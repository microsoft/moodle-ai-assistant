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
 * AI Chat - Teacher analytics dashboard.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);

$course  = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/aichat:viewdashboard', $context);

$PAGE->set_url(new moodle_url('/local/aichat/dashboard.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('dashboard', 'local_aichat'));
$PAGE->set_heading(format_string($course->fullname));

// Handle rebuild action before data gathering so stats are up to date.
$rebuildresult = null;
if (optional_param('rebuild', 0, PARAM_BOOL) && confirm_sesskey()) {
    $rebuildresult = \local_aichat\rag\vector_store::index_course($courseid);
}

// Gather analytics data.
$now   = time();
$days  = 30;
$start = $now - ($days * DAYSECS);

// Messages per day (last 30 days). Use FLOOR division for cross-DB compatibility (PostgreSQL + MySQL).
$sql = "SELECT FLOOR(m.timecreated / 86400) AS daynum, COUNT(*) AS cnt
          FROM {local_aichat_messages} m
          JOIN {local_aichat_threads} t ON t.id = m.threadid
         WHERE t.courseid = :courseid
           AND m.role = :role
           AND m.timecreated >= :start
         GROUP BY FLOOR(m.timecreated / 86400)
         ORDER BY daynum ASC";
$messagesperday = $DB->get_records_sql($sql, [
    'courseid' => $courseid,
    'role'     => 'user',
    'start'    => $start,
]);

// Unique users.
$uniqueusers = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT t.userid)
       FROM {local_aichat_threads} t
      WHERE t.courseid = :courseid",
    ['courseid' => $courseid]
);

// Total messages.
$totalmessages = $DB->count_records_sql(
    "SELECT COUNT(m.id)
       FROM {local_aichat_messages} m
       JOIN {local_aichat_threads} t ON t.id = m.threadid
      WHERE t.courseid = :courseid AND m.role = :role",
    ['courseid' => $courseid, 'role' => 'user']
);

// Feedback breakdown.
// The feedback column stores integers: 1 = thumbs up, -1 = thumbs down.
// get_records_sql keys by the first selected column, so keys are '1' and '-1'.
$feedbackstats = $DB->get_records_sql(
    "SELECT f.feedback, COUNT(*) AS cnt
       FROM {local_aichat_feedback} f
       JOIN {local_aichat_messages} m ON m.id = f.messageid
       JOIN {local_aichat_threads} t ON t.id = m.threadid
      WHERE t.courseid = :courseid
      GROUP BY f.feedback",
    ['courseid' => $courseid]
);
$thumbsup   = isset($feedbackstats[1]) ? (int) $feedbackstats[1]->cnt : 0;
$thumbsdown = isset($feedbackstats[-1]) ? (int) $feedbackstats[-1]->cnt : 0;

// Token usage (total).
$tokenstats = $DB->get_record_sql(
    "SELECT COALESCE(SUM(tu.prompt_tokens), 0) AS prompt_tokens,
            COALESCE(SUM(tu.completion_tokens), 0) AS completion_tokens,
            COALESCE(SUM(tu.total_tokens), 0) AS total_tokens
       FROM {local_aichat_token_usage} tu
       JOIN {local_aichat_messages} m ON m.id = tu.messageid
       JOIN {local_aichat_threads} t ON t.id = m.threadid
      WHERE t.courseid = :courseid",
    ['courseid' => $courseid]
);

// RAG index stats.
$ragstats = \local_aichat\rag\vector_store::get_index_stats($courseid);

// Top 20 users by usage in this course.
$sql = "SELECT u.id AS userid, u.firstname, u.lastname, u.email,
               msg.message_count,
               COALESCE(tok.total_tokens, 0) AS total_tokens,
               COALESCE(tok.prompt_tokens, 0) AS prompt_tokens,
               COALESCE(tok.completion_tokens, 0) AS completion_tokens,
               msg.last_active
          FROM {user} u
          JOIN (SELECT t.userid,
                       COUNT(m.id) AS message_count,
                       MAX(m.timecreated) AS last_active
                  FROM {local_aichat_threads} t
                  JOIN {local_aichat_messages} m ON m.threadid = t.id AND m.role = 'user'
                 WHERE t.courseid = :cid1
                 GROUP BY t.userid) msg ON msg.userid = u.id
     LEFT JOIN (SELECT t2.userid,
                       SUM(tu.total_tokens) AS total_tokens,
                       SUM(tu.prompt_tokens) AS prompt_tokens,
                       SUM(tu.completion_tokens) AS completion_tokens
                  FROM {local_aichat_threads} t2
                  JOIN {local_aichat_messages} am ON am.threadid = t2.id AND am.role = 'assistant'
                  JOIN {local_aichat_token_usage} tu ON tu.messageid = am.id
                 WHERE t2.courseid = :cid2
                 GROUP BY t2.userid) tok ON tok.userid = u.id
         ORDER BY total_tokens DESC";
