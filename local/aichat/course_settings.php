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
 * AI Chat - Per-course settings page.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/course_settings_form.php');

$courseid = required_param('courseid', PARAM_INT);

$course  = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/aichat:manage', $context);

$PAGE->set_url(new moodle_url('/local/aichat/course_settings.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('coursesettings', 'local_aichat'));
$PAGE->set_heading(format_string($course->fullname));

// Load existing settings.
$existing = $DB->get_record('local_aichat_course_settings', ['courseid' => $courseid]);
$formdata = new stdClass();
$formdata->courseid = $courseid;
if ($existing) {
    $formdata->enable_export = $existing->enable_export;
    $formdata->enable_upload = $existing->enable_upload;
}

$form = new local_aichat_course_settings_form(null, null, 'post', '', ['class' => 'aichat-course-settings']);
$form->set_data($formdata);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
} else if ($data = $form->get_data()) {
    $now = time();

    if ($existing) {
        $existing->enable_export = $data->enable_export;
        $existing->enable_upload = $data->enable_upload;
        $existing->timemodified  = $now;
        $DB->update_record('local_aichat_course_settings', $existing);
    } else {
        $record = new stdClass();
        $record->courseid      = $courseid;
        $record->enable_export = $data->enable_export;
        $record->enable_upload = $data->enable_upload;
        $record->timecreated   = $now;
        $record->timemodified  = $now;
        $DB->insert_record('local_aichat_course_settings', $record);
    }

    redirect(
        new moodle_url('/local/aichat/course_settings.php', ['courseid' => $courseid]),
        get_string('settingssaved', 'local_aichat'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coursesettings', 'local_aichat'));
$form->display();
echo $OUTPUT->footer();
