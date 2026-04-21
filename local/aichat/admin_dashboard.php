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
 * AI Chat - Site-wide admin token usage dashboard.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_aichat_admindashboard');

// Date range filter.
$datefrom = optional_param('from', time() - (30 * DAYSECS), PARAM_INT);
$dateto   = optional_param('to', time(), PARAM_INT);
$range    = optional_param('range', 30, PARAM_INT);

if ($range > 0) {
    $datefrom = time() - ($range * DAYSECS);
    $dateto = time();
}

// Summary cards.
$alltimetokens = $DB->get_field_sql(
    "SELECT COALESCE(SUM(total_tokens), 0) FROM {local_aichat_token_usage}"
);

$monthtokens = $DB->get_field_sql(
    "SELECT COALESCE(SUM(total_tokens), 0)
       FROM {local_aichat_token_usage}
      WHERE timecreated >= :start",
    ['start' => time() - (30 * DAYSECS)]
);

$totalconversations = $DB->count_records('local_aichat_threads');
$totalmessages = $DB->count_records('local_aichat_messages');

// Embedding stats (site-wide).
$embeddingstats = $DB->get_record_sql(
    "SELECT COUNT(*) AS chunk_count,
            COALESCE(SUM(token_count), 0) AS total_tokens
       FROM {local_aichat_embeddings}"
);

// Embedding stats per course (top 20 by token count).
$embeddingpercourse = $DB->get_records_sql(
    "SELECT e.courseid, c.shortname, c.fullname,
            COUNT(*) AS chunk_count,
            COALESCE(SUM(e.token_count), 0) AS total_tokens,
            MAX(e.timemodified) AS last_indexed
       FROM {local_aichat_embeddings} e
       JOIN {course} c ON c.id = e.courseid
      GROUP BY e.courseid, c.shortname, c.fullname
      ORDER BY total_tokens DESC",
    [], 0, 20
);

// Daily token usage for chart (cross-DB compatible: FLOOR is ANSI SQL).
$sql = "SELECT FLOOR(tu.timecreated / 86400) AS daynum,
               COALESCE(SUM(tu.prompt_tokens), 0) AS prompt_tokens,
               COALESCE(SUM(tu.completion_tokens), 0) AS completion_tokens
          FROM {local_aichat_token_usage} tu
         WHERE tu.timecreated >= :start AND tu.timecreated <= :endtime
         GROUP BY FLOOR(tu.timecreated / 86400)
         ORDER BY daynum ASC";
$dailytokens = $DB->get_records_sql($sql, ['start' => $datefrom, 'endtime' => $dateto]);

$daylabels   = [];
$promptdata  = [];
$compldata   = [];
foreach ($dailytokens as $row) {
    $daylabels[]  = date('Y-m-d', (int) $row->daynum * 86400);
    $promptdata[] = (int) $row->prompt_tokens;
    $compldata[]  = (int) $row->completion_tokens;
}

// Token usage per deployment.
$sql = "SELECT tu.deployment,
               COALESCE(SUM(tu.prompt_tokens), 0) AS prompt_tokens,
               COALESCE(SUM(tu.completion_tokens), 0) AS completion_tokens,
               COALESCE(SUM(tu.total_tokens), 0) AS total_tokens,
               COUNT(tu.id) AS request_count
          FROM {local_aichat_token_usage} tu
         WHERE tu.timecreated >= :start AND tu.timecreated <= :endtime
         GROUP BY tu.deployment
         ORDER BY total_tokens DESC";
$deploymentstats = $DB->get_records_sql($sql, ['start' => $datefrom, 'endtime' => $dateto]);

$deploylabels = [];
$deploytokens = [];
foreach ($deploymentstats as $ds) {
    $label = !empty($ds->deployment) ? $ds->deployment : get_string('unknowndeployment', 'local_aichat');
    $deploylabels[] = $label;
    $deploytokens[] = (int) $ds->total_tokens;
}

// Token usage per course (top 20).
$sql = "SELECT t.courseid, c.shortname, c.fullname,
               COALESCE(SUM(tu.prompt_tokens), 0) AS prompt_tokens,
               COALESCE(SUM(tu.completion_tokens), 0) AS completion_tokens,
               COALESCE(SUM(tu.total_tokens), 0) AS total_tokens,
               COUNT(DISTINCT m.id) AS message_count
          FROM {local_aichat_token_usage} tu
          JOIN {local_aichat_messages} m ON m.id = tu.messageid
          JOIN {local_aichat_threads} t ON t.id = m.threadid
          JOIN {course} c ON c.id = t.courseid
         WHERE tu.timecreated >= :start AND tu.timecreated <= :endtime
         GROUP BY t.courseid, c.shortname, c.fullname
         ORDER BY total_tokens DESC";