$topusers = $DB->get_records_sql($sql, ['cid1' => $courseid, 'cid2' => $courseid], 0, 20);

// Handle user usage CSV export for this course.
if (optional_param('exportusers', 0, PARAM_BOOL) && confirm_sesskey()) {
    // Fetch top 1000 users for the full report.
    $allusers = $DB->get_records_sql($sql, ['cid1' => $courseid, 'cid2' => $courseid], 0, 1000);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="aichat-user-usage-' . $courseid . '-' . date('Ymd') . '.csv"');
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['userid', 'firstname', 'lastname', 'email', 'messages',
                   'prompt_tokens', 'completion_tokens', 'total_tokens', 'last_active']);
    foreach ($allusers as $u) {
        $firstname = format_string($u->firstname);
        $lastname = format_string($u->lastname);
        // Prevent CSV formula injection.
        if (preg_match('/^[=+\-@\t\r]/', $firstname)) {
            $firstname = "'" . $firstname;
        }
        if (preg_match('/^[=+\-@\t\r]/', $lastname)) {
            $lastname = "'" . $lastname;
        }
        fputcsv($fp, [
            $u->userid, $firstname, $lastname, $u->email,
            $u->message_count, $u->prompt_tokens, $u->completion_tokens,
            $u->total_tokens, $u->last_active ? userdate($u->last_active) : '',
        ]);
    }
    fclose($fp);
    exit;
}

// Prepare chart data.
$chartlabels = [];
$chartdata   = [];
foreach ($messagesperday as $row) {
    $chartlabels[] = date('Y-m-d', (int) $row->daynum * 86400);
    $chartdata[]   = (int) $row->cnt;
}

// Calculate feedback percentage for the sub-text.
$totalfeedback = $thumbsup + $thumbsdown;
$feedbackpercent = $totalfeedback > 0 ? round(($thumbsup / $totalfeedback) * 100) : 0;

// Calculate average messages per day.
$avgperday = count($chartdata) > 0 ? round(array_sum($chartdata) / count($chartdata), 1) : 0;

echo $OUTPUT->header();

echo html_writer::start_div('aichat-dashboard');

// Dashboard header.
echo html_writer::start_div('aichat-dashboard-header');
echo html_writer::start_div('aichat-dashboard-title');
echo '<div class="aichat-dashboard-icon">' .
    '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' .
    '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>' .
    '<rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>' .
    '</svg></div>';
echo html_writer::tag('h2', get_string('dashboard', 'local_aichat'));
echo html_writer::end_div();
echo html_writer::end_div();

// Show rebuild notification if applicable.
if ($rebuildresult !== null) {
    echo '<div class="aichat-notification aichat-notification--success">' .
        '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">' .
        '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' .
        '<span>' . get_string('indexrebuilt', 'local_aichat', (object) $rebuildresult) . '</span></div>';
}

// Stats grid.
echo html_writer::start_div('aichat-stats-grid');

// Users card.
echo '<div class="aichat-stat-card aichat-stat-card--users">' .
    '<div class="aichat-stat-header">' .
        '<span class="aichat-stat-label">' . get_string('uniqueusers', 'local_aichat') . '</span>' .
        '<div class="aichat-stat-icon aichat-stat-icon--users">' .
            '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">' .
            '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>' .
            '<path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>' .
        '</div>' .
    '</div>' .
    '<div class="aichat-stat-value">' . $uniqueusers . '</div>' .
    '<div class="aichat-stat-sub">' . get_string('dashactivestudents', 'local_aichat') . '</div>' .
    '</div>';

// Messages card.
echo '<div class="aichat-stat-card aichat-stat-card--messages">' .
    '<div class="aichat-stat-header">' .
        '<span class="aichat-stat-label">' . get_string('totalmessages', 'local_aichat') . '</span>' .
        '<div class="aichat-stat-icon aichat-stat-icon--messages">' .
            '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">' .
            '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>' .
        '</div>' .
    '</div>' .
    '<div class="aichat-stat-value">' . number_format($totalmessages) . '</div>' .
    '<div class="aichat-stat-sub">' . get_string('dashavgperday', 'local_aichat', $avgperday) . '</div>' .
    '</div>';

