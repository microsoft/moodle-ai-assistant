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
 * AI Chat - Re-index courses scheduled task.
 *
 * Re-indexes courses with embeddings older than 24 hours.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task to re-index course content for RAG.
 */
class reindex_courses extends \core\task\scheduled_task {

    /**
     * Return the task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskreindex', 'local_aichat');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB;

        $cutoff = time() - DAYSECS;

        // Find courses that have embeddings older than 24 hours.
        $sql = "SELECT DISTINCT courseid
                  FROM {local_aichat_embeddings}
                 WHERE timemodified < :cutoff";

        $courses = $DB->get_records_sql($sql, ['cutoff' => $cutoff]);

        if (empty($courses)) {
            mtrace('  No courses require re-indexing.');
            return;
        }

        foreach ($courses as $record) {
            try {
                mtrace("  Re-indexing course {$record->courseid}...");
                $stats = \local_aichat\rag\vector_store::index_course($record->courseid);
                mtrace("    Indexed: {$stats['indexed']}, Skipped: {$stats['skipped']}, " .
                       "Deleted: {$stats['deleted']}");
            } catch (\Exception $e) {
                mtrace("  Error re-indexing course {$record->courseid}: " . $e->getMessage());
            }
        }
    }
}
