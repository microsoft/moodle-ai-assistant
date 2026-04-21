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
 * AI Chat - Admin conversation log viewer.
 *
 * Displays anonymized conversation logs for monitoring.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$page     = optional_param('page', 0, PARAM_INT);
$perpage  = 20;

$course  = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/aichat:viewlogs', $context);

$PAGE->set_url(new moodle_url('/local/aichat/logs.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('logs', 'local_aichat'));
$PAGE->set_heading(format_string($course->fullname));

// Date filter.
$fromstr = optional_param('from', '', PARAM_TEXT);
$tostr   = optional_param('to', '', PARAM_TEXT);
$datefrom = !empty($fromstr) ? strtotime($fromstr) : time() - (7 * DAYSECS);
$dateto   = !empty($tostr)   ? strtotime($tostr) + DAYSECS - 1 : time();
if ($datefrom === false) {
    $datefrom = time() - (7 * DAYSECS);
}
if ($dateto === false) {
    $dateto = time();
}

// Get threads for this course, ordered by most recent activity.
$totalthreads = $DB->count_records_select('local_aichat_threads',
    'courseid = :courseid AND timemodified >= :from AND timemodified <= :to',
    ['courseid' => $courseid, 'from' => $datefrom, 'to' => $dateto]);

$threads = $DB->get_records_select('local_aichat_threads',
    'courseid = :courseid AND timemodified >= :from AND timemodified <= :to',
    ['courseid' => $courseid, 'from' => $datefrom, 'to' => $dateto],
    'timemodified DESC', '*', $page * $perpage, $perpage);

// Build anonymized user map.
$usermap = [];
$counter = 1;

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('logs', 'local_aichat'));

// Date filter form.
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => new moodle_url('/local/aichat/logs.php'),
    'class'  => 'mb-3',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
echo html_writer::start_div('row g-2 align-items-end');
echo html_writer::div(
    html_writer::tag('label', get_string('from'), ['class' => 'form-label', 'for' => 'from']) .
    html_writer::empty_tag('input', [
        'type' => 'date', 'name' => 'from', 'id' => 'from', 'class' => 'form-control',
        'value' => date('Y-m-d', $datefrom),
    ]),
    'col-auto'
);
echo html_writer::div(
    html_writer::tag('label', get_string('to'), ['class' => 'form-label', 'for' => 'to']) .
    html_writer::empty_tag('input', [
        'type' => 'date', 'name' => 'to', 'id' => 'to', 'class' => 'form-control',
        'value' => date('Y-m-d', $dateto),
    ]),
    'col-auto'
);
echo html_writer::div(
    html_writer::empty_tag('input', [
        'type' => 'submit', 'value' => get_string('filter', 'local_aichat'),
        'class' => 'btn btn-primary',
    ]),
    'col-auto'
);
echo html_writer::end_div();
echo html_writer::end_tag('form');

if (empty($threads)) {
    echo $OUTPUT->notification(get_string('nologs', 'local_aichat'), \core\output\notification::NOTIFY_INFO);
} else {
    foreach ($threads as $thread) {
        // Anonymize user.
        if (!isset($usermap[$thread->userid])) {
            $usermap[$thread->userid] = get_string('student', 'local_aichat') . ' ' . $counter++;
        }
        $anonname = $usermap[$thread->userid];

        echo html_writer::start_div('card mb-3');
        echo html_writer::start_div('card-header');
        echo html_writer::tag('strong', $anonname) . ' &mdash; ' .
             userdate($thread->timemodified, get_string('strftimedatetimeshort', 'langconfig'));
        echo html_writer::end_div();
        echo html_writer::start_div('card-body');

        $messages = $DB->get_records('local_aichat_messages',
            ['threadid' => $thread->id], 'timecreated ASC');

        foreach ($messages as $msg) {
            $rolename = ($msg->role === 'user') ? $anonname : get_string('assistant', 'local_aichat');
            $cssclass = ($msg->role === 'user') ? 'text-primary' : 'text-success';

            // Check for flagged messages (prompt injection attempts detected).
            $flagged = false;
            if ($msg->role === 'user') {
                $original = $msg->message;
                $stripped = \local_aichat\security\input_sanitizer::strip_prompt_injection(
                    $original, 0, 0
                );
                if ($stripped !== $original) {
                    $flagged = true;
                }
            }

            $style = $flagged ? 'background-color: #ffe0e0; border-left: 3px solid #dc3545; padding-left: 8px;' : '';

            echo html_writer::start_div('mb-2', ['style' => $style]);
            echo html_writer::tag('small',
                html_writer::tag('strong', $rolename, ['class' => $cssclass]) . ' &mdash; ' .
                userdate($msg->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                ['class' => 'text-muted']
            );
            if ($msg->role === 'user') {
                echo html_writer::div(nl2br(s($msg->message)), 'ms-2');
            } else {
                echo html_writer::div(format_text($msg->message, FORMAT_HTML), 'ms-2');
            }
            if ($flagged) {
                echo html_writer::tag('small',
                    get_string('flaggedinjection', 'local_aichat'),
                    ['class' => 'text-danger']
                );
            }
            echo html_writer::end_div();
        }

        echo html_writer::end_div(); // card-body.
        echo html_writer::end_div(); // card.
    }

    // Pagination.
    echo $OUTPUT->paging_bar($totalthreads, $page, $perpage,
        new moodle_url('/local/aichat/logs.php', [
            'courseid' => $courseid, 'from' => date('Y-m-d', $datefrom), 'to' => date('Y-m-d', $dateto),
        ]));
}

echo $OUTPUT->footer();
