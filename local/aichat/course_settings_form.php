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
 * AI Chat - Per-course settings form.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Course settings form for local_aichat.
 */
class local_aichat_course_settings_form extends moodleform {

    /**
     * Form definition.
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('header', 'generalhdr', get_string('coursesettings', 'local_aichat'));

        $mform->addElement('advcheckbox', 'enable_export',
            get_string('enableexport', 'local_aichat'),
            get_string('enableexport_desc', 'local_aichat'));
        $mform->setDefault('enable_export', 1);

        $mform->addElement('advcheckbox', 'enable_upload',
            get_string('enableupload', 'local_aichat'),
            get_string('enableupload_desc', 'local_aichat'));
        $mform->setDefault('enable_upload', 0);

        $this->add_action_buttons();
    }
}
