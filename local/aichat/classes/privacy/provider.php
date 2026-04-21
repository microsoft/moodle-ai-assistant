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
 * AI Chat - Privacy provider (GDPR compliance).
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy subsystem implementation for local_aichat.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Returns metadata about the user data stored by this plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_aichat_threads', [
            'userid'       => 'privacy:metadata:threads:userid',
            'courseid'     => 'privacy:metadata:threads:courseid',
            'title'        => 'privacy:metadata:threads:title',
            'timecreated'  => 'privacy:metadata:threads:timecreated',
            'timemodified' => 'privacy:metadata:threads:timemodified',
        ], 'privacy:metadata:threads');

        $collection->add_database_table('local_aichat_messages', [
            'threadid'    => 'privacy:metadata:messages:threadid',
            'role'        => 'privacy:metadata:messages:role',
            'message'     => 'privacy:metadata:messages:message',
            'timecreated' => 'privacy:metadata:messages:timecreated',
        ], 'privacy:metadata:messages');

        $collection->add_database_table('local_aichat_feedback', [
            'messageid'   => 'privacy:metadata:feedback:messageid',
            'userid'      => 'privacy:metadata:feedback:userid',
            'feedback'    => 'privacy:metadata:feedback:feedback',
            'comment'     => 'privacy:metadata:feedback:comment',
            'timecreated' => 'privacy:metadata:feedback:timecreated',
        ], 'privacy:metadata:feedback');

        $collection->add_external_location_link('azure_openai', [
            'message' => 'privacy:metadata:azure:message',
        ], 'privacy:metadata:azure');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT DISTINCT ctx.id
                  FROM {local_aichat_threads} t
                  JOIN {context} ctx ON ctx.instanceid = t.courseid AND ctx.contextlevel = :contextlevel
                 WHERE t.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'userid' => $userid,
            'contextlevel' => CONTEXT_COURSE,
        ]);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $sql = "SELECT DISTINCT t.userid
                  FROM {local_aichat_threads} t
                 WHERE t.courseid = :courseid";

        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
    }

    /**
     * Export all user data for the specified approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }

            $threads = $DB->get_records('local_aichat_threads', [
                'userid'   => $userid,
                'courseid' => $context->instanceid,
            ]);

            foreach ($threads as $thread) {
                $messages = $DB->get_records('local_aichat_messages',
                    ['threadid' => $thread->id], 'timecreated ASC');

                $exportedmsgs = [];
                foreach ($messages as $msg) {
                    $exportedmsg = [
                        'role'        => $msg->role,
                        'message'     => $msg->message,
                        'timecreated' => \core_privacy\local\request\transform::datetime($msg->timecreated),
                    ];

                    // Include feedback if present.
                    $feedback = $DB->get_record('local_aichat_feedback', [
                        'messageid' => $msg->id,
                        'userid'    => $userid,
                    ]);
                    if ($feedback) {
                        $exportedmsg['feedback'] = $feedback->feedback;
                        $exportedmsg['feedback_comment'] = $feedback->comment;
                    }

                    $exportedmsgs[] = $exportedmsg;
                }

                $threaddata = [
                    'title'        => $thread->title,
                    'timecreated'  => \core_privacy\local\request\transform::datetime($thread->timecreated),
                    'timemodified' => \core_privacy\local\request\transform::datetime($thread->timemodified),
                    'messages'     => $exportedmsgs,
                ];

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_aichat'), $thread->id],
                    (object) $threaddata
                );
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $threads = $DB->get_records('local_aichat_threads', ['courseid' => $context->instanceid]);
        foreach ($threads as $thread) {
            self::delete_thread_data($thread->id);
        }
        $DB->delete_records('local_aichat_threads', ['courseid' => $context->instanceid]);
    }

    /**
     * Delete all user data for the specified user in the given contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }

            $threads = $DB->get_records('local_aichat_threads', [
                'userid'   => $userid,
                'courseid' => $context->instanceid,
            ]);

            foreach ($threads as $thread) {
                self::delete_thread_data($thread->id);
            }

            $DB->delete_records('local_aichat_threads', [
                'userid'   => $userid,
                'courseid' => $context->instanceid,
            ]);
        }
    }

    /**
     * Delete all user data for the specified users in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        list($insql, $inparams) = $DB->get_in_or_equal($userids);
        $inparams[] = $context->instanceid;

        $threads = $DB->get_records_select('local_aichat_threads',
            "userid {$insql} AND courseid = ?", $inparams);

        foreach ($threads as $thread) {
            self::delete_thread_data($thread->id);
        }

        $DB->delete_records_select('local_aichat_threads',
            "userid {$insql} AND courseid = ?", $inparams);
    }

    /**
     * Delete all data associated with a thread (messages, feedback, token usage).
     *
     * @param int $threadid
     */
    private static function delete_thread_data(int $threadid): void {
        global $DB;

        $messages = $DB->get_records('local_aichat_messages', ['threadid' => $threadid]);
        foreach ($messages as $msg) {
            $DB->delete_records('local_aichat_token_usage', ['messageid' => $msg->id]);
            $DB->delete_records('local_aichat_feedback', ['messageid' => $msg->id]);
        }
        $DB->delete_records('local_aichat_messages', ['threadid' => $threadid]);
    }
}
