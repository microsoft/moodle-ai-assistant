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
 * AI Chat - Chat export endpoint.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();

$courseid = required_param('courseid', PARAM_INT);
$format   = optional_param('format', 'txt', PARAM_ALPHA);

$context = context_course::instance($courseid);
require_capability('local/aichat:use', $context);

// Check if export is enabled for this course.
$coursesettings = $DB->get_record('local_aichat_course_settings', ['courseid' => $courseid]);
if ($coursesettings && empty($coursesettings->enable_export)) {
    throw new moodle_exception('exportdisabled', 'local_aichat');
}

// Get thread and messages.
$thread = $DB->get_record('local_aichat_threads', [
    'userid'   => $USER->id,
    'courseid' => $courseid,
]);

if (!$thread) {
    throw new moodle_exception('nothread', 'local_aichat');
}

$messages = $DB->get_records('local_aichat_messages', ['threadid' => $thread->id], 'timecreated ASC');

if (empty($messages)) {
    throw new moodle_exception('nomessages', 'local_aichat');
}

$course = get_course($courseid);
$date   = userdate(time(), '%Y%m%d-%H%M');
$filename = clean_filename('chat-export-' . $course->shortname . '-' . $date);

if ($format === 'txt') {
    // Plain text export.
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.txt"');

    echo get_string('exportheader', 'local_aichat', (object) [
        'coursename' => format_string($course->fullname),
        'date' => userdate(time()),
    ]) . "\n";
    echo str_repeat('=', 60) . "\n\n";

    foreach ($messages as $msg) {
        $role = ($msg->role === 'user')
            ? fullname($USER)
            : get_string('assistant', 'local_aichat');
        $timestamp = userdate($msg->timecreated, get_string('strftimedatetimeshort', 'langconfig'));
        echo "[{$timestamp}] {$role}:\n";
        echo html_to_text($msg->message) . "\n\n";
    }
} else if ($format === 'pdf') {
    // PDF export using TCPDF (bundled with Moodle).
    require_once($CFG->libdir . '/pdflib.php');

    $pdf = new pdf();
    $pdf->SetTitle(get_string('exporttitle', 'local_aichat', format_string($course->fullname)));
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();

    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, get_string('exporttitle', 'local_aichat', format_string($course->fullname)), 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, userdate(time()), 0, 1);
    $pdf->Ln(6);

    foreach ($messages as $msg) {
        $role = ($msg->role === 'user')
            ? fullname($USER)
            : get_string('assistant', 'local_aichat');
        $timestamp = userdate($msg->timecreated, get_string('strftimedatetimeshort', 'langconfig'));

        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 5, "[{$timestamp}] {$role}:", 0, 1);
        $pdf->SetFont('helvetica', '', 10);
        $safemsg = \local_aichat\security\output_sanitizer::sanitize_ai_response($msg->message);
        $pdf->writeHTML(format_text($safemsg, FORMAT_HTML), true, false, true, false, '');
        $pdf->Ln(4);
    }

    $pdf->Output($filename . '.pdf', 'D');
} else {
    throw new moodle_exception('invalidformat', 'local_aichat');
}

// Fire event.
$event = \local_aichat\event\chat_exported::create([
    'context' => $context,
    'userid'  => $USER->id,
    'other'   => [
        'format' => $format,
    ],
]);
$event->trigger();
