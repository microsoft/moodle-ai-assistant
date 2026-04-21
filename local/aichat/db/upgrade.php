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
 * AI Chat - Database upgrade steps.
 *
 * @package   local_aichat
 * @copyright 2026 Moodle AI Chat Contributors
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute local_aichat upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_aichat_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2024010105) {
        // Add deployment column to token_usage table.
        $table = new xmldb_table('local_aichat_token_usage');

        $field = new xmldb_field('deployment', XMLDB_TYPE_CHAR, '255', null,
            XMLDB_NOTNULL, null, '', 'messageid');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add index on deployment for aggregation queries.
        $index = new xmldb_index('ix_deployment', XMLDB_INDEX_NOTUNIQUE, ['deployment']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Remove cost-related config settings.
        unset_config('costperprompt', 'local_aichat');
        unset_config('costpercompletion', 'local_aichat');
        unset_config('costcurrency', 'local_aichat');

        upgrade_plugin_savepoint(true, 2024010105, 'local', 'aichat');
    }

    return true;
}
