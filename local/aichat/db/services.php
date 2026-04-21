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
 * AI Chat - External web service definitions
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [

    // Send a user message and get an AI response (non-streaming fallback).
    'local_aichat_send_message' => [
        'classname'     => 'local_aichat\external\send_message',
        'methodname'    => 'execute',
        'description'   => 'Send a user message within the active thread and return the AI response.',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'local/aichat:use',
        'loginrequired' => true,
    ],

    // Retrieve message history for the active thread in a course.
    'local_aichat_get_history' => [
        'classname'     => 'local_aichat\external\get_history',
        'methodname'    => 'execute',
        'description'   => 'Retrieve messages for the user\'s active thread in a course.',
        'type'          => 'read',
        'ajax'          => true,
        'capabilities'  => 'local/aichat:use',
        'loginrequired' => true,
    ],

    // Create a new thread (deletes previous if any).
    'local_aichat_new_thread' => [
        'classname'     => 'local_aichat\external\new_thread',
        'methodname'    => 'execute',
        'description'   => 'Delete the previous thread (if any) and create a fresh thread with greeting.',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'local/aichat:use',
        'loginrequired' => true,
    ],

    // Submit thumbs up/down feedback on an assistant message.
    'local_aichat_submit_feedback' => [
        'classname'     => 'local_aichat\external\submit_feedback',
        'methodname'    => 'execute',
        'description'   => 'Store thumbs up/down feedback for an assistant message.',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'local/aichat:use',
        'loginrequired' => true,
    ],

    // Get per-course feature toggles (export, upload).
    'local_aichat_get_course_settings' => [
        'classname'     => 'local_aichat\external\get_course_settings',
        'methodname'    => 'execute',
        'description'   => 'Return per-course feature toggles (export, upload).',
        'type'          => 'read',
        'ajax'          => true,
        'capabilities'  => 'local/aichat:use',
        'loginrequired' => true,
    ],

    // Save per-course feature toggles.
    'local_aichat_save_course_settings' => [
        'classname'     => 'local_aichat\external\save_course_settings',
        'methodname'    => 'execute',
        'description'   => 'Save per-course feature toggles (export, upload).',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'local/aichat:manage',
        'loginrequired' => true,
    ],

    // Trigger RAG re-indexing for a course.
    'local_aichat_rebuild_index' => [
        'classname'     => 'local_aichat\external\rebuild_index',
        'methodname'    => 'execute',
        'description'   => 'Trigger RAG content re-indexing for a course.',
        'type'          => 'write',
        'ajax'          => true,
        'capabilities'  => 'local/aichat:manage',
        'loginrequired' => true,
    ],
];