$coursestats = $DB->get_records_sql($sql, ['start' => $datefrom, 'endtime' => $dateto], 0, 20);

$courselabels = [];
$coursetokens = [];
foreach ($coursestats as $cs) {
    $courselabels[] = $cs->shortname;
    $coursetokens[] = (int) $cs->total_tokens;
}

// Top 20 users by usage (site-wide, within date range).
$sql = "SELECT u.id AS userid, u.firstname, u.lastname, u.email,
               msg.message_count,
               msg.course_count,
               COALESCE(tok.total_tokens, 0) AS total_tokens,
               COALESCE(tok.prompt_tokens, 0) AS prompt_tokens,
               COALESCE(tok.completion_tokens, 0) AS completion_tokens,
               msg.last_active
          FROM {user} u
          JOIN (SELECT t.userid,
                       COUNT(m.id) AS message_count,
                       COUNT(DISTINCT t.courseid) AS course_count,
                       MAX(m.timecreated) AS last_active
                  FROM {local_aichat_threads} t
                  JOIN {local_aichat_messages} m ON m.threadid = t.id AND m.role = 'user'
                 WHERE m.timecreated >= :mstart AND m.timecreated <= :mend
                 GROUP BY t.userid) msg ON msg.userid = u.id
     LEFT JOIN (SELECT t2.userid,
                       SUM(tu.total_tokens) AS total_tokens,
                       SUM(tu.prompt_tokens) AS prompt_tokens,
                       SUM(tu.completion_tokens) AS completion_tokens
                  FROM {local_aichat_threads} t2
                  JOIN {local_aichat_messages} am ON am.threadid = t2.id AND am.role = 'assistant'
                  JOIN {local_aichat_token_usage} tu ON tu.messageid = am.id
                 WHERE tu.timecreated >= :tustart AND tu.timecreated <= :tuend
                 GROUP BY t2.userid) tok ON tok.userid = u.id
         ORDER BY total_tokens DESC";
$topusers_admin = $DB->get_records_sql($sql, [
    'tustart' => $datefrom, 'tuend' => $dateto,
    'mstart' => $datefrom, 'mend' => $dateto,
], 0, 20);

// Handle user usage CSV export (site-wide, full — no LIMIT).
if (optional_param('exportusers', 0, PARAM_BOOL) && confirm_sesskey()) {
    $allusers_admin = $DB->get_records_sql($sql, [
        'tustart' => $datefrom, 'tuend' => $dateto,
        'mstart' => $datefrom, 'mend' => $dateto,
    ], 0, 1000);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="aichat-user-usage-sitewide-' . date('Ymd') . '.csv"');
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['userid', 'firstname', 'lastname', 'email', 'courses',
                   'messages', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'last_active']);
    foreach ($allusers_admin as $u) {
        $firstname = format_string($u->firstname);
        $lastname = format_string($u->lastname);
        if (preg_match('/^[=+\-@\t\r]/', $firstname)) {
            $firstname = "'" . $firstname;
        }
        if (preg_match('/^[=+\-@\t\r]/', $lastname)) {
            $lastname = "'" . $lastname;
        }
        fputcsv($fp, [
            $u->userid, $firstname, $lastname, $u->email,
            $u->course_count, $u->message_count,
            $u->prompt_tokens, $u->completion_tokens,
            $u->total_tokens, $u->last_active ? userdate($u->last_active) : '',
        ]);
    }
    fclose($fp);
    exit;
}

// Handle CSV export.
if (optional_param('export', 0, PARAM_BOOL) && confirm_sesskey()) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="aichat-usage-report-' . date('Ymd') . '.csv"');
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['courseid', 'coursename', 'prompt_tokens', 'completion_tokens',
                   'total_tokens', 'message_count']);
    foreach ($coursestats as $cs) {
        // Prevent CSV formula injection: prefix dangerous first characters.
        $coursename = format_string($cs->fullname);
        if (preg_match('/^[=+\-@\t\r]/', $coursename)) {
            $coursename = "'" . $coursename;
        }
        fputcsv($fp, [
            $cs->courseid, $coursename,
            $cs->prompt_tokens, $cs->completion_tokens,
            $cs->total_tokens, $cs->message_count,
        ]);
    }
    fclose($fp);
    exit;
}