// Tokens card.
echo '<div class="aichat-stat-card aichat-stat-card--tokens">' .
    '<div class="aichat-stat-header">' .
        '<span class="aichat-stat-label">' . get_string('totaltokens', 'local_aichat') . '</span>' .
        '<div class="aichat-stat-icon aichat-stat-icon--tokens">' .
            '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">' .
            '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>' .
        '</div>' .
    '</div>' .
    '<div class="aichat-stat-value">' . number_format($tokenstats->total_tokens) . '</div>' .
    '<div class="aichat-stat-sub">' . get_string('dashprompttokens', 'local_aichat',
        number_format($tokenstats->prompt_tokens)) . '</div>' .
    '</div>';

// Feedback card.
echo '<div class="aichat-stat-card aichat-stat-card--feedback">' .
    '<div class="aichat-stat-header">' .
        '<span class="aichat-stat-label">' . get_string('feedback', 'local_aichat') . '</span>' .
        '<div class="aichat-stat-icon aichat-stat-icon--feedback">' .
            '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">' .
            '<path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3H14z"/>' .
            '<path d="M7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>' .
        '</div>' .
    '</div>' .
    '<div class="aichat-feedback-row">' .
        '<div class="aichat-feedback-item aichat-feedback-item--up">' .
            '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M2 21h4V9H2v12zm22-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L14.17 1 7.59 7.59C7.22 7.95 7 8.45 7 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z"/></svg>' .
            '<span>' . $thumbsup . '</span>' .
        '</div>' .
        '<div class="aichat-feedback-item aichat-feedback-item--down">' .
            '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M22 3h-4v12h4V3zm-8 12V5c0-1.1-.9-2-2-2H3c-.83 0-1.54.5-1.84 1.22l-3.02 7.05c-.09.23-.14.47-.14.73v2c0 1.1.9 2 2 2h6.31l-.95 4.57-.03.32c0 .41.17.79.44 1.06L9.83 23l6.59-6.59c.36-.36.58-.86.58-1.41z" transform="translate(24,24) rotate(180)"/></svg>' .
            '<span>' . $thumbsdown . '</span>' .
        '</div>' .
    '</div>' .
    '<div class="aichat-stat-sub">' . get_string('dashsatisfaction', 'local_aichat', $feedbackpercent) . '</div>' .
    '</div>';

echo html_writer::end_div(); // stats-grid

// RAG Index Status.
$ragstatusclass = $ragstats['chunk_count'] > 0 ? 'indexed' : 'empty';
$ragstatuslabel = $ragstats['chunk_count'] > 0
    ? get_string('dashragindexed', 'local_aichat')
    : get_string('dashragnoindex', 'local_aichat');
$rebuildurl = new moodle_url('/local/aichat/dashboard.php', [
    'courseid' => $courseid,
    'rebuild' => 1,
    'sesskey' => sesskey(),
]);

echo '<div class="aichat-rag-card">' .
    '<div class="aichat-rag-header">' .
        '<h3 class="aichat-rag-title">' .
            '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#94a3b8" stroke-width="2">' .
            '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' .
            get_string('ragindexstatus', 'local_aichat') .
        '</h3>' .
        '<span class="aichat-rag-status-badge aichat-rag-status-badge--' . $ragstatusclass . '">' .
            '<span class="aichat-rag-status-dot"></span>' .
            $ragstatuslabel .
        '</span>' .
    '</div>' .
    '<div class="aichat-rag-body">' .
        '<div class="aichat-rag-metrics">' .
            '<div class="aichat-rag-metric">' .
                '<span class="aichat-rag-metric-label">' . get_string('dashchunks', 'local_aichat') . '</span>' .
                '<span class="aichat-rag-metric-value">' . number_format($ragstats['chunk_count']) . '</span>' .
            '</div>' .
            '<div class="aichat-rag-metric">' .
                '<span class="aichat-rag-metric-label">' . get_string('embeddingtokens', 'local_aichat') . '</span>' .
                '<span class="aichat-rag-metric-value">' . number_format($ragstats['total_tokens']) . '</span>' .
            '</div>';
