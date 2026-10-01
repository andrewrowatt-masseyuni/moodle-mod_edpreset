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
 * Upgrade mod_edpreset.
 *
 * @param int $oldversion The version being upgraded from.
 * @return bool
 */
function xmldb_edpreset_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026092500) {
        // Teacher guidance moved out of this plugin. It is now embedded in the exemplar's own text
        // with local_edguidance, and travels into courses inside the activity's backup, so presets
        // no longer carry a guidance field of their own. Existing text is discarded, not migrated:
        // the old field predates any production use.
        $dropped = [
            'edpreset_meta' => ['teacherguidance', 'teacherguidanceformat'],
            'edpreset_item' => ['teacherguidance'],
        ];
        foreach ($dropped as $tablename => $fieldnames) {
            $table = new xmldb_table($tablename);
            foreach ($fieldnames as $fieldname) {
                $field = new xmldb_field($fieldname);
                if ($dbman->field_exists($table, $field)) {
                    $dbman->drop_field($table, $field);
                }
            }
        }

        upgrade_mod_savepoint(true, 2026092500, 'edpreset');
    }

    if ($oldversion < 2026100200) {
        // The section of a teacher's course a preset is meant for. Curator input on edpreset_meta,
        // denormalised onto edpreset_item by the next rebuild exactly as tags are. Existing rows
        // take '' - no recommendation - which is what the chooser already shows for them.
        foreach (['edpreset_meta', 'edpreset_item'] as $tablename) {
            $table = new xmldb_table($tablename);
            $field = new xmldb_field(
                'recommendedsection',
                XMLDB_TYPE_CHAR,
                '255',
                null,
                XMLDB_NOTNULL,
                null,
                null,
                'defaultname'
            );
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_mod_savepoint(true, 2026100200, 'edpreset');
    }

    if ($oldversion < 2026100201) {
        // Section templates marked [Template,restricted]. Existing rows take 0 until a rebuild sets
        // the flag from the section names.
        $table = new xmldb_table('edpreset_item');
        $field = new xmldb_field(
            'templaterestricted',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'templatename'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Queued rather than left to the nightly reconcile. A section a curator has already named
        // "[Template,restricted]" was not a template at all to the previous marker match, so its
        // activities are currently being offered one by one - which is exactly what the marker was
        // meant to prevent.
        \core\task\manager::queue_adhoc_task(new \mod_edpreset\task\rebuild_presets(), true);

        upgrade_mod_savepoint(true, 2026100201, 'edpreset');
    }

    if ($oldversion < 2026100202) {
        // The section summary used to be kept for template sections only, as their card's
        // description. Ordinary sections now show theirs under the group heading on the preset
        // chooser page, so the column holds every section's summary and is renamed to say so.
        $table = new xmldb_table('edpreset_item');
        $field = new xmldb_field('templatesummary', XMLDB_TYPE_TEXT, null, null, null, null, null, 'templaterestricted');
        if ($dbman->field_exists($table, $field)) {
            $dbman->rename_field($table, $field, 'sectionsummary');
        }

        // Template rows keep their summaries through the rename; ordinary sections have none stored
        // until the next rebuild, so queue one rather than wait for the nightly reconcile.
        \core\task\manager::queue_adhoc_task(new \mod_edpreset\task\rebuild_presets(), true);

        upgrade_mod_savepoint(true, 2026100202, 'edpreset');
    }

    return true;
}