echo $OUTPUT->header();

echo html_writer::start_div('aichat-dashboard');

// Dashboard header.
echo html_writer::start_div('aichat-dashboard-header');
echo html_writer::start_div('aichat-dashboard-title');
echo '<div class="aichat-dashboard-icon">' .
    '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' .
    '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>';
echo html_writer::tag('h2', get_string('admindashboard', 'local_aichat'));
echo html_writer::end_div();

// Range selector.
echo '<div class="aichat-dashboard-actions">';
echo '<div class="aichat-range-selector">';
$ranges = [30, 60, 90];
foreach ($ranges as $r) {
    $active = ($r == $range) ? ' aichat-range-btn--active' : '';
    $url = new moodle_url('/local/aichat/admin_dashboard.php', ['range' => $r]);
    echo '<a href="' . $url->out(true) . '" class="aichat-range-btn' . $active . '">' .
        $r . ' ' . get_string('days') . '</a>';
}
echo '</div></div>';

echo html_writer::end_div(); // header

// Stats grid.
echo html_writer::start_div('aichat-stats-grid');

// All-time tokens.
echo '<div class="aichat-stat-card aichat-stat-card--tokens">' .
    '<div class="aichat-stat-header">' .
        '<span class="aichat-stat-label">' . get_string('alltimetokens', 'local_aichat') . '</span>' .
        '<div class="aichat-stat-icon aichat-stat-icon--tokens">' .
            '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">' .
            '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>' .
        '</div>' .
    '</div>' .
    '<div class="aichat-stat-value">' . number_format($alltimetokens) . '</div>' .
    '<div class="aichat-stat-sub">' . get_string('dashcumulativeusage', 'local_aichat') . '</div>' .
    '</div>';

// Month tokens.
echo '<div class="aichat-stat-card aichat-stat-card--month">' .
    '<div class="aichat-stat-header">' .
        '<span class="aichat-stat-label">' . get_string('monthtokens', 'local_aichat') . '</span>' .
        '<div class="aichat-stat-icon aichat-stat-icon--month">' .
            '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">' .
            '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>' .
            '<line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>' .
            '<line x1="3" y1="10" x2="21" y2="10"/></svg>' .
        '</div>' .
    '</div>' .
    '<div class="aichat-stat-value">' . number_format($monthtokens) . '</div>' .
    '<div class="aichat-stat-sub">' . get_string('dashlast30days', 'local_aichat') . '</div>' .
    '</div>';

// Conversations.
echo '<div class="aichat-stat-card aichat-stat-card--conversations">' .
    '<div class="aichat-stat-header">' .
        '<span class="aichat-stat-label">' . get_string('totalconversations', 'local_aichat') . '</span>' .
        '<div class="aichat-stat-icon aichat-stat-icon--conversations">' .
            '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">' .
            '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>' .
        '</div>' .
    '</div>' .
    '<div class="aichat-stat-value">' . number_format($totalconversations) . '</div>' .
    '<div class="aichat-stat-sub">' . get_string('dashallthreads', 'local_aichat') . '</div>' .
    '</div>';

// Messages.
echo '<div class="aichat-stat-card aichat-stat-card--messages">' .
    '<div class="aichat-stat-header">' .
        '<span class="aichat-stat-label">' . get_string('totalmessages', 'local_aichat') . '</span>' .
        '<div class="aichat-stat-icon aichat-stat-icon--messages">' .
            '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">' .
            '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>' .
        '</div>' .
    '</div>' .
    '<div class="aichat-stat-value">' . number_format($totalmessages) . '</div>' .
    '<div class="aichat-stat-sub">' . get_string('dashalltimemessages', 'local_aichat') . '</div>' .
    '</div>';

// Embedding stats card.
echo '<div class="aichat-stat-card aichat-stat-card--embeddings">' .
    '<div class="aichat-stat-header">' .
        '<span class="aichat-stat-label">' . get_string('embeddingtokens', 'local_aichat') . '</span>' .
        '<div class="aichat-stat-icon aichat-stat-icon--embeddings">' .
            '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">' .
            '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' .
        '</div>' .
    '</div>' .
    '<div class="aichat-stat-value">' . number_format($embeddingstats->total_tokens) . '</div>' .
    '<div class="aichat-stat-sub">' . get_string('embeddingchunks', 'local_aichat',
        number_format($embeddingstats->chunk_count)) . '</div>' .
    '</div>';

