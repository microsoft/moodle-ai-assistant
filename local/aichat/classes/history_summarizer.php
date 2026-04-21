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
 * AI Chat - History summarizer
 *
 * Compresses older conversation history into a rolling summary.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages conversation history compression via rolling summaries.
 */
class history_summarizer {

    /**
     * Get the conversation context for an API call: summary of older + recent raw messages.
     *
     * @param int $threadid The thread ID.
     * @return array {summary: string|null, messages: \stdClass[]}
     */
    public static function get_context_history(int $threadid): array {
        global $DB;

        $window = (int) get_config('local_aichat', 'historywindow') ?: 5;

        // Load all messages ordered by time.
        $messages = $DB->get_records('local_aichat_messages', ['threadid' => $threadid],
            'timecreated ASC', 'id, role, message, timecreated');
        $messages = array_values($messages);

        $total = count($messages);
        if ($total <= $window) {
            // All messages fit in the raw window — no summary needed.
            return ['summary' => null, 'messages' => $messages];
        }

        // Split: older messages for summary, recent messages sent raw.
        $recentmessages = array_slice($messages, -$window);
        $oldermessages = array_slice($messages, 0, $total - $window);

        // Check if we already have a cached summary.
        $thread = $DB->get_record('local_aichat_threads', ['id' => $threadid], 'id, summary');
        if (!empty($thread->summary)) {
            // Check if summary is still current by counting older messages.
            // We'll update the summary incrementally if new messages rotated out.
            return ['summary' => $thread->summary, 'messages' => $recentmessages];
        }

        // Generate a fresh summary of the older messages.
        $summary = self::generate_summary($oldermessages);
        if (!empty($summary)) {
            $DB->set_field('local_aichat_threads', 'summary', $summary, ['id' => $threadid]);
        }

        return ['summary' => $summary, 'messages' => $recentmessages];
    }

    /**
     * Update the summary when new messages rotate out of the raw window.
     *
     * Called after storing a new message, when the raw window shifts.
     *
     * @param int $threadid The thread ID.
     */
    public static function update_summary_if_needed(int $threadid): void {
        global $DB;

        $window = (int) get_config('local_aichat', 'historywindow') ?: 5;

        $total = $DB->count_records('local_aichat_messages', ['threadid' => $threadid]);
        if ($total <= $window) {
            return; // No summary needed yet.
        }

        // Get the message(s) that just rotated out of the raw window.
        $oldercount = $total - $window;
        $oldermessages = $DB->get_records('local_aichat_messages', ['threadid' => $threadid],
            'timecreated ASC', 'id, role, message, timecreated', 0, $oldercount);
        $oldermessages = array_values($oldermessages);

        $thread = $DB->get_record('local_aichat_threads', ['id' => $threadid], 'id, summary');
        $currentsummary = $thread->summary ?? '';

        if (empty($currentsummary)) {
            // Generate first summary.
            $summary = self::generate_summary($oldermessages);
        } else {
            // Incrementally update: send current summary + the newly-rotated message(s).
            // Only the last 2 messages that rotated are used for incremental update.
            $newrotated = array_slice($oldermessages, -2);
            $summary = self::update_summary($currentsummary, $newrotated);
        }

        if (!empty($summary)) {
            $DB->set_field('local_aichat_threads', 'summary', $summary, ['id' => $threadid]);
        }
    }

    /**
     * Generate a summary of conversation messages using Azure OpenAI.
     *
     * @param array $messages Array of message objects (role, message).
     * @return string The summary text.
     */
    private static function generate_summary(array $messages): string {
        if (empty($messages)) {
            return '';
        }

        $transcript = '';
        foreach ($messages as $msg) {
            $role = ucfirst($msg->role);
            $transcript .= $role . ': ' . $msg->message . "\n";
        }

        $apimessages = [
            [
                'role' => 'system',
                'content' => 'Summarize the following conversation history in max 200 words, '
                           . 'preserving key topics, questions asked, and facts discussed. '
                           . 'Write in the same language as the conversation.'
            ],
            [
                'role' => 'user',
                'content' => $transcript,
            ],
        ];

        try {
            $result = azure_openai_client::complete($apimessages);
            return $result['response'];
        } catch (\Exception $e) {
            debugging('AI Chat: Summary generation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return '';
        }
    }

    /**
     * Incrementally update an existing summary with newly rotated messages.
     *
     * @param string $currentsummary The current rolling summary.
     * @param array $newmessages The newly rotated-out messages.
     * @return string The updated summary.
     */
    private static function update_summary(string $currentsummary, array $newmessages): string {
        $transcript = '';
        foreach ($newmessages as $msg) {
            $role = ucfirst($msg->role);
            $transcript .= $role . ': ' . $msg->message . "\n";
        }

        $apimessages = [
            [
                'role' => 'system',
                'content' => 'Update the following conversation summary to incorporate the new messages below. '
                           . 'Keep it under 200 words. Preserve key topics and facts. '
                           . 'Write in the same language as the conversation.'
            ],
            [
                'role' => 'user',
                'content' => "Current summary:\n" . $currentsummary
                           . "\n\nNew messages to incorporate:\n" . $transcript,
            ],
        ];

        try {
            $result = azure_openai_client::complete($apimessages);
            return $result['response'];
        } catch (\Exception $e) {
            debugging('AI Chat: Summary update failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $currentsummary; // Keep existing summary on failure.
        }
    }
}
