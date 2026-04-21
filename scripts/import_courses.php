<?php
/**
 * Moodle course importer - creates courses with full content from JSON.
 *
 * Usage (run inside Moodle container):
 *   php /tmp/import_courses.php /tmp/courses.json
 *
 * This script uses Moodle's internal APIs to create:
 * - Course categories (if needed)
 * - Courses with topic format
 * - Page activities (mod_page)
 * - Forum activities (mod_forum)
 * - Assignment activities (mod_assign)
 */

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
$CFG->debug = E_ALL;
$CFG->debugdisplay = 1;
ini_set('display_errors', '1');
ini_set('error_reporting', E_ALL);

require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/modinfolib.php');
require_once($CFG->dirroot . '/course/modlib.php');

// Check args
if ($argc < 2) {
    cli_error("Usage: php import_courses.php <courses.json>");
}

$jsonpath = $argv[1];
if (!file_exists($jsonpath)) {
    cli_error("File not found: $jsonpath");
}

$courses = json_decode(file_get_contents($jsonpath), true);
if (!$courses) {
    cli_error("Invalid JSON in $jsonpath");
}

echo "=== Moodle Course Importer ===\n\n";

foreach ($courses as $idx => $coursedata) {
    $num = $idx + 1;
    echo "[$num/" . count($courses) . "] {$coursedata['FullName']} ({$coursedata['ShortName']})...\n";

    // Check if course already exists
    $existing = $DB->get_record('course', ['shortname' => $coursedata['ShortName']]);
    if ($existing) {
        // Check if it already has activities (content)
        $modcount = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {course_modules} WHERE course = ?",
            [$existing->id]
        );
        if ($modcount > 5) {
            echo "  SKIP: course {$coursedata['ShortName']} already has $modcount activities.\n\n";
            continue;
        }
        // Course exists but is empty - populate it
        echo "  Course exists (ID={$existing->id}) but is empty - populating...\n";
        $createdcourse = $existing;
    } else {
        // Resolve or create category
        $categoryid = resolve_category($coursedata['Category']);

        // Create the course
        $course = new stdClass();
        $course->fullname    = $coursedata['FullName'];
        $course->shortname   = $coursedata['ShortName'];
        $course->summary     = $coursedata['Summary'];
        $course->summaryformat = FORMAT_HTML;
        $course->format      = 'topics';
        $course->category    = $categoryid;
        $course->visible     = 1;
        $course->lang        = 'it';
        $course->enablecompletion = 1;
        $course->numsections = count($coursedata['Sections']) - 1;

        $createdcourse = create_course($course);
        echo "  Created course ID={$createdcourse->id}\n";
    }

    // Now populate sections with activities
    $sectionnum = 0;
    foreach ($coursedata['Sections'] as $sectiondata) {
        echo "  Section $sectionnum: {$sectiondata['Title']}\n";

        // Update section name and summary
        $section = $DB->get_record('course_sections', [
            'course' => $createdcourse->id,
            'section' => $sectionnum
        ]);

        if ($section) {
            $section->name    = $sectiondata['Title'];
            $section->summary = $sectiondata['Summary'];
            $section->summaryformat = FORMAT_HTML;
            $DB->update_record('course_sections', $section);
        } else {
            // Create section if it doesn't exist
            $section = course_create_section($createdcourse->id, $sectionnum);
            $section->name    = $sectiondata['Title'];
            $section->summary = $sectiondata['Summary'];
            $section->summaryformat = FORMAT_HTML;
            $DB->update_record('course_sections', $section);
        }

        // Create activities in this section
        foreach ($sectiondata['Activities'] as $actdata) {
            try {
                switch ($actdata['Type']) {
                    case 'page':
                        create_page_activity($createdcourse, $sectionnum, $actdata);
                        break;
                    case 'forum':
                        create_forum_activity($createdcourse, $sectionnum, $actdata);
                        break;
                    case 'assign':
                        create_assign_activity($createdcourse, $sectionnum, $actdata);
                        break;
                    default:
                        echo "    WARN: Unknown type {$actdata['Type']}\n";
                }
            } catch (Exception $e) {
                echo "    ERROR creating {$actdata['Type']} '{$actdata['Name']}': {$e->getMessage()}\n";
                // Reset transaction if needed
                if ($DB->is_transaction_started()) {
                    $DB->force_transaction_rollback();
                }
            }
        }

        $sectionnum++;
    }

    echo "  Done.\n\n";
    // Rebuild course cache
    rebuild_course_cache($createdcourse->id, true);
}

echo "=== All courses imported! ===\n";

// ─── Helper Functions ────────────────────────────────────────────────────────

function resolve_category($name) {
    global $DB;

    $cat = $DB->get_record('course_categories', ['name' => $name]);
    if ($cat) {
        return $cat->id;
    }

    // Create category
    $data = new stdClass();
    $data->name = $name;
    $data->parent = 0;
    $data->visible = 1;
    $data->description = '';

    // Use core API
    if (class_exists('core_course_category')) {
        $created = core_course_category::create($data);
        echo "  Created category: $name (ID={$created->id})\n";
        return $created->id;
    }

    // Fallback for older Moodle
    if (class_exists('coursecat')) {
        $created = coursecat::create($data);
        echo "  Created category: $name (ID={$created->id})\n";
        return $created->id;
    }

    // Last resort: insert directly
    $data->sortorder = 0;
    $data->timemodified = time();
    $data->depth = 1;
    $id = $DB->insert_record('course_categories', $data);
    echo "  Created category: $name (ID=$id)\n";
    return $id;
}