echo html_writer::end_div(); // stats-grid

// Daily token usage chart.
echo '<div class="aichat-chart-card">' .
    '<div class="aichat-chart-header">' .
        '<h3 class="aichat-chart-title">' .
            '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#94a3b8" stroke-width="2">' .
            '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/>' .
            '<line x1="6" y1="20" x2="6" y2="16"/></svg>' .
            get_string('dailytokenusage', 'local_aichat') .
        '</h3>' .
    '</div>' .
    '<div class="aichat-chart-body">' .
        '<canvas id="aichat-daily-tokens" height="300"></canvas>' .
    '</div>' .
    '</div>';

// Two charts side by side.
echo '<div class="aichat-grid-2">';

// Deployment breakdown chart.
echo '<div class="aichat-chart-card">' .
    '<div class="aichat-chart-header">' .
        '<h3 class="aichat-chart-title">' .
            '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#94a3b8" stroke-width="2">' .
            '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>' .
            get_string('tokensperdeployment', 'local_aichat') .
        '</h3>' .
    '</div>' .
    '<div class="aichat-chart-body">' .
        '<canvas id="aichat-deployment-tokens" height="250"></canvas>' .
    '</div>' .
    '</div>';

// Course breakdown chart.
echo '<div class="aichat-chart-card">' .
    '<div class="aichat-chart-header">' .
        '<h3 class="aichat-chart-title">' .
            '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#94a3b8" stroke-width="2">' .
            '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>' .
            '<path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>' .
            get_string('tokenspercoursechart', 'local_aichat') .
        '</h3>' .
    '</div>' .
    '<div class="aichat-chart-body">' .
        '<canvas id="aichat-course-tokens" height="300"></canvas>' .
    '</div>' .
    '</div>';

echo '</div>'; // grid-2

// Deployment breakdown table.
echo '<div class="aichat-table-card">' .
    '<div class="aichat-table-header">' .
        '<h3 class="aichat-table-title">' .
            '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#94a3b8" stroke-width="2">' .
            '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>' .
            get_string('deploymentbreakdown', 'local_aichat') .
        '</h3>' .
    '</div>' .
    '<div class="aichat-table-body">' .
        '<table class="aichat-table">' .
        '<thead><tr>' .
            '<th>' . get_string('deployment', 'local_aichat') . '</th>' .
            '<th>' . get_string('prompttokens', 'local_aichat') . '</th>' .
            '<th>' . get_string('completiontokens', 'local_aichat') . '</th>' .
            '<th>' . get_string('totaltokens', 'local_aichat') . '</th>' .
            '<th>' . get_string('requests', 'local_aichat') . '</th>' .
        '</tr></thead><tbody>';
foreach ($deploymentstats as $ds) {
    $label = !empty($ds->deployment) ? s($ds->deployment) : get_string('unknowndeployment', 'local_aichat');
    echo '<tr>' .
        '<td>' . $label . '</td>' .
        '<td class="aichat-td-number">' . number_format($ds->prompt_tokens) . '</td>' .
        '<td class="aichat-td-number">' . number_format($ds->completion_tokens) . '</td>' .
        '<td class="aichat-td-number">' . number_format($ds->total_tokens) . '</td>' .
        '<td class="aichat-td-number">' . number_format($ds->request_count) . '</td>' .
        '</tr>';
}
echo '</tbody></table></div></div>';

// Course breakdown table.
$exporturl = new moodle_url('/local/aichat/admin_dashboard.php', [
    'export' => 1, 'sesskey' => sesskey(), 'range' => $range,
]);

echo '<div class="aichat-table-card">' .
    '<div class="aichat-table-header">' .
        '<h3 class="aichat-table-title">' .
            '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#94a3b8" stroke-width="2">' .
            '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>' .
            '<path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>' .
            get_string('coursebreakdown', 'local_aichat') .
        '</h3>' .
        '<a href="' . $exporturl->out(true) . '" class="aichat-export-btn">' .
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' .
            '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>' .
            '<polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>' .
            get_string('exportcsv', 'local_aichat') .
        '</a>' .
    '</div>' .
    '<div class="aichat-table-body">' .
        '<table class="aichat-table">' .
        '<thead><tr>' .
            '<th>' . get_string('course') . '</th>' .
            '<th>' . get_string('prompttokens', 'local_aichat') . '</th>' .
            '<th>' . get_string('completiontokens', 'local_aichat') . '</th>' .
            '<th>' . get_string('totaltokens', 'local_aichat') . '</th>' .
            '<th>' . get_string('totalmessages', 'local_aichat') . '</th>' .
        '</tr></thead><tbody>';
