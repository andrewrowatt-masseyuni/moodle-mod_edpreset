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

    if ($oldversion < 2026100203) {
        // Presets are no longer baked into stored archives ahead of time. Each copy now backs the
        // exemplar up and restores it in the same request, and a curator-set release status decides
        // what is offered. Everything the bake pipeline kept is dropped.
        $meta = new xmldb_table('edpreset_meta');
        $item = new xmldb_table('edpreset_item');

        // 1. The release status, defaulting to draft for anything a curator adds from now on.
        $field = new xmldb_field('status', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'draft', 'recommendedsection');
        if (!$dbman->field_exists($meta, $field)) {
            $dbman->add_field($meta, $field);
        }

        // Released only where the preset was actually being offered, i.e. had a validated archive
        // behind it, so the choosers show exactly the same presets after the upgrade as before. Done
        // before the archive fingerprint is dropped below, because that is what records it.
        if ($dbman->field_exists($item, new xmldb_field('backupcontenthash'))) {
            $DB->execute(
                "UPDATE {edpreset_meta}
                    SET status = :released
                  WHERE cmid IN (SELECT templatecmid FROM {edpreset_item} WHERE backupcontenthash IS NOT NULL)",
                ['released' => 'released']
            );
        }

        // 2. The pipeline columns, and the index over the old pipeline status.
        $index = new xmldb_index('status-enabled-sortorder', XMLDB_INDEX_NOTUNIQUE, ['status', 'enabled', 'sortorder']);
        if ($dbman->index_exists($item, $index)) {
            $dbman->drop_index($item, $index);
        }

        $dropped = [
            'status', 'statusdetail', 'datescleared', 'scrubbed', 'backupcontenthash', 'backupfilesize',
            'backuptimebaked', 'timevalidated', 'exemplartimemodified',
        ];
        foreach ($dropped as $name) {
            $field = new xmldb_field($name);
            if ($dbman->field_exists($item, $field)) {
                $dbman->drop_field($item, $field);
            }
        }

        // The same column name, now holding the release status copied from edpreset_meta.
        $fields = [
            new xmldb_field('status', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'draft', 'sortorder'),
            new xmldb_field('lasterror', XMLDB_TYPE_TEXT, null, null, null, null, null, 'status'),
            new xmldb_field('timelasterror', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'lasterror'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($item, $field)) {
                $dbman->add_field($item, $field);
            }
        }

        // Copied now rather than left to the rebuild queued below, which would leave every preset
        // out of the chooser until cron next ran.
        $DB->execute(
            "UPDATE {edpreset_item}
                SET status = (SELECT m.status FROM {edpreset_meta} m WHERE m.cmid = {edpreset_item}.templatecmid)
              WHERE templatecmid IN (SELECT cmid FROM {edpreset_meta})"
        );

        // 3. The stored archives. Nothing serves them and nothing reads them any more.
        $fs = get_file_storage();
        foreach (['presetunscrubbed', 'presetstaging', 'presetbackup'] as $filearea) {
            $fs->delete_area_files(context_system::instance()->id, 'mod_edpreset', $filearea);
        }

        // 4. Queued work for task classes that no longer exist.
        $DB->delete_records_list('task_adhoc', 'classname', [
            '\\mod_edpreset\\task\\bake_preset',
            '\\mod_edpreset\\task\\validate_preset',
        ]);

        // 5. Settings for the backup size cap and the restore test course. The course itself is left
        // where it is - it is hidden, and deleting a course is not something an upgrade should do -
        // and can be deleted by an administrator.
        foreach (['maxbackupsize', 'sandboxshortname', 'sandboxcategoryid'] as $name) {
            unset_config($name, 'mod_edpreset');
        }

        // 6. Refresh everything else the scan derives.
        \core\task\manager::queue_adhoc_task(new \mod_edpreset\task\rebuild_presets(), true);

        upgrade_mod_savepoint(true, 2026100203, 'edpreset');
    }

    if ($oldversion < 2026100204) {
        // Whether a preset goes into the standard activity chooser is now the curator's choice, made
        // per preset, rather than following from it sitting in section 1 of the template course.
        $meta = new xmldb_table('edpreset_meta');
        $field = new xmldb_field('showinchooser', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'status');
        if (!$dbman->field_exists($meta, $field)) {
            $dbman->add_field($meta, $field);
        }

        $item = new xmldb_table('edpreset_item');
        $field = new xmldb_field('showinchooser', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'status');
        if (!$dbman->field_exists($item, $field)) {
            $dbman->add_field($item, $field);
        }

        // Switched on for exactly the presets the old rule put in the chooser - those in section 1
        // that are not section template members - so teachers see no change from the upgrade.
        // Picked out in PHP rather than with templatename = '' in SQL, which Oracle reads as NULL.
        foreach ($DB->get_records('edpreset_item', ['sectionnum' => 1], '', 'id, templatecmid, templatename') as $row) {
            if (trim((string)$row->templatename) !== '') {
                continue;
            }
            $DB->set_field('edpreset_item', 'showinchooser', 1, ['id' => $row->id]);
            $DB->set_field('edpreset_meta', 'showinchooser', 1, ['cmid' => $row->templatecmid]);
        }

        upgrade_mod_savepoint(true, 2026100204, 'edpreset');
    }

    return true;
}
