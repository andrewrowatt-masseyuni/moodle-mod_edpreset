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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Upgrade steps for the activity preset provider.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute the upgrade steps from the given old version.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_edpreset_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026080200) {
        // Optional teacher-facing guidance: raw markdown on the curator's row, cleaned HTML on the
        // derived row. Both nullable - unlike description, guidance is optional.
        $table = new xmldb_table('edpreset_meta');
        $field = new xmldb_field('teacherguidance', XMLDB_TYPE_TEXT, null, null, null, null, null, 'description');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('edpreset_item');
        $field = new xmldb_field('teacherguidance', XMLDB_TYPE_TEXT, null, null, null, null, null, 'description');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026080200, 'edpreset');
    }

    if ($oldversion < 2026081300) {
        // Section templates. Both are section-level values denormalised onto every member row, the
        // same way category already is, so that the chooser page stays a single query.
        $table = new xmldb_table('edpreset_item');

        // No default: xmldb_field::setDefault() rejects '' on a CHAR NOT NULL column - it wants a
        // meaningful default or none - and silently rewrites it to null anyway. The column is still
        // NOT NULL; the persistent supplies '' for new records.
        $field = new xmldb_field('templatename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'category');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('templatesummary', XMLDB_TYPE_TEXT, null, null, null, null, null, 'templatename');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Existing rows carry neither, and nothing recomputes them until the template course is
        // rescanned - so ask for that rescan rather than leaving every preset looking like a
        // non-member until the nightly reconcile happens to run.
        \mod_edpreset\local\baker::queue_rebuild();

        upgrade_mod_savepoint(true, 2026081300, 'edpreset');
    }

    return true;
}
