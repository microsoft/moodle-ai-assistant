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
 * AI Chat - Get history external function
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
 * Retrieve message history for the user's active thread in a course.
 */
class get_history extends external_api {

    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Get the message history.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/aichat:use', $context);

        // Find the user's active thread in this course.
        $thread = $DB->get_record('local_aichat_threads', [
            'userid'   => $USER->id,
            'courseid' => $params['courseid'],
        ]);

        if (!$thread) {
            return [
                'threadid' => 0,
                'messages' => [],
            ];
        }

        // Fetch all messages for this thread, ordered chronologically.
        $messages = $DB->get_records('local_aichat_messages', [
            'threadid' => $thread->id,
        ], 'timecreated ASC, id ASC');

        $result = [];
        foreach ($messages as $msg) {
            // Check for existing feedback on assistant messages.
            $feedback = 0;
            if ($msg->role === 'assistant') {
                $fb = $DB->get_record('local_aichat_feedback', [
                    'messageid' => $msg->id,
                    'userid'    => $USER->id,
                ]);
                if ($fb) {
                    $feedback = (int) $fb->feedback;
                }
            }

            $result[] = [
                'id'          => (int) $msg->id,
                'role'        => $msg->role,
                'message'     => $msg->message,
                'feedback'    => $feedback,
                'timecreated' => (int) $msg->timecreated,
            ];
        }

        return [
            'threadid' => (int) $thread->id,
            'messages' => $result,
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'threadid' => new external_value(PARAM_INT, 'Active thread ID, 0 if none'),
            'messages' => new external_multiple_structure(
                new external_single_structure([
                    'id'          => new external_value(PARAM_INT, 'Message ID'),
                    'role'        => new external_value(PARAM_ALPHA, 'user or assistant'),
                    'message'     => new external_value(PARAM_RAW, 'Message content'),
                    'feedback'    => new external_value(PARAM_INT, 'Feedback: 1=up, -1=down, 0=none'),
                    'timecreated' => new external_value(PARAM_INT, 'Unix timestamp'),
                ]),
                'Thread messages', VALUE_DEFAULT, []
            ),
        ]);
    }
}