function create_page_activity($course, $sectionnum, $actdata) {
    global $DB, $CFG;

    $moduleinfo = new stdClass();
    $moduleinfo->modulename   = 'page';
    $moduleinfo->course       = $course->id;
    $moduleinfo->section      = $sectionnum;
    $moduleinfo->visible      = 1;
    $moduleinfo->name         = $actdata['Name'];
    $moduleinfo->intro        = '';
    $moduleinfo->introformat  = FORMAT_HTML;
    $moduleinfo->content      = isset($actdata['Content']) ? $actdata['Content'] : '';
    $moduleinfo->contentformat = FORMAT_HTML;
    $moduleinfo->display      = 5; // RESOURCELIB_DISPLAY_OPEN
    $moduleinfo->printintro   = 0;
    $moduleinfo->printheading = 1;
    $moduleinfo->printlastmodified = 0;
    $moduleinfo->completion   = 1;
    $moduleinfo->completionview = 1;

    // Add course module
    $cm = add_module_to_course($course, $sectionnum, 'page', $moduleinfo);
    echo "    + Page: {$actdata['Name']} (cmid=$cm)\n";
}

function create_forum_activity($course, $sectionnum, $actdata) {
    global $DB, $CFG;

    $moduleinfo = new stdClass();
    $moduleinfo->modulename   = 'forum';
    $moduleinfo->course       = $course->id;
    $moduleinfo->section      = $sectionnum;
    $moduleinfo->visible      = 1;
    $moduleinfo->name         = $actdata['Name'];
    $moduleinfo->intro        = isset($actdata['Intro']) ? $actdata['Intro'] : '';
    $moduleinfo->introformat  = FORMAT_HTML;
    $moduleinfo->type         = 'general';
    $moduleinfo->forcesubscribe = 0;
    $moduleinfo->trackingtype = 1;
    $moduleinfo->maxbytes     = 512000;
    $moduleinfo->maxattachments = 9;
    $moduleinfo->grade_forum  = 0;
    $moduleinfo->completion   = 1;

    $cm = add_module_to_course($course, $sectionnum, 'forum', $moduleinfo);
    echo "    + Forum: {$actdata['Name']} (cmid=$cm)\n";
}

function create_assign_activity($course, $sectionnum, $actdata) {
    global $DB, $CFG;

    $moduleinfo = new stdClass();
    $moduleinfo->modulename   = 'assign';
    $moduleinfo->course       = $course->id;
    $moduleinfo->section      = $sectionnum;
    $moduleinfo->visible      = 1;
    $moduleinfo->name         = $actdata['Name'];
    $moduleinfo->intro        = isset($actdata['Intro']) ? $actdata['Intro'] : '';
    $moduleinfo->introformat  = FORMAT_HTML;
    $moduleinfo->alwaysshowdescription = 1;
    $moduleinfo->submissiondrafts = 0;
    $moduleinfo->requiresubmissionstatement = 0;
    $moduleinfo->sendnotifications = 0;
    $moduleinfo->sendlatenotifications = 0;
    $moduleinfo->sendstudentnotifications = 1;
    $moduleinfo->duedate      = 0;
    $moduleinfo->cutoffdate   = 0;
    $moduleinfo->gradingduedate = 0;
    $moduleinfo->allowsubmissionsfromdate = 0;
    $moduleinfo->grade        = 100;
    $moduleinfo->completionsubmit = 1;
    $moduleinfo->completion   = 1;
    $moduleinfo->teamsubmission = 0;
    $moduleinfo->requireallteammemberssubmit = 0;
    $moduleinfo->teamsubmissiongroupingid = 0;
    $moduleinfo->blindmarking = 0;
    $moduleinfo->hidegrader   = 0;
    $moduleinfo->markingworkflow = 0;
    $moduleinfo->markingallocation = 0;
    $moduleinfo->preventsubmissionnotingroup = 0;
    $moduleinfo->attemptreopenmethod = 'none';
    $moduleinfo->maxattempts  = -1;

    // Submission plugins
    $moduleinfo->assignsubmission_onlinetext_enabled = 1;
    $moduleinfo->assignsubmission_file_enabled = 1;
    $moduleinfo->assignsubmission_file_maxfiles = 3;
    $moduleinfo->assignsubmission_file_maxsizebytes = 5242880;
    $moduleinfo->assignfeedback_comments_enabled = 1;

    $cm = add_module_to_course($course, $sectionnum, 'assign', $moduleinfo);
    echo "    + Assign: {$actdata['Name']} (cmid=$cm)\n";
}

function add_module_to_course($course, $sectionnum, $modulename, $moduleinfo) {
    global $DB, $CFG;

    require_once($CFG->dirroot . '/course/modlib.php');

    // Get module ID
    $module = $DB->get_record('modules', ['name' => $modulename], '*', MUST_EXIST);

    $moduleinfo->module    = $module->id;
    $moduleinfo->modulename = $modulename;
    $moduleinfo->course    = $course->id;
    $moduleinfo->section   = $sectionnum;
    $moduleinfo->visible   = 1;
    $moduleinfo->visibleoncoursepage = 1;
    $moduleinfo->cmidnumber = '';
    $moduleinfo->groupmode = 0;
    $moduleinfo->groupingid = 0;

    // Use Moodle's add_moduleinfo which handles everything
    $result = add_moduleinfo($moduleinfo, $course);

    return $result->coursemodule;
}