foreach ($coursestats as $cs) {
    echo '<tr>' .
        '<td>' . format_string($cs->fullname) . '</td>' .
        '<td class="aichat-td-number">' . number_format($cs->prompt_tokens) . '</td>' .
        '<td class="aichat-td-number">' . number_format($cs->completion_tokens) . '</td>' .
        '<td class="aichat-td-number">' . number_format($cs->total_tokens) . '</td>' .
        '<td class="aichat-td-number">' . $cs->message_count . '</td>' .
        '</tr>';
}
echo '</tbody></table></div></div>';

// Embedding consumption per course table.
echo '<div class="aichat-table-card">' .
    '<div class="aichat-table-header">' .
        '<h3 class="aichat-table-title">' .
            '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#94a3b8" stroke-width="2">' .
            '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' .
            get_string('embeddingpercourse', 'local_aichat') .
        '</h3>' .
    '</div>' .
    '<div class="aichat-table-body">' .
        '<table class="aichat-table">' .
        '<thead><tr>' .
            '<th>' . get_string('course') . '</th>' .
            '<th>' . get_string('dashchunks', 'local_aichat') . '</th>' .
            '<th>' . get_string('embeddingtokens', 'local_aichat') . '</th>' .
            '<th>' . get_string('lastactive', 'local_aichat') . '</th>' .
        '</tr></thead><tbody>';
if (empty($embeddingpercourse)) {
    echo '<tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:24px;">' .
        get_string('noembeddingsyet', 'local_aichat') . '</td></tr>';
} else {
    foreach ($embeddingpercourse as $ec) {
        echo '<tr>' .
            '<td>' . format_string($ec->fullname) . '</td>' .
            '<td class="aichat-td-number">' . number_format($ec->chunk_count) . '</td>' .
            '<td class="aichat-td-number">' . number_format($ec->total_tokens) . '</td>' .
            '<td>' . ($ec->last_indexed ? userdate($ec->last_indexed, get_string('strftimedatetime')) : '-') . '</td>' .
            '</tr>';
    }
}
echo '</tbody></table></div></div>';

// Top 20 users by usage table (site-wide).
$exportusersurl_admin = new moodle_url('/local/aichat/admin_dashboard.php', [
    'exportusers' => 1, 'sesskey' => sesskey(), 'range' => $range,
]);

echo '<div class="aichat-table-card">' .
    '<div class="aichat-table-header">' .
        '<h3 class="aichat-table-title">' .
            '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#94a3b8" stroke-width="2">' .
            '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>' .
            '<path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>' .
            get_string('topusersbyusage', 'local_aichat') .
        '</h3>' .
        '<a href="' . $exportusersurl_admin->out(true) . '" class="aichat-export-btn">' .
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' .
            '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>' .
            '<polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>' .
            get_string('exportusercsv', 'local_aichat') .
        '</a>' .
    '</div>' .
    '<div class="aichat-table-body">' .
        '<table class="aichat-table">' .
        '<thead><tr>' .
            '<th>' . get_string('user') . '</th>' .
            '<th>' . get_string('email') . '</th>' .
            '<th>' . get_string('courses') . '</th>' .
            '<th>' . get_string('totalmessages', 'local_aichat') . '</th>' .
            '<th>' . get_string('totaltokens', 'local_aichat') . '</th>' .
            '<th>' . get_string('lastactive', 'local_aichat') . '</th>' .
        '</tr></thead><tbody>';
if (empty($topusers_admin)) {
    echo '<tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:24px;">' .
        get_string('nousersyet', 'local_aichat') . '</td></tr>';
} else {
    foreach ($topusers_admin as $u) {
        $fullname = s(fullname($u));
        echo '<tr>' .
            '<td>' . $fullname . '</td>' .
            '<td>' . s($u->email) . '</td>' .
            '<td class="aichat-td-number">' . number_format($u->course_count) . '</td>' .
            '<td class="aichat-td-number">' . number_format($u->message_count) . '</td>' .
            '<td class="aichat-td-number">' . number_format($u->total_tokens) . '</td>' .
            '<td>' . ($u->last_active ? userdate($u->last_active, get_string('strftimedatetime')) : '-') . '</td>' .
            '</tr>';
    }
}
echo '</tbody></table></div></div>';

