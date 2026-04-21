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
 * AI Chat - SSE streaming endpoint.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
define('NO_MOODLE_COOKIES', false);

require_once(__DIR__ . '/../../config.php');

require_login(null, false, null, false, true);
require_sesskey();

$courseid  = required_param('courseid', PARAM_INT);
$message   = required_param('message', PARAM_RAW);
$sectionid = optional_param('sectionid', 0, PARAM_INT);
$cmid      = optional_param('cmid', 0, PARAM_INT);

$context = context_course::instance($courseid);
require_capability('local/aichat:use', $context);

// SSE headers.
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

// Prevent PHP output buffering.
while (ob_get_level()) {
    ob_end_flush();
}

/**
 * Send an SSE event.
 *
 * @param string $eventname
 * @param mixed $data
 */
function local_aichat_sse_send(string $eventname, $data): void {
    echo "event: {$eventname}\n";
    echo "data: " . json_encode($data) . "\n\n";
    flush();
}

// Helper: write a trace line to aichat.log (defined outside try so catch can use it).
$aichat_trace = function (string $level, string $step, string $detail = '') use ($CFG) {
    if (!get_config('local_aichat', 'enablefilelog')) {
        return;
    }
    $dir = $CFG->dataroot . '/local_aichat';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $ts = date('Y-m-d H:i:s');
    $line = "[{$ts}] [local_aichat] [{$level}] {$step}";
    if ($detail !== '') {
        $line .= ' ' . $detail;
    }
    @file_put_contents($dir . '/aichat.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
};

try {
    $usermsg = \local_aichat\security\input_sanitizer::sanitize_message($message);
    $maxlen  = (int) get_config('local_aichat', 'maxmsglength') ?: 2000;
    \local_aichat\security\input_sanitizer::validate_message_length($usermsg, $maxlen);
    $usermsg = \local_aichat\security\input_sanitizer::strip_prompt_injection($usermsg, $USER->id, $courseid);

    if (empty(trim($usermsg))) {
        throw new moodle_exception('emptyinput', 'local_aichat');
    }

    // Rate limiting.
    \local_aichat\security\rate_limiter::check_burst_limit($USER->id);
    $dailystatus = \local_aichat\security\rate_limiter::check_daily_limit($USER->id);

    // Get or create thread.
    $thread = $DB->get_record('local_aichat_threads', [
        'userid'   => $USER->id,
        'courseid' => $courseid,
    ]);
    $now = time();
    if (!$thread) {
        $thread = new stdClass();
        $thread->userid = $USER->id;
        $thread->courseid = $courseid;
        $thread->title = get_string('newchat', 'local_aichat');
        $thread->timecreated = $now;
        $thread->timemodified = $now;
        $thread->id = $DB->insert_record('local_aichat_threads', $thread);
    }

    // Store user message.
    $usermsgrecord = new stdClass();
    $usermsgrecord->threadid = $thread->id;
    $usermsgrecord->role = 'user';
    $usermsgrecord->message = $usermsg;
    $usermsgrecord->timecreated = $now;
    $usermsgrecord->id = $DB->insert_record('local_aichat_messages', $usermsgrecord);

    // Log user message (PII-safe: no username, message content hashed).
    $aichat_trace('INFO', 'user_message',
        "userid={$USER->id}"
        . " courseid={$courseid}"
        . " threadid={$thread->id}"
        . " msgid={$usermsgrecord->id}"
        . " len=" . core_text::strlen($usermsg)
    );

    // RAG context assembly — pass current cmid and sectionid so the context
    // assembler can inject the live page content into every prompt.
    $aichat_trace('DEBUG', 'rag_start', "courseid={$courseid} cmid={$cmid} sectionid={$sectionid}");
    $ragcontext = \local_aichat\rag\context_assembler::build_context(
        $courseid, $usermsg,
        $cmid > 0 ? $cmid : null,
        $sectionid > 0 ? $sectionid : null
    );
    $aichat_trace('DEBUG', 'rag_done', 'len=' . strlen($ragcontext));

    // Build system prompt.
    $course = get_course($courseid);
    $lang   = current_language();
    $aichat_trace('DEBUG', 'build_system_prompt', "course={$course->fullname} lang={$lang}");
    $systemprompt = \local_aichat\azure_openai_client::build_system_prompt(
        format_string($course->fullname), $lang, $ragcontext
    );
    $aichat_trace('DEBUG', 'system_prompt_done', 'len=' . strlen($systemprompt));

    // History summarization.
    $aichat_trace('DEBUG', 'history_start', "threadid={$thread->id}");
    $historydata = \local_aichat\history_summarizer::get_context_history($thread->id);
    $aichat_trace('DEBUG', 'history_done',
        'messages=' . count($historydata['messages'])
        . ' has_summary=' . (!empty($historydata['summary']) ? 'yes' : 'no')
    );

    // Build API messages.
    $apimessages = \local_aichat\azure_openai_client::build_messages(
        $systemprompt,
        $historydata['messages'],
        $historydata['summary'],
        $usermsg
    );
    $aichat_trace('INFO', 'api_messages_built', 'count=' . count($apimessages));

    // Stream via SSE.
    $fullresponse = '';
    $aichat_trace('INFO', 'stream_call_start', '');

    $streamresult = \local_aichat\azure_openai_client::stream(
        $apimessages,
        function (string $token) use (&$fullresponse) {
            $fullresponse .= $token;
            local_aichat_sse_send('token', ['token' => $token]);
        }
    );

    $aichat_trace('INFO', 'stream_call_done',
        'prompt_tokens=' . ($streamresult['prompt_tokens'] ?? 0)
        . ' completion_tokens=' . ($streamresult['completion_tokens'] ?? 0)
        . ' total_tokens=' . ($streamresult['total_tokens'] ?? 0)
    );

    // Parse suggestions from full response.
    $parsed = \local_aichat\azure_openai_client::parse_suggestions($fullresponse);

    // Output sanitization.
    $cleanresponse = \local_aichat\security\output_sanitizer::sanitize_ai_response($parsed['clean_response']);

    // Store assistant message.
    $assistantrecord = new stdClass();
    $assistantrecord->threadid = $thread->id;
    $assistantrecord->role = 'assistant';
    $assistantrecord->message = $cleanresponse;
    $assistantrecord->timecreated = time();
    $assistantrecord->id = $DB->insert_record('local_aichat_messages', $assistantrecord);

    // Store token usage.
    $prompttokens     = $streamresult['prompt_tokens'] ?? 0;
    $completiontokens = $streamresult['completion_tokens'] ?? 0;
    $totaltokens      = $streamresult['total_tokens'] ?? ($prompttokens + $completiontokens);

    $tokenrecord = new stdClass();
    $tokenrecord->messageid = $assistantrecord->id;
    $tokenrecord->deployment = $streamresult['deployment'] ?? '';
    $tokenrecord->prompt_tokens = $prompttokens;
    $tokenrecord->completion_tokens = $completiontokens;
    $tokenrecord->total_tokens = $totaltokens;
    $tokenrecord->timecreated = time();
    $DB->insert_record('local_aichat_token_usage', $tokenrecord);

    // Update thread.
    $DB->set_field('local_aichat_threads', 'timemodified', time(), ['id' => $thread->id]);

    // Update summary if needed.
    \local_aichat\history_summarizer::update_summary_if_needed($thread->id);

    // Fire event.
    $event = \local_aichat\event\chat_message_sent::create([
        'context' => $context,
        'userid'  => $USER->id,
        'other'   => [
            'threadid'          => $thread->id,
            'messagelength'     => core_text::strlen($usermsg),
            'prompt_tokens'     => $prompttokens,
            'completion_tokens' => $completiontokens,
        ],
    ]);
    $event->trigger();

    // Send final SSE event.
    $remaining = ($dailystatus['remaining'] > 0) ? $dailystatus['remaining'] - 1 : 0;
    local_aichat_sse_send('done', [
        'messageid'  => (int) $assistantrecord->id,
        'remaining'  => $remaining,
        'suggestions' => $parsed['suggestions'],
    ]);

} catch (\Throwable $e) {
    $errfile = basename($e->getFile()) . ':' . $e->getLine();
    $errmsg  = $e->getMessage();
    error_log('[local_aichat] [ERROR] ajax.php exception class=' . get_class($e)
        . ' code=' . ($e instanceof moodle_exception ? $e->errorcode : 'n/a')
        . ' msg=' . $errmsg
        . ' at=' . $e->getFile() . ':' . $e->getLine());

    // Also write to the dedicated aichat log file.
    $aichat_trace('ERROR', 'exception',
        'class=' . get_class($e)
        . ' code=' . ($e instanceof moodle_exception ? $e->errorcode : 'n/a')
        . ' msg=' . $errmsg
        . ' at=' . $errfile
    );
    debugging('AI Chat SSE error: ' . $errmsg . ' in ' . $e->getFile() . ':' . $e->getLine(),
        DEBUG_NORMAL);
    // Send the actual error to client; errorcode drives the displayed string,
    // with a debug detail suffix when Moodle debugging is enabled.
    $errorkey = $e instanceof moodle_exception ? $e->errorcode : 'assistantunavailable';
    $a = $e instanceof moodle_exception ? $e->a : null;
    // Attempt to resolve the lang string; fall back to assistantunavailable.
    $errstring = get_string_manager()->string_exists($errorkey, 'local_aichat')
        ? get_string($errorkey, 'local_aichat', $a)
        : get_string('assistantunavailable', 'local_aichat');
    local_aichat_sse_send('error', [
        'message'   => $errstring,
        'debug'     => debugging('', DEBUG_DEVELOPER, false)
            ? $errorkey . ': ' . $errmsg
            : '',
    ]);
}
