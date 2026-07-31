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

namespace mod_edpreset\local;

use cm_info;
use core_courseformat\sectiondelegate;
use mod_edpreset\preset;
use mod_edpreset\task\bake_preset;
use mod_edpreset\task\rebuild_presets;
use mod_edpreset\task\validate_preset;
use section_info;
use stdClass;
use Throwable;

/**
 * Keeps the preset records in step with the exemplar activities in the template course.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class baker {

    /**
     * Rescan the template course: add, update and remove presets, and queue any needed bakes.
     *
     * @return array{scanned: int, queued: int, removed: int}
     */
    public static function rebuild(): array {
        $course = template::get();
        if (!$course) {
            return ['scanned' => 0, 'queued' => 0, 'removed' => 0];
        }

        $seen = [];
        $queued = 0;
        $sortorder = 0;

        $modinfo = get_fast_modinfo($course);
        foreach ($modinfo->get_section_info_all() as $sectioninfo) {
            if (!self::section_is_scannable($sectioninfo)) {
                continue;
            }

            $category = get_section_name($course, $sectioninfo);
            $cmids = $modinfo->sections[$sectioninfo->section] ?? [];

            foreach ($cmids as $index => $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if (!self::cm_is_scannable($cm)) {
                    continue;
                }

                $sortorder = ($sectioninfo->section * 1000) + $index;
                $preset = self::upsert($course, $cm, $category, $sortorder);
                $seen[] = (int)$preset->get('id');

                if (self::needs_baking($preset)) {
                    self::queue_bake((int)$cm->id);
                    $queued++;
                }
            }
        }

        $removed = self::remove_presets_except($course, $seen);

        return ['scanned' => count($seen), 'queued' => $queued, 'removed' => $removed];
    }

    /**
     * Whether a section should be scanned for exemplars.
     *
     * @param section_info $sectioninfo The section.
     * @return bool
     */
    protected static function section_is_scannable(section_info $sectioninfo): bool {
        // Section 0 is reserved for instructions to whoever curates the template course, so
        // anything put there is documentation rather than an exemplar.
        if ($sectioninfo->section < template::first_scanned_section()) {
            return false;
        }
        if (!$sectioninfo->visible) {
            return false;
        }
        // Sections owned by a delegating module (a subsection, say) belong to that module.
        return !$sectioninfo->is_delegated();
    }

    /**
     * Whether a course module should become a preset.
     *
     * @param cm_info $cm The course module.
     * @return bool
     */
    protected static function cm_is_scannable(cm_info $cm): bool {
        if (!$cm->visible || $cm->deletioninprogress) {
            return false;
        }
        // A delegating module carries a whole section with it; core filters these out of the
        // chooser by component name, and ours is always mod_edpreset, so that filter would not
        // fire and we could end up nesting subsections.
        if (sectiondelegate::has_delegate_class('mod_' . $cm->modname)) {
            return false;
        }
        // Without backup support there is no way to copy it at all.
        return (bool)plugin_supports('mod', $cm->modname, FEATURE_BACKUP_MOODLE2, true);
    }

    /**
     * Create or update the preset row for an exemplar.
     *
     * Rows are upserted on templatecmid, never deleted and reinserted, because user favourites and
     * recommendations key on the preset id. Reinserting on every rebuild would silently re-point
     * everyone's stars.
     *
     * @param stdClass $course The template course.
     * @param cm_info $cm The exemplar.
     * @param string $category The section name.
     * @param int $sortorder The display order.
     * @return preset
     */
    protected static function upsert(stdClass $course, cm_info $cm, string $category, int $sortorder): preset {
        $preset = preset::get_record(['templatecmid' => $cm->id]) ?: new preset();

        $preset->set('templatecourseid', (int)$course->id);
        $preset->set('templatecmid', (int)$cm->id);
        $preset->set('modname', $cm->modname);
        $preset->set('instanceid', (int)$cm->instance);
        $preset->set('contextid', (int)$cm->context->id);
        $preset->set('title', format_string($cm->name, true, ['context' => $cm->context]));
        $preset->set('category', $category);
        $preset->set('sectionnum', (int)$cm->sectionnum);
        $preset->set('sortorder', $sortorder);
        $preset->set('archetype', (int)plugin_supports('mod', $cm->modname, FEATURE_MOD_ARCHETYPE, MOD_ARCHETYPE_OTHER));
        $preset->set('purpose', (string)plugin_supports('mod', $cm->modname, FEATURE_MOD_PURPOSE, MOD_PURPOSE_OTHER));
        $preset->set('branded', (bool)component_callback('mod_' . $cm->modname, 'is_branded', [], false));
        $preset->set('exemplartimemodified', self::exemplar_timemodified($cm));

        if ($preset->get('id')) {
            $preset->update();
        } else {
            $preset->set('status', preset::STATUS_PENDING);
            $preset->create();
        }

        // Needs the preset id, so it has to follow the create().
        $preset->set('help', self::build_help($cm, $preset, $category));
        $preset->update();

        return $preset;
    }

    /**
     * Build the description shown in the chooser, and publish any images it needs.
     *
     * The exemplar's intro can contain images, but their URLs point into the template course, where
     * mod_<x>_pluginfile() requires enrolment - so every teacher would get a broken image. The
     * files are therefore copied to a system-context area of this plugin and the URLs rewritten to
     * point there. That is a deliberate publication decision: anything in an exemplar's description
     * becomes readable by everyone who can add an activity.
     *
     * @param cm_info $cm The exemplar.
     * @param preset $preset The preset.
     * @param string $category The section name.
     * @return string Cleaned HTML.
     */
    protected static function build_help(cm_info $cm, preset $preset, string $category): string {
        global $DB;

        $systemcontext = \context_system::instance();
        $presetid = (int)$preset->get('id');

        $intro = '';
        $introformat = FORMAT_HTML;
        if (plugin_supports('mod', $cm->modname, FEATURE_MOD_INTRO, true)) {
            $record = $DB->get_record($cm->modname, ['id' => $cm->instance], 'id, intro, introformat');
            if ($record) {
                $intro = (string)$record->intro;
                $introformat = (int)$record->introformat;
            }
        }

        self::copy_help_files($cm, $presetid);

        $intro = file_rewrite_pluginfile_urls(
            $intro,
            'pluginfile.php',
            $systemcontext->id,
            'mod_edpreset',
            preset::FILEAREA_HELP,
            $presetid
        );

        // Cleaned here, at bake time, because core exports the chooser's help text as PARAM_RAW and
        // the template renders it unescaped.
        $help = format_text($intro, $introformat, ['context' => $systemcontext, 'noclean' => false]);

        if (get_config('mod_edpreset', 'showcategoryinhelp') && $category !== '') {
            // Also makes the category searchable: the chooser's search matches descriptions as
            // well as titles.
            $help = \html_writer::div(
                get_string('category', 'mod_edpreset', $category),
                'edpreset-category'
            ) . $help;
        }

        return $help;
    }

    /**
     * Copy the exemplar's intro files into this plugin's publicly readable area.
     *
     * @param cm_info $cm The exemplar.
     * @param int $presetid The preset id.
     */
    protected static function copy_help_files(cm_info $cm, int $presetid): void {
        $fs = get_file_storage();
        $systemcontext = \context_system::instance();

        $fs->delete_area_files($systemcontext->id, 'mod_edpreset', preset::FILEAREA_HELP, $presetid);

        $files = $fs->get_area_files($cm->context->id, 'mod_' . $cm->modname, 'intro', 0, 'filename', false);
        foreach ($files as $file) {
            $fs->create_file_from_storedfile([
                'contextid' => $systemcontext->id,
                'component' => 'mod_edpreset',
                'filearea' => preset::FILEAREA_HELP,
                'itemid' => $presetid,
                'filepath' => $file->get_filepath(),
                'filename' => $file->get_filename(),
            ], $file);
        }
    }

    /**
     * A best-effort "when did this exemplar last change" stamp.
     *
     * @param cm_info $cm The exemplar.
     * @return int
     */
    protected static function exemplar_timemodified(cm_info $cm): int {
        global $DB;

        $columns = $DB->get_columns($cm->modname);
        $instancetime = 0;
        if (array_key_exists('timemodified', $columns)) {
            $instancetime = (int)$DB->get_field($cm->modname, 'timemodified', ['id' => $cm->instance]);
        }

        return max((int)$cm->added, $instancetime);
    }

    /**
     * Whether a preset still needs an archive produced for it.
     *
     * @param preset $preset The preset.
     * @return bool
     */
    protected static function needs_baking(preset $preset): bool {
        // A preset with no usable archive always needs one. A live preset is re-baked too, because
        // changes that matter - a rubric, an uploaded file, a quiz question - do not reliably bump
        // any timestamp we could compare against.
        return true;
    }

    /**
     * Delete presets whose exemplar is no longer in the template course.
     *
     * @param stdClass $course The template course.
     * @param int[] $keepids Preset ids to keep.
     * @return int How many were removed.
     */
    protected static function remove_presets_except(stdClass $course, array $keepids): int {
        $removed = 0;
        foreach (preset::get_records(['templatecourseid' => (int)$course->id]) as $preset) {
            if (in_array((int)$preset->get('id'), $keepids, true)) {
                continue;
            }
            self::delete_preset($preset);
            $removed++;
        }
        return $removed;
    }

    /**
     * Remove a preset and everything that belongs to it.
     *
     * @param preset $preset The preset.
     */
    public static function delete_preset(preset $preset): void {
        global $DB;

        $presetid = (int)$preset->get('id');

        foreach ([preset::FILEAREA_BACKUP, preset::FILEAREA_STAGING,
                  preset::FILEAREA_UNSCRUBBED, preset::FILEAREA_HELP] as $filearea) {
            backup_baker::clear_area($preset, $filearea);
        }

        // Favourites and recommendations point at this preset id. \core_favourites has no bulk
        // delete for an arbitrary item, so this is done directly.
        foreach (['contentitem_mod_edpreset', 'recommend_mod_edpreset'] as $itemtype) {
            $DB->delete_records('favourite', [
                'component' => 'core_course',
                'itemtype' => $itemtype,
                'itemid' => $presetid,
            ]);
        }

        $preset->delete();
    }

    /**
     * Produce the archive for one exemplar and hand it to validation.
     *
     * @param int $cmid The exemplar's course module id.
     * @return bool True if an archive was staged.
     */
    public static function bake_one(int $cmid): bool {
        $preset = preset::get_record(['templatecmid' => $cmid]);
        if (!$preset) {
            return false;
        }

        $preset->set('status', preset::STATUS_BAKING);
        $preset->set('statusdetail', '');
        $preset->update();

        try {
            backup_baker::bake($preset);
        } catch (Throwable $e) {
            $preset->set('status', preset::STATUS_FAILED);
            $preset->set('statusdetail', get_class($e) . ': ' . $e->getMessage());
            $preset->update();
            return false;
        }

        $preset->set('status', preset::STATUS_VALIDATING);
        $preset->update();

        self::queue_validate((int)$preset->get('id'));

        return true;
    }

    /**
     * Mark a preset as awaiting a fresh archive and queue the work.
     *
     * The live archive is deliberately left alone, so the preset keeps working in the chooser until
     * its replacement has been proven.
     *
     * @param int $cmid The exemplar's course module id.
     */
    public static function mark_stale(int $cmid): void {
        $preset = preset::get_record(['templatecmid' => $cmid]);
        if ($preset) {
            $preset->set('status', preset::STATUS_PENDING);
            $preset->update();
        }
        self::queue_bake($cmid);
    }

    /**
     * Queue a bake for one exemplar.
     *
     * @param int $cmid The exemplar's course module id.
     */
    public static function queue_bake(int $cmid): void {
        $task = new bake_preset();
        $task->set_custom_data(['cmid' => $cmid]);
        // De-duplicates, so saving an exemplar ten times in a row queues one bake.
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Queue a validation for one preset.
     *
     * @param int $presetid The preset id.
     */
    public static function queue_validate(int $presetid): void {
        $task = new validate_preset();
        $task->set_custom_data(['presetid' => $presetid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Queue a full rescan of the template course.
     */
    public static function queue_rebuild(): void {
        \core\task\manager::queue_adhoc_task(new rebuild_presets(), true);
    }

    /**
     * Throw away every stored archive and rebuild from scratch.
     *
     * Presets leave the chooser immediately and return one at a time, each as its own replacement
     * archive is baked and proven.
     *
     * @return int How many presets were reset.
     */
    public static function clear_cache(): int {
        $count = 0;
        foreach (preset::get_records() as $preset) {
            foreach ([preset::FILEAREA_BACKUP, preset::FILEAREA_STAGING,
                      preset::FILEAREA_UNSCRUBBED] as $filearea) {
                backup_baker::clear_area($preset, $filearea);
            }
            $preset->set('backupcontenthash', null);
            $preset->set('backupfilesize', 0);
            $preset->set('status', preset::STATUS_PENDING);
            $preset->set('statusdetail', '');
            $preset->update();
            $count++;
        }

        self::queue_rebuild();

        return $count;
    }
}
