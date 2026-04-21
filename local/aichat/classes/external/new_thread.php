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
 * AI Chat - New thread external function
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
 * Delete the previous thread (if any) and create a fresh thread with greeting.
 */
class new_thread extends external_api {

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
     * Create a new thread.
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

        $now = time();
        $cid = $params['courseid'];

        $transaction = $DB->start_delegated_transaction();

        try {
            // Delete previous thread and all related data.
            $oldthread = $DB->get_record('local_aichat_threads', [
                'userid'   => $USER->id,
                'courseid' => $cid,
            ]);

            if ($oldthread) {
                // Get all message IDs for cascade deletion.
                $messageids = $DB->get_fieldset_select('local_aichat_messages', 'id',
                    'threadid = ?', [$oldthread->id]);

                if (!empty($messageids)) {
                    list($insql, $inparams) = $DB->get_in_or_equal($messageids);
                    $DB->delete_records_select('local_aichat_feedback', "messageid $insql", $inparams);
                    $DB->delete_records_select('local_aichat_token_usage', "messageid $insql", $inparams);
                }

                $DB->delete_records('local_aichat_messages', ['threadid' => $oldthread->id]);
                $DB->delete_records('local_aichat_threads', ['id' => $oldthread->id]);
            }

            // Create new thread.
            $thread = new \stdClass();
            $thread->userid      = $USER->id;
            $thread->courseid    = $cid;
            $thread->title       = get_string('newchat', 'local_aichat');
            $thread->summary     = null;
            $thread->timecreated = $now;
            $thread->timemodified = $now;
            $thread->id = $DB->insert_record('local_aichat_threads', $thread);

            // Insert greeting as the first assistant message.
            $course  = get_course($cid);
            $greeting = get_string('greeting', 'local_aichat', (object) [
                'firstname'  => $USER->firstname,
                'coursename' => format_string($course->fullname),
            ]);

            $greetingmsg = new \stdClass();
            $greetingmsg->threadid    = $thread->id;
            $greetingmsg->role        = 'assistant';
            $greetingmsg->message     = $greeting;
            $greetingmsg->timecreated = $now;
            $greetingmsg->id = $DB->insert_record('local_aichat_messages', $greetingmsg);

            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
        }

        // Fire event.
        $event = \local_aichat\event\chat_thread_created::create([
            'context' => $context,
            'userid'  => $USER->id,
            'other'   => [
                'threadid' => $thread->id,
            ],
        ]);
        $event->trigger();

        return [
            'threadid'   => (int) $thread->id,
            'greeting'   => $greeting,
            'messageid'  => (int) $greetingmsg->id,
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'threadid'  => new external_value(PARAM_INT, 'New thread ID'),
            'greeting'  => new external_value(PARAM_RAW, 'Greeting message text'),
            'messageid' => new external_value(PARAM_INT, 'Greeting message ID'),
        ]);
    }
}
