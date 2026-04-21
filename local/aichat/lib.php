<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * AI Chat - Library functions
 *
 * Provides the before_footer callback that injects the chatbot on course pages,
 * and navigation callbacks for course secondary navigation links.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Inject the floating chatbot widget on course and activity pages.
 *
 * Called automatically by Moodle's plugin hook system just before </body>.
 */
function local_aichat_before_footer() {
    global $PAGE, $USER, $CFG, $DB;

    // Only proceed if plugin is enabled.
    if (!get_config('local_aichat', 'enabled')) {
        return;
    }

    // Only proceed if Azure OpenAI is configured.
    $endpoint = get_config('local_aichat', 'endpoint');
    $apikey   = get_config('local_aichat', 'apikey');
    if (empty($endpoint) || empty($apikey)) {
        return;
    }

    // Determine context — must be a course or module context.
    $context = $PAGE->context;
    $courseid  = 0;
    $sectionid = 0;
    $cmid      = 0;

    if ($context->contextlevel === CONTEXT_MODULE) {
        // Activity page.
        $cmid     = $context->instanceid;
        $courseid = $PAGE->course->id;
    } else if ($context->contextlevel === CONTEXT_COURSE) {
        // Course page.
        $courseid = $context->instanceid;
    } else {
        // Not a course/activity page — do not inject.
        return;
    }

    // Skip the site-level course (front page).
    if ($courseid <= 1) {
        return;
    }

    // Check capability.
    $coursecontext = \context_course::instance($courseid);
    if (!has_capability('local/aichat:use', $coursecontext)) {
        return;
    }

    // Gather data for the JS module.
    $course = get_course($courseid);

    // Detect current section DB id if available.
    // The 'section' URL param is the section *number* (0-based), not the DB id — resolve it.
    $sectionid = 0;
    if ($context->contextlevel === CONTEXT_COURSE) {
        $sectionnumber = optional_param('section', -1, PARAM_INT);
        if ($sectionnumber >= 0) {
            $modinfo = get_fast_modinfo($course);
            $sectioninfo = $modinfo->get_section_info($sectionnumber, IGNORE_MISSING);
            if ($sectioninfo) {
                $sectionid = (int) $sectioninfo->id;
            }
        }
    }

    // Theming settings.
    $primarycolor   = get_config('local_aichat', 'primarycolor') ?: '#4f46e5';
    $secondarycolor = get_config('local_aichat', 'secondarycolor') ?: '#3730a3';
    $headertitle    = get_config('local_aichat', 'headertitle') ?: get_string('courseassistant', 'local_aichat');

    // Inject CSS custom properties so styles.css picks up admin-configured colours.
    $safeprimary   = preg_replace('/[^#a-fA-F0-9]/', '', $primarycolor);
    $safesecondary = preg_replace('/[^#a-fA-F0-9]/', '', $secondarycolor);
    echo '<style>:root{--aichat-primary:' . $safeprimary . ';--aichat-secondary:' . $safesecondary . ';}</style>';

    // Custom avatar URL (if uploaded via admin settings).
    $avatarurl = '';
    $fs = get_file_storage();
    $syscontext = \context_system::instance();
    $files = $fs->get_area_files($syscontext->id, 'local_aichat', 'botavatar', 0, 'sortorder', false);
    if (!empty($files)) {
        $file = reset($files);
        $avatarurl = \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        )->out(false);
    }

    // Load per-course settings (export/upload toggles) from DB.
    $dbsettings = $DB->get_record('local_aichat_course_settings', ['courseid' => $courseid]);
    $coursesettings = (object)[
        'enable_export' => $dbsettings ? (bool) $dbsettings->enable_export : false,
        'enable_upload' => $dbsettings ? (bool) $dbsettings->enable_upload : false,
    ];

    // User language.
    $lang = current_language();

    // Resolve the greeting string server-side (requires placeholder substitution).
    $greeting = get_string('greeting', 'local_aichat', (object)[
        'firstname'  => $USER->firstname,
        'coursename' => format_string($course->fullname),
    ]);

    // Privacy notice config.
    $showprivacynotice = (bool) get_config('local_aichat', 'showprivacynotice');
    $privacynotice     = get_config('local_aichat', 'privacynotice');

    // Usage limits for client-side display.
    $dailylimit   = (int) get_config('local_aichat', 'dailylimit');
    $maxmsglength = (int) get_config('local_aichat', 'maxmsglength');

    // Collect all language strings needed by the JS module.
    $stringkeys = [
        'sendmessage', 'newchat', 'close', 'exportchat', 'uploaddocument',
        'tellmeaboutcourse', 'summarizesection', 'createquiz',
        'dailylimitreached', 'burstwait', 'assistantunavailable',
        'privacynoticetitle', 'iagree', 'remainingmessages', 'courseassistant',
        'typemessage', 'thinking', 'thumbsup', 'thumbsdown', 'removeupload',
        'voiceinput', 'voicelistening', 'voiceunsupported',
    ];
    $strings = new \stdClass();
    foreach ($stringkeys as $key) {
        $strings->$key = get_string($key, 'local_aichat');
    }
    $strings->greeting = $greeting;

    // Theme settings object for JS.
    $themesettings = (object)[
        'primaryColor'   => $primarycolor,
        'secondaryColor' => $secondarycolor,
        'headerTitle'    => $headertitle,
        'avatarUrl'      => $avatarurl,
    ];

    // Initialise the AMD chatbot module.
    $PAGE->requires->js_call_amd('local_aichat/chatbot', 'init', [
        $courseid,
        $sectionid,
        $cmid,
        $USER->firstname,
        format_string($course->fullname),
        $strings,
        $coursesettings,
        $themesettings,
        $lang,
        $showprivacynotice,
        $privacynotice,
        $dailylimit,
        $maxmsglength,
    ]);
}

