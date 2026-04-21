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
 * AI Chat - Submit feedback external function
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
use core_external\external_value;

/**
 * Store thumbs up/down feedback for an assistant message.
 */
class submit_feedback extends external_api {

    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'messageid' => new external_value(PARAM_INT, 'Assistant message ID'),
            'feedback'  => new external_value(PARAM_INT, '1 = thumbs up, -1 = thumbs down'),
        ]);
    }

    /**
     * Submit feedback.
     *
     * @param int $messageid
     * @param int $feedback
     * @return array
     */
    public static function execute(int $messageid, int $feedback): array {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'messageid' => $messageid,
            'feedback'  => $feedback,
        ]);

        // Validate feedback value.
        if (!in_array($params['feedback'], [1, -1], true)) {
            throw new \invalid_parameter_exception('Feedback must be 1 or -1.');
        }

        // Verify the message exists and is an assistant message.
        $message = $DB->get_record('local_aichat_messages', ['id' => $params['messageid']], '*', MUST_EXIST);
        if ($message->role !== 'assistant') {
            throw new \invalid_parameter_exception('Feedback can only be given on assistant messages.');
        }

        // Verify the thread belongs to this user.
        $thread = $DB->get_record('local_aichat_threads', ['id' => $message->threadid], '*', MUST_EXIST);
        $context = \context_course::instance($thread->courseid);
        self::validate_context($context);
        require_capability('local/aichat:use', $context);

        if ((int) $thread->userid !== (int) $USER->id) {
            throw new \moodle_exception('nopermissions', 'error', '', 'submit feedback on another user\'s thread');
        }

        $now = time();

        // Upsert: update if exists, insert if not.
        $existing = $DB->get_record('local_aichat_feedback', [
            'messageid' => $params['messageid'],
            'userid'    => $USER->id,
        ]);

        if ($existing) {
            $existing->feedback = $params['feedback'];
            $DB->update_record('local_aichat_feedback', $existing);
        } else {
            $record = new \stdClass();
            $record->messageid   = $params['messageid'];
            $record->userid      = $USER->id;
            $record->feedback    = $params['feedback'];
            $record->timecreated = $now;
            $DB->insert_record('local_aichat_feedback', $record);
        }

        // Fire event.
        $feedbacklabel = $params['feedback'] === 1 ? 'up' : 'down';
        $event = \local_aichat\event\chat_feedback_given::create([
            'context' => $context,
            'userid'  => $USER->id,
            'other'   => [
                'messageid' => $params['messageid'],
                'feedback'  => $feedbacklabel,
            ],
        ]);
        $event->trigger();

        return ['success' => true];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the feedback was saved'),
        ]);
    }
}
