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
use mod_edpreset\meta;
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

            $sectiondata = self::section_data($course, $sectioninfo);
            $cmids = $modinfo->sections[$sectioninfo->section] ?? [];

            foreach ($cmids as $index => $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if (!self::cm_is_scannable($cm)) {
                    continue;
                }

                $sortorder = ($sectioninfo->section * 1000) + $index;
                $preset = self::upsert($course, $cm, $sectiondata, $sortorder);
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
        if (!self::modname_is_scannable($cm->modname)) {
            return false;
        }
        // The curator's preset details are what make an activity an exemplar. Without them there
        // is no name to show, nothing to describe it and no way to tell a curated exemplar from
        // something a colleague parked here, so it is not a preset.
        return meta::exists_for_cm((int)$cm->id);
    }

    /**
     * Whether a module type can become a preset at all.
     *
     * Split out from cm_is_scannable() so the settings form can decide whether to ask for preset
     * details before any course module exists.
     *
     * @param string $modname The module name, without the mod_ prefix.
     * @return bool
     */
    public static function modname_is_scannable(string $modname): bool {
        // A delegating module carries a whole section with it; core filters these out of the
        // chooser by component name, and ours is always mod_edpreset, so that filter would not
        // fire and we could end up nesting subsections.
        if (sectiondelegate::has_delegate_class('mod_' . $modname)) {
            return false;
        }
        // Without backup support there is no way to copy it at all.
        return (bool)plugin_supports('mod', $modname, FEATURE_BACKUP_MOODLE2, true);
    }

    /**
     * The section-level values every preset in a section carries.
     *
     * All three are denormalised onto each member row rather than held anywhere central, which is
     * what lets the chooser page build itself from a single query over the presets.
     *
     * @param stdClass $course The template course.
     * @param section_info $sectioninfo The section.
     * @return array{category: string, templatename: string, templatesummary: string}
     */
    protected static function section_data(stdClass $course, section_info $sectioninfo): array {
        // Deliberately the raw name: get_section_name() below is format_string()ed and falls back to
        // "Topic 3", neither of which should decide whether a section is a template.
        $istemplate = template::is_template_section_name($sectioninfo->name);

        return [
            'category' => get_section_name($course, $sectioninfo),
            'templatename' => $istemplate
                ? format_string(
                    template::strip_template_marker($sectioninfo->name),
                    true,
                    ['context' => \context_course::instance((int)$course->id)]
                )
                : '',
            'templatesummary' => $istemplate ? self::render_section_summary($sectioninfo) : '',
        ];
    }

    /**
     * Turn a template section's summary into the cleaned HTML shown on its card.
     *
     * Deliberately cleaned - noclean false - where core renders section summaries with noclean true
     * (see core_courseformat\output\local\content\section\summary::format_summary_text). Core's
     * summaries are only ever seen by people already in that course; this one is read by every
     * teacher on the site who opens the preset chooser, so it crosses the same trust boundary as a
     * preset description and gets the same treatment.
     *
     * Note that a summary embedding an uploaded file will render a broken link for anyone who cannot
     * access the template course: core serves that filearea through require_course_login(). Section
     * summaries used as template descriptions should be text.
     *
     * @param section_info $sectioninfo The template section.
     * @return string Cleaned HTML, or '' when the section has no summary.
     */
    protected static function render_section_summary(section_info $sectioninfo): string {
        $summary = (string)$sectioninfo->summary;
        if (trim($summary) === '') {
            return '';
        }

        $context = \context_course::instance((int)$sectioninfo->course);

        return format_text(
            file_rewrite_pluginfile_urls(
                $summary,
                'pluginfile.php',
                $context->id,
                'course',
                'section',
                $sectioninfo->id
            ),
            (int)$sectioninfo->summaryformat,
            ['context' => $context, 'noclean' => false]
        );
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
     * @param array $sectiondata As returned by section_data().
     * @param int $sortorder The display order.
     * @return preset
     */
    protected static function upsert(stdClass $course, cm_info $cm, array $sectiondata, int $sortorder): preset {
        // Guaranteed by cm_is_scannable(), which is the gate on getting here at all.
        $details = meta::get_for_cm((int)$cm->id);

        $preset = preset::get_record(['templatecmid' => $cm->id]) ?: new preset();

        $preset->set('templatecourseid', (int)$course->id);
        $preset->set('templatecmid', (int)$cm->id);
        $preset->set('modname', $cm->modname);
        $preset->set('instanceid', (int)$cm->instance);
        $preset->set('contextid', (int)$cm->context->id);
        // Deliberately the curator's preset name rather than $cm->name: an inline rename on the
        // course page fires course_module_updated without running the settings form's post
        // actions, so what teachers see must not be tied to what the exemplar happens to be
        // called this week.
        $preset->set('title', $details->get('presetname'));
        $preset->set('description', self::render_description($details));
        $preset->set('teacherguidance', self::render_guidance($details));
        $preset->set('tags', $details->get('tags'));
        $preset->set('defaultname', $details->get('defaultname'));
        $preset->set('category', $sectiondata['category']);
        $preset->set('templatename', $sectiondata['templatename']);
        $preset->set('templatesummary', $sectiondata['templatesummary']);
        $preset->set('sectionnum', (int)$cm->sectionnum);
        $preset->set('sortorder', $sortorder);
        $preset->set('archetype', (int)plugin_supports('mod', $cm->modname, FEATURE_MOD_ARCHETYPE, MOD_ARCHETYPE_OTHER));
        $preset->set('purpose', (string)plugin_supports('mod', $cm->modname, FEATURE_MOD_PURPOSE, MOD_PURPOSE_OTHER));
        $preset->set('branded', (bool)component_callback('mod_' . $cm->modname, 'is_branded', [], false));
        $preset->set('exemplartimemodified', self::exemplar_timemodified($cm));
        $preset->set('help', self::build_help($preset));

        if ($preset->get('id')) {
            $preset->update();
        } else {
            $preset->set('status', preset::STATUS_PENDING);
            $preset->create();
        }

        return $preset;
    }

    /**
     * Turn what the curator typed into the cleaned HTML shown to teachers.
     *
     * Cleaned here, at bake time, rather than at display time: this is the point at which the text
     * crosses out of the template course and becomes readable by everyone who can add an activity,
     * and both the chooser and the preset page render it unescaped.
     *
     * The format comes off the row rather than being assumed. It is FORMAT_HTML for anything typed
     * in the rich text editor, but a site running the plain textarea editor is still offered the
     * whole format menu, so the stored format is what decides.
     *
     * @param meta $details The curator's preset details.
     * @return string Cleaned HTML.
     */
    protected static function render_description(meta $details): string {
        return format_text(
            (string)$details->get('description'),
            (int)$details->get('descriptionformat'),
            ['context' => \context_system::instance(), 'noclean' => false]
        );
    }

    /**
     * Turn the curator's guidance into the cleaned HTML teachers are shown.
     *
     * Cleaned at bake time for the same reason as the description, and it matters more here: this
     * HTML is emitted unescaped into every course that has a note for this preset.
     *
     * Unlike the description, guidance is optional. Returning '' rather than letting format_text()
     * wrap nothing in a paragraph is what lets callers treat "has guidance" as a simple emptiness
     * test - emit_note() and mod_ednote both rely on that. The test is html_is_blank() rather than
     * trim() because a rich text editor that has been typed into and emptied again leaves "<p></p>"
     * behind. The settings form normalises that away before storing it, but the form is not the
     * only thing that writes these rows, so the guarantee is made here too.
     *
     * @param meta $details The curator's preset details.
     * @return string Cleaned HTML, or '' when the curator entered no guidance.
     */
    protected static function render_guidance(meta $details): string {
        $guidance = (string)$details->get('teacherguidance');
        if (html_is_blank($guidance)) {
            return '';
        }

        return format_text(
            $guidance,
            (int)$details->get('teacherguidanceformat'),
            ['context' => \context_system::instance(), 'noclean' => false]
        );
    }

    /**
     * Build the description the standard activity chooser shows in its info panel.
     *
     * The tags are prefixed rather than merely shown, because the chooser's search matches
     * descriptions as well as titles - so this is what makes a preset findable by tag there,
     * without any change to core.
     *
     * @param preset $preset The preset, with its description already set.
     * @return string Cleaned HTML.
     */
    protected static function build_help(preset $preset): string {
        $help = (string)$preset->get('description');

        $tags = (string)$preset->get('tags');
        if ($tags !== '') {
            $help = \html_writer::div(
                s($tags),
                'edpreset-help-tags'
            ) . $help;
        }

        return $help;
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

        foreach (
            [preset::FILEAREA_BACKUP, preset::FILEAREA_STAGING,
                  preset::FILEAREA_UNSCRUBBED] as $filearea
        ) {
            backup_baker::clear_area($preset, $filearea);
        }

        // Favourites and recommendations point at this preset id. \core_favourites has no bulk
        // delete for an arbitrary item, so this is done directly. The contextid is deliberately
        // not part of the criteria: the preset chooser page's stars live in each user's own
        // context, so there is a different one per user.
        $DB->delete_records_select(
            'favourite',
            "(component = :core AND itemtype IN (:chooseritem, :recommend) AND itemid = :itemid)
             OR (component = :ours AND itemtype = :ouritemtype AND itemid = :ouritemid)",
            [
                'core' => 'core_course',
                'chooseritem' => 'contentitem_mod_edpreset',
                'recommend' => 'recommend_mod_edpreset',
                'itemid' => $presetid,
                'ours' => preset::FAVOURITE_COMPONENT,
                'ouritemtype' => preset::FAVOURITE_ITEMTYPE,
                'ouritemid' => $presetid,
            ]
        );

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
            foreach (
                [preset::FILEAREA_BACKUP, preset::FILEAREA_STAGING,
                      preset::FILEAREA_UNSCRUBBED] as $filearea
            ) {
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