/**
 * Extend the course secondary navigation with AI Chat links.
 *
 * @param navigation_node $parentnode The course navigation node.
 * @param stdClass $course The course object.
 * @param context_course $context The course context.
 */
function local_aichat_extend_navigation_course(\navigation_node $parentnode, \stdClass $course, \context_course $context) {
    if (!get_config('local_aichat', 'enabled')) {
        return;
    }

    // Dashboard link (teachers).
    if (has_capability('local/aichat:viewdashboard', $context)) {
        $parentnode->add(
            get_string('dashboard', 'local_aichat'),
            new \moodle_url('/local/aichat/dashboard.php', ['courseid' => $course->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'aichat_dashboard',
            new \pix_icon('i/report', '')
        );
    }

    // Logs link (managers).
    if (has_capability('local/aichat:viewlogs', $context)) {
        $parentnode->add(
            get_string('logs', 'local_aichat'),
            new \moodle_url('/local/aichat/logs.php', ['courseid' => $course->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'aichat_logs',
            new \pix_icon('i/log', '')
        );
    }

    // Course settings link (managers).
    if (has_capability('local/aichat:manage', $context)) {
        $parentnode->add(
            get_string('coursesettings', 'local_aichat'),
            new \moodle_url('/local/aichat/course_settings.php', ['courseid' => $course->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'aichat_settings',
            new \pix_icon('i/settings', '')
        );
    }
}

/**
 * Serve files for the local_aichat plugin (bot avatar).
 *
 * @param stdClass $course The course object.
 * @param stdClass $cm The course module object (unused).
 * @param context $context The context.
 * @param string $filearea The file area.
 * @param array $args Extra arguments (itemid, filepath, filename).
 * @param bool $forcedownload Whether to force download.
 * @param array $options Additional options.
 * @return bool False if the file is not found.
 */
function local_aichat_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    // Only serve files from the botavatar area in system context.
    if ($context->contextlevel !== CONTEXT_SYSTEM || $filearea !== 'botavatar') {
        return false;
    }

    $itemid = array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_aichat', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}
