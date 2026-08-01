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

use mod_edpreset\meta;
use mod_edpreset\preset;

/**
 * Test generator for the activity preset provider.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_edpreset_generator extends testing_module_generator {
    /**
     * This module cannot be instantiated.
     *
     * @param array|stdClass|null $record Ignored.
     * @param array|null $options Ignored.
     * @return stdClass Never returns.
     * @throws coding_exception Always.
     */
    public function create_instance($record = null, ?array $options = null) {
        throw new coding_exception(
            'mod_edpreset is a content-item provider and is never added to a course. '
            . 'Use create_preset() or create_template_course() instead.'
        );
    }

    /**
     * Create a preset record.
     *
     * By default the preset is made "live" - given a backup file whose contenthash matches the
     * record - because that is what the chooser gates on. Pass 'live' => false for a preset that
     * exists but is not yet offered.
     *
     * @param array $record Overrides for the preset fields, plus the pseudo-fields 'live' and
     *                      'backupcontent'.
     * @return preset
     */
    public function create_preset(array $record = []): preset {
        static $counter = 0;
        $counter++;

        $live = $record['live'] ?? true;
        $backupcontent = $record['backupcontent'] ?? "not a real backup, preset $counter";
        unset($record['live'], $record['backupcontent']);

        $record += [
            'templatecourseid' => 0,
            'templatecmid' => -$counter,
            'modname' => 'assign',
            'instanceid' => $counter,
            'contextid' => \context_system::instance()->id,
            'title' => 'Test preset ' . $counter,
            'help' => 'Description of test preset ' . $counter,
            'category' => 'Test category',
            'sectionnum' => 1,
            'sortorder' => 1000 + $counter,
            'archetype' => MOD_ARCHETYPE_ASSIGNMENT,
            'purpose' => MOD_PURPOSE_ASSESSMENT,
            'status' => $live ? preset::STATUS_READY : preset::STATUS_PENDING,
        ];

        $preset = new preset(0, (object)$record);
        $preset->create();

        if ($live) {
            $this->attach_backup($preset, $backupcontent, preset::FILEAREA_BACKUP);
        }

        return $preset;
    }

    /**
     * Attach an archive to a preset and record its contenthash, making the preset live.
     *
     * @param preset $preset The preset.
     * @param string $content The file content.
     * @param string $filearea One of preset::FILEAREA_BACKUP or preset::FILEAREA_STAGING.
     * @return stored_file
     */
    public function attach_backup(preset $preset, string $content, string $filearea): stored_file {
        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'mod_edpreset',
            'filearea' => $filearea,
            'itemid' => $preset->get('id'),
            'filepath' => '/',
            'filename' => 'preset_' . $preset->get('id') . '.mbz',
        ], $content);

        if ($filearea === preset::FILEAREA_BACKUP) {
            $preset->set('backupcontenthash', $file->get_contenthash());
            $preset->set('backupfilesize', $file->get_filesize());
            $preset->set('backuptimebaked', time());
            $preset->set('timevalidated', time());
            $preset->update();
        }

        return $file;
    }

    /**
     * Record preset details against an activity.
     *
     * The presence of this row is what makes an activity a preset, and the settings form is the
     * only thing that writes one in production. create_module() does not run the form's post
     * actions, so tests that expect an exemplar to be scanned must call this.
     *
     * @param int $cmid The course module id.
     * @param array $fields Overrides for the metadata fields.
     * @return meta
     */
    public function create_metadata(int $cmid, array $fields = []): meta {
        static $counter = 0;
        $counter++;

        $fields += [
            'cmid' => $cmid,
            'presetname' => 'Test preset ' . $counter,
            'description' => 'Description of test preset ' . $counter,
            'tags' => '',
            // Deliberately empty by default: a non-empty value renames every copied activity, and
            // that should only happen in the tests that are about it.
            'defaultname' => '',
        ];

        $meta = new meta(0, (object)$fields);
        $meta->create();

        return $meta;
    }

    /**
     * Create a course laid out like a template course, and point the plugin at it.
     *
     * @param array $spec Section number => array of activity specs. Each spec takes 'modname',
     *                    an optional 'name', and an optional 'meta': an array of metadata field
     *                    overrides, or false for an activity with no preset details at all.
     *                    Section 0 is reserved for curator instructions and is never scanned.
     * @return stdClass The course.
     */
    public function create_template_course(array $spec = []): stdClass {
        $generator = $this->datagenerator;

        $numsections = $spec ? max(array_keys($spec)) : 1;
        $course = $generator->create_course(['numsections' => $numsections, 'format' => 'topics']);

        foreach ($spec as $sectionnum => $activities) {
            foreach ($activities as $activity) {
                $name = $activity['name'] ?? ucfirst($activity['modname']);
                $module = $generator->create_module($activity['modname'], [
                    'course' => $course->id,
                    'section' => $sectionnum,
                    'name' => $name,
                ]);

                $metafields = $activity['meta'] ?? [];
                if ($metafields === false) {
                    continue;
                }
                $this->create_metadata((int)$module->cmid, $metafields + ['presetname' => $name]);
            }
        }

        set_config('templatecourseid', $course->id, 'mod_edpreset');
        set_config('enabled', 1, 'mod_edpreset');

        return $course;
    }
}
