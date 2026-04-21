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
 * AI Chat - Save course settings external function
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
 * Save per-course feature toggles.
 */
class save_course_settings extends external_api {

    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'      => new external_value(PARAM_INT, 'Course ID'),
            'enable_export' => new external_value(PARAM_BOOL, 'Enable chat export'),
            'enable_upload' => new external_value(PARAM_BOOL, 'Enable file upload'),
        ]);
    }

    /**
     * Save course settings.
     *
     * @param int $courseid
     * @param bool $enableExport
     * @param bool $enableUpload
     * @return array
     */
    public static function execute(int $courseid, bool $enableExport, bool $enableUpload): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'      => $courseid,
            'enable_export' => $enableExport,
            'enable_upload' => $enableUpload,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/aichat:manage', $context);

        $now = time();
        $existing = $DB->get_record('local_aichat_course_settings', [
            'courseid' => $params['courseid'],
        ]);

        if ($existing) {
            $existing->enable_export = (int) $params['enable_export'];
            $existing->enable_upload = (int) $params['enable_upload'];
            $existing->timemodified  = $now;
            $DB->update_record('local_aichat_course_settings', $existing);
        } else {
            $record = new \stdClass();
            $record->courseid      = $params['courseid'];
            $record->enable_export = (int) $params['enable_export'];
            $record->enable_upload = (int) $params['enable_upload'];
            $record->timecreated   = $now;
            $record->timemodified  = $now;
            $DB->insert_record('local_aichat_course_settings', $record);
        }

        return ['success' => true];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the settings were saved'),
        ]);
    }
}