if ($ragstats['last_indexed']) {
    echo '<div class="aichat-rag-metric">' .
            '<span class="aichat-rag-metric-label">' . get_string('dashlastindexed', 'local_aichat') . '</span>' .
            '<span class="aichat-rag-metric-value">' . userdate($ragstats['last_indexed']) . '</span>' .
        '</div>';
}
echo '</div>' .
        '<div style="margin-top: 16px;">' .
            '<a href="' . $rebuildurl->out(true) . '" class="aichat-rebuild-btn">' .
                '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">' .
                '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/>' .
                '<path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>' .
                get_string('rebuildindex', 'local_aichat') .
            '</a>' .
        '</div>' .
    '</div>' .
    '</div>';

// Messages per day chart.
echo '<div class="aichat-chart-card">' .
    '<div class="aichat-chart-header">' .
        '<h3 class="aichat-chart-title">' .
            '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#94a3b8" stroke-width="2">' .
            '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/>' .
            '<line x1="6" y1="20" x2="6" y2="14"/></svg>' .
            get_string('messagesperday', 'local_aichat') .
        '</h3>' .
    '</div>' .
    '<div class="aichat-chart-body">' .
        '<canvas id="aichat-messages-chart" height="300"></canvas>' .
    '</div>' .
    '</div>';

// Top 20 users by usage table.
$exportusersurl = new moodle_url('/local/aichat/dashboard.php', [
    'courseid' => $courseid, 'exportusers' => 1, 'sesskey' => sesskey(),
]);

echo '<div class="aichat-table-card">' .
    '<div class="aichat-table-header">' .
        '<h3 class="aichat-table-title">' .
            '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#94a3b8" stroke-width="2">' .
            '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>' .
            '<path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>' .
            get_string('topusersbyusage', 'local_aichat') .
        '</h3>' .
        '<a href="' . $exportusersurl->out(true) . '" class="aichat-export-btn">' .
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
            '<th>' . get_string('totalmessages', 'local_aichat') . '</th>' .
            '<th>' . get_string('totaltokens', 'local_aichat') . '</th>' .
            '<th>' . get_string('lastactive', 'local_aichat') . '</th>' .
        '</tr></thead><tbody>';
if (empty($topusers)) {
    echo '<tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:24px;">' .
        get_string('nousersyet', 'local_aichat') . '</td></tr>';
} else {
    foreach ($topusers as $u) {
        $fullname = s(fullname($u));
        echo '<tr>' .
            '<td>' . $fullname . '</td>' .
            '<td>' . s($u->email) . '</td>' .
            '<td class="aichat-td-number">' . number_format($u->message_count) . '</td>' .
            '<td class="aichat-td-number">' . number_format($u->total_tokens) . '</td>' .
            '<td>' . ($u->last_active ? userdate($u->last_active, get_string('strftimedatetime')) : '-') . '</td>' .
            '</tr>';
    }
}
echo '</tbody></table></div></div>';

echo html_writer::end_div(); // aichat-dashboard

// Include Chart.js via Moodle AMD.
$PAGE->requires->js_amd_inline("
require(['core/chartjs'], function(Chart) {
    var ctx = document.getElementById('aichat-messages-chart').getContext('2d');
    var gradient = ctx.createLinearGradient(0, 0, 0, 300);
    gradient.addColorStop(0, 'rgba(176, 43, 41, 0.15)');
    gradient.addColorStop(1, 'rgba(176, 43, 41, 0.01)');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: " . json_encode($chartlabels) . ",
            datasets: [{
                label: '" . get_string('messagesperday', 'local_aichat') . "',
                data: " . json_encode($chartdata) . ",
                borderColor: '#4f46e5',
                backgroundColor: gradient,
                fill: true,
                tension: 0.4,
                borderWidth: 2.5,
                pointRadius: 4,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#4f46e5',
                pointBorderWidth: 2,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: '#4f46e5',
                pointHoverBorderColor: '#fff',
                pointHoverBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1e293b',
                    titleColor: '#f8fafc',
                    bodyColor: '#cbd5e1',
                    padding: 12,
                    cornerRadius: 8,
                    displayColors: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1, color: '#94a3b8', font: { size: 12 } },
                    grid: { color: '#f1f5f9' },
                    border: { display: false }
                },
                x: {
                    ticks: { color: '#94a3b8', font: { size: 11 }, maxRotation: 45 },
                    grid: { display: false },
                    border: { display: false }
                }
            }
        }
    });
});
");

echo $OUTPUT->footer();
