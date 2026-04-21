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
 * AI Chat - Chat feedback given event.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aichat\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event fired when a user provides feedback (thumbs up/down) on an assistant message.
 */
class chat_feedback_given extends \core\event\base {

    /**
     * Initialise the event.
     */
    protected function init(): void {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Return the event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventchatfeedbackgiven', 'local_aichat');
    }

    /**
     * Return the event description.
     *
     * @return string
     */
    public function get_description(): string {
        $messageid = $this->other['messageid'] ?? 0;
        $feedback  = $this->other['feedback'] ?? 'unknown';
        return "The user with id '{$this->userid}' gave '{$feedback}' feedback " .
               "on message '{$messageid}' in course '{$this->courseid}'.";
    }

    /**
     * Get URL related to the event.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/course/view.php', ['id' => $this->courseid]);
    }
}
