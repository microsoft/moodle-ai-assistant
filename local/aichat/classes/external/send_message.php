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
 * AI Chat - Send message external function
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;

/**
 * Send a user message and return the AI response (non-streaming fallback).
 */
class send_message extends external_api {

    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'message'  => new external_value(PARAM_RAW, 'User message text'),
            'sectionid' => new external_value(PARAM_INT, 'Current section ID', VALUE_DEFAULT, 0),
            'cmid'     => new external_value(PARAM_INT, 'Current course module ID', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Send a message and get the AI response.
     *
     * @param int $courseid
     * @param string $message
     * @param int $sectionid
     * @param int $cmid
     * @return array
     */
    public static function execute(int $courseid, string $message, int $sectionid = 0, int $cmid = 0): array {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'  => $courseid,
            'message'   => $message,
            'sectionid' => $sectionid,
            'cmid'      => $cmid,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/aichat:use', $context);

        $cid = $params['courseid'];
        $usermsg = $params['message'];

        // Input sanitization.
        $usermsg = \local_aichat\security\input_sanitizer::sanitize_message($usermsg);
        $maxlen = (int) get_config('local_aichat', 'maxmsglength') ?: 2000;
        \local_aichat\security\input_sanitizer::validate_message_length($usermsg, $maxlen);
        $usermsg = \local_aichat\security\input_sanitizer::strip_prompt_injection($usermsg, $USER->id, $cid);

        if (empty(trim($usermsg))) {
            throw new \moodle_exception('emptyinput', 'local_aichat');
        }

        // Rate limiting.
        \local_aichat\security\rate_limiter::check_burst_limit($USER->id);
        $dailystatus = \local_aichat\security\rate_limiter::check_daily_limit($USER->id);

        // Get or create the user's thread.
        $thread = $DB->get_record('local_aichat_threads', [
            'userid' => $USER->id,
            'courseid' => $cid,
        ]);
        $now = time();
        if (!$thread) {
            $thread = new \stdClass();
            $thread->userid = $USER->id;
            $thread->courseid = $cid;
            $thread->title = get_string('newchat', 'local_aichat');
            $thread->timecreated = $now;
            $thread->timemodified = $now;
            $thread->id = $DB->insert_record('local_aichat_threads', $thread);
        }

        // Store the user message.
        $usermsgrecord = new \stdClass();
        $usermsgrecord->threadid = $thread->id;
        $usermsgrecord->role = 'user';
        $usermsgrecord->message = $usermsg;
        $usermsgrecord->timecreated = $now;
        $usermsgrecord->id = $DB->insert_record('local_aichat_messages', $usermsgrecord);

        // RAG context assembly — pass current cmid and sectionid so the context
        // assembler can inject the live page content into every prompt.
        $ragcontext = \local_aichat\rag\context_assembler::build_context(
            $cid, $usermsg,
            $params['cmid'] > 0 ? $params['cmid'] : null,
            $params['sectionid'] > 0 ? $params['sectionid'] : null
        );

        // Build system prompt.
        $course = get_course($cid);
        $lang = current_language();
        $systemprompt = \local_aichat\azure_openai_client::build_system_prompt(
            format_string($course->fullname), $lang, $ragcontext
        );

        // History summarization.
        $historydata = \local_aichat\history_summarizer::get_context_history($thread->id);

        // Build API messages.
        $apimessages = \local_aichat\azure_openai_client::build_messages(
            $systemprompt,
            $historydata['messages'],
            $historydata['summary'],
            $usermsg
        );

        // Call Azure OpenAI (non-streaming).
        $result = \local_aichat\azure_openai_client::complete($apimessages);

        // Parse suggestions from response.
        $parsed = \local_aichat\azure_openai_client::parse_suggestions($result['response']);

        // Output sanitization.
        $cleanresponse = \local_aichat\security\output_sanitizer::sanitize_ai_response($parsed['clean_response']);

        // Store the assistant message.
        $assistantrecord = new \stdClass();
        $assistantrecord->threadid = $thread->id;
        $assistantrecord->role = 'assistant';
        $assistantrecord->message = $cleanresponse;
        $assistantrecord->timecreated = time();
        $assistantrecord->id = $DB->insert_record('local_aichat_messages', $assistantrecord);

        // Store token usage.
        $tokenrecord = new \stdClass();
        $tokenrecord->messageid = $assistantrecord->id;
        $tokenrecord->deployment = $result['deployment'];
        $tokenrecord->prompt_tokens = $result['prompt_tokens'];
        $tokenrecord->completion_tokens = $result['completion_tokens'];
        $tokenrecord->total_tokens = $result['total_tokens'];
        $tokenrecord->timecreated = time();
        $DB->insert_record('local_aichat_token_usage', $tokenrecord);

        // Update thread timestamp.
        $DB->set_field('local_aichat_threads', 'timemodified', time(), ['id' => $thread->id]);

        // Update history summary if needed.
        \local_aichat\history_summarizer::update_summary_if_needed($thread->id);

        // Fire event.
        $event = \local_aichat\event\chat_message_sent::create([
            'context' => $context,
            'userid' => $USER->id,
            'other' => [
                'threadid' => $thread->id,
                'messagelength' => \core_text::strlen($usermsg),
                'prompt_tokens' => $result['prompt_tokens'],
                'completion_tokens' => $result['completion_tokens'],
            ],
        ]);
        $event->trigger();

        // Recalculate remaining messages.
        $remaining = $dailystatus['remaining'] > 0 ? $dailystatus['remaining'] - 1 : 0;

        return [
            'success' => true,
            'messageid' => (int) $assistantrecord->id,
            'response' => $cleanresponse,
            'suggestions' => $parsed['suggestions'],
            'remaining' => $remaining,
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'     => new external_value(PARAM_BOOL, 'Whether the request succeeded'),
            'messageid'   => new external_value(PARAM_INT, 'ID of the stored assistant message'),
            'response'    => new external_value(PARAM_RAW, 'AI response text'),
            'suggestions' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Suggested follow-up'),
                'Follow-up suggestions', VALUE_OPTIONAL
            ),
            'remaining'   => new external_value(PARAM_INT, 'Remaining daily messages'),
        ]);
    }
}
