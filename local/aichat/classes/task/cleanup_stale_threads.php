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
 * AI Chat - Cleanup stale threads scheduled task.
 *
 * Deletes threads with no user messages older than 30 days.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task to clean up stale threads.
 */
class cleanup_stale_threads extends \core\task\scheduled_task {

    /**
     * Return the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskcleanup', 'local_aichat');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB;

        $cutoff = time() - (30 * DAYSECS);

        // Find threads older than 30 days that have no user messages.
        $sql = "SELECT t.id
                  FROM {local_aichat_threads} t
                 WHERE t.timemodified < :cutoff
                   AND NOT EXISTS (
                       SELECT 1
                         FROM {local_aichat_messages} m
                        WHERE m.threadid = t.id
                          AND m.role = :role
                   )";

        $stale = $DB->get_records_sql($sql, [
            'cutoff' => $cutoff,
            'role'   => 'user',
        ]);

        if (empty($stale)) {
            mtrace('  No stale threads found.');
            return;
        }

        $count = 0;
        foreach ($stale as $thread) {
            $transaction = $DB->start_delegated_transaction();
            try {
                // Delete token usage for messages in this thread.
                $messages = $DB->get_records('local_aichat_messages', ['threadid' => $thread->id]);
                foreach ($messages as $msg) {
                    $DB->delete_records('local_aichat_token_usage', ['messageid' => $msg->id]);
                    $DB->delete_records('local_aichat_feedback', ['messageid' => $msg->id]);
                }
                $DB->delete_records('local_aichat_messages', ['threadid' => $thread->id]);
                $DB->delete_records('local_aichat_threads', ['id' => $thread->id]);
                $transaction->allow_commit();
                $count++;
            } catch (\Exception $e) {
                $transaction->rollback($e);
                mtrace('  Error cleaning thread ' . $thread->id . ': ' . $e->getMessage());
            }
        }

        mtrace("  Cleaned up {$count} stale threads.");
    }
}