echo html_writer::end_div(); // aichat-dashboard

// Chart.js with improved styling.
$chartcolors = "['#6366f1','#f59e0b','#10b981','#ec4899','#8b5cf6','#f97316','#14b8a6','#e11d48','#0ea5e9','#84cc16']";
$PAGE->requires->js_amd_inline("
require(['core/chartjs'], function(Chart) {
    // Shared tooltip config.
    var tooltipConfig = {
        backgroundColor: '#1e293b',
        titleColor: '#f8fafc',
        bodyColor: '#cbd5e1',
        padding: 12,
        cornerRadius: 8,
        displayColors: true
    };

    // Daily tokens.
    var ctx1 = document.getElementById('aichat-daily-tokens').getContext('2d');
    var grad1 = ctx1.createLinearGradient(0, 0, 0, 300);
    grad1.addColorStop(0, 'rgba(176, 43, 41, 0.15)');
    grad1.addColorStop(1, 'rgba(176, 43, 41, 0.01)');
    var grad2 = ctx1.createLinearGradient(0, 0, 0, 300);
    grad2.addColorStop(0, 'rgba(43, 108, 176, 0.15)');
    grad2.addColorStop(1, 'rgba(43, 108, 176, 0.01)');
    new Chart(ctx1, {
        type: 'line',
        data: {
            labels: " . json_encode($daylabels) . ",
            datasets: [
                {
                    label: '" . get_string('prompttokens', 'local_aichat') . "',
                    data: " . json_encode($promptdata) . ",
                    borderColor: '#4f46e5',
                    backgroundColor: grad1,
                    fill: true, tension: 0.4, borderWidth: 2.5,
                    pointRadius: 3, pointBackgroundColor: '#fff',
                    pointBorderColor: '#4f46e5', pointBorderWidth: 2,
                    pointHoverRadius: 5
                },
                {
                    label: '" . get_string('completiontokens', 'local_aichat') . "',
                    data: " . json_encode($compldata) . ",
                    borderColor: '#2b6cb0',
                    backgroundColor: grad2,
                    fill: true, tension: 0.4, borderWidth: 2.5,
                    pointRadius: 3, pointBackgroundColor: '#fff',
                    pointBorderColor: '#2b6cb0', pointBorderWidth: 2,
                    pointHoverRadius: 5
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { tooltip: tooltipConfig, legend: { labels: { usePointStyle: true, padding: 20 } } },
            scales: {
                y: { beginAtZero: true, ticks: { color: '#94a3b8' }, grid: { color: '#f1f5f9' }, border: { display: false } },
                x: { ticks: { color: '#94a3b8', maxRotation: 45 }, grid: { display: false }, border: { display: false } }
            }
        }
    });

    // Deployment tokens.
    new Chart(document.getElementById('aichat-deployment-tokens').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: " . json_encode($deploylabels) . ",
            datasets: [{
                data: " . json_encode($deploytokens) . ",
                backgroundColor: " . $chartcolors . ",
                borderWidth: 0,
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                tooltip: tooltipConfig,
                legend: { position: 'bottom', labels: { usePointStyle: true, padding: 16, font: { size: 12 } } }
            },
            cutout: '60%'
        }
    });

    // Course tokens.
    new Chart(document.getElementById('aichat-course-tokens').getContext('2d'), {
        type: 'bar',
        data: {
            labels: " . json_encode($courselabels) . ",
            datasets: [{
                label: '" . get_string('totaltokens', 'local_aichat') . "',
                data: " . json_encode($coursetokens) . ",
                backgroundColor: " . $chartcolors . ",
                borderRadius: 6,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: { tooltip: tooltipConfig, legend: { display: false } },
            scales: {
                x: { beginAtZero: true, ticks: { color: '#94a3b8' }, grid: { color: '#f1f5f9' }, border: { display: false } },
                y: { ticks: { color: '#334155', font: { size: 12 } }, grid: { display: false }, border: { display: false } }
            }
        }
    });
});
");

echo $OUTPUT->footer();
