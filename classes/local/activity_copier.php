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

use backup;
use cm_info;
use core\progress\base as progress_base;
use mod_edpreset\preset;
use moodle_exception;
use restore_controller;
use stdClass;
use stored_file;

/**
 * Restores a single activity archive into a course.
 *
 * This is the cross-course equivalent of core's duplicate_module(), which cannot be reused: its
 * backup/restore core would work, but everything after the restore is hardcoded to the *source*
 * course - get_coursemodule_from_id(..., $cm->course), the course_sections lookup filtered on
 * $cm->course, and get_fast_modinfo($cm->course) - so it silently fails when the target course is
 * a different one.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_copier {
    /**
     * The module that carries a preset's teacher guidance into a course.
     *
     * A note is chrome belonging to the activity below it rather than an item in its own right, so
     * everything that counts or orders a section's contents has to know to treat it as such.
     *
     * @var string
     */
    public const NOTE_MODNAME = 'ednote';

    /**
     * Restore an activity archive into a course and place it.
     *
     * Deliberately shared with the validation pass (see validator), so that the test restore
     * exercises exactly the code path a teacher's click takes rather than a parallel one.
     *
     * The restore runs as $userid in MODE_IMPORT, which requires moodle/restore:restoretargetimport
     * in the target course. Editing teachers hold that by default. Note that the *backup* side has
     * the opposite requirement - moodle/backup:backuptargetimport in the source course - which
     * teachers do not hold for the template course, and which is why archives are baked ahead of
     * time by an admin rather than produced on demand.
     *
     * @param stored_file $mbz The activity archive.
     * @param stdClass $course The target course.
     * @param int $sectionnum The section number to place the activity in.
     * @param int $beforemod Course module id to insert before, or 0 to append.
     * @param int $userid The user to run the restore as.
     * @param progress_base|null $progress Optional progress reporter.
     * @return int The new course module id.
     * @throws moodle_exception If the restore fails its precheck or produces no activity.
     */
    public static function restore_into(
        stored_file $mbz,
        stdClass $course,
        int $sectionnum,
        int $beforemod = 0,
        int $userid = 0,
        ?progress_base $progress = null
    ): int {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $userid = $userid ?: $USER->id;
        $coursecontext = \context_course::instance($course->id);

        $tempdir = restore_controller::get_tempdir_name($coursecontext->id, $userid);
        $fulltempdir = make_backup_temp_directory($tempdir);
        get_file_packer('application/vnd.moodle.backup')->extract_to_pathname($mbz, $fulltempdir);

        $rc = null;
        try {
            $rc = new restore_controller(
                $tempdir,
                $course->id,
                backup::INTERACTIVE_NO,
                backup::MODE_IMPORT,
                $userid,
                backup::TARGET_CURRENT_ADDING
            );

            if ($progress) {
                $rc->set_progress($progress);
            }

            self::disable_user_data_settings($rc);

            if (!$rc->execute_precheck()) {
                $results = $rc->get_precheck_results();
                if (!empty($results['errors'])) {
                    throw new moodle_exception(
                        'restoreprecheckfailed',
                        'mod_edpreset',
                        '',
                        implode('; ', $results['errors'])
                    );
                }
            }

            $rc->execute_plan();
            // Before dispose() below, which empties the plan's task list and takes the only record
            // of the new course module id with it.
            $newcmid = self::find_restored_cmid($rc);
        } finally {
            self::dispose($rc);
            if (empty($CFG->keeptempdirectoriesonbackup)) {
                fulldelete($fulltempdir);
            }
        }

        self::place($course, $newcmid, $sectionnum, $beforemod);

        // The restored activity carries the exemplar's idnumber, availability rules and expected
        // completion date. An idnumber must be unique within a course, availability references
        // ids that only mean something in the template course, and a date copied from an exemplar
        // is never the date the teacher wants.
        $DB->set_field('course_modules', 'idnumber', '', ['id' => $newcmid]);
        $DB->set_field('course_modules', 'availability', null, ['id' => $newcmid]);
        $DB->set_field('course_modules', 'completionexpected', 0, ['id' => $newcmid]);

        rebuild_course_cache($course->id, true);

        $newcm = get_coursemodule_from_id('', $newcmid, $course->id, false, MUST_EXIST);
        course_module_update_calendar_events($newcm->modname, null, $newcm);

        // The restore subsystem does not fire course_module_created - which is exactly why core's
        // duplicate_module() triggers it by hand. Without this, completion, competencies and any
        // third-party observers never learn the activity exists.
        $cminfo = get_fast_modinfo($course->id)->get_cm($newcmid);
        \core\event\course_module_created::create_from_cm($cminfo)->trigger();

        return $newcmid;
    }

    /**
     * Copy a preset into a course.
     *
     * @param preset $preset The preset to copy.
     * @param stdClass $course The target course.
     * @param int $sectionnum The section number to place the activity in.
     * @param int $beforemod Course module id to insert before, or 0 to append.
     * @param progress_base|null $progress Optional progress reporter.
     * @return cm_info The new course module.
     * @throws moodle_exception If the preset has no usable archive.
     */
    public static function copy(
        preset $preset,
        stdClass $course,
        int $sectionnum,
        int $beforemod = 0,
        ?progress_base $progress = null
    ): cm_info {
        return self::copy_with_note($preset, $course, $sectionnum, $beforemod, $progress)['cm'];
    }

    /**
     * Copy a preset into a course, reporting the teacher note it produced as well as the activity.
     *
     * The note's course module id matters to anything that reorders the section afterwards: a note
     * belongs immediately above the activity it describes, and only the code that created the pair
     * knows which note goes with which activity.
     *
     * @param preset $preset The preset to copy.
     * @param stdClass $course The target course.
     * @param int $sectionnum The section number to place the activity in.
     * @param int $beforemod Course module id to insert before, or 0 to append.
     * @param progress_base|null $progress Optional progress reporter.
     * @return array{cm: cm_info, notecmid: ?int} The new course module, and its note if it got one.
     * @throws moodle_exception If the preset has no usable archive.
     */
    protected static function copy_with_note(
        preset $preset,
        stdClass $course,
        int $sectionnum,
        int $beforemod = 0,
        ?progress_base $progress = null
    ): array {
        global $CFG;

        // Holds moveto_module() and set_coursemodule_name(), and is not part of the standard
        // bootstrap - so an external function reaching here has nothing loaded.
        require_once($CFG->dirroot . '/course/lib.php');

        if (!$preset->is_live()) {
            throw new moodle_exception('backupstale', 'mod_edpreset');
        }

        $newcmid = self::restore_into(
            $preset->get_live_file(),
            $course,
            $sectionnum,
            $beforemod,
            0,
            $progress
        );

        // Before the modinfo read below, not after: set_coursemodule_name() purges and rebuilds
        // the course cache, so a rename afterwards would leave the returned cm_info holding the
        // exemplar's name - which is exactly what the caller displays.
        $defaultname = trim((string)$preset->get('defaultname'));
        if ($defaultname !== '') {
            set_coursemodule_name($newcmid, $defaultname);
        }

        // Here rather than in restore_into(), which the validator's test restore also goes through:
        // the sandbox course must not collect a teacher note on every validation pass.
        $notecmid = self::emit_note($preset, $course, $sectionnum, $newcmid);

        return [
            'cm' => get_fast_modinfo($course->id)->get_cm($newcmid),
            'notecmid' => $notecmid,
        ];
    }

    /**
     * Copy several presets into a course, one after another.
     *
     * One preset or ten take the same route: this is the whole of what a teacher's click does,
     * whether it came from the activity chooser or from a batch selection on the chooser page.
     *
     * A preset that fails does not take the rest down with it. Nothing wraps a restore in a
     * transaction - core does not either - so whatever has already been copied is really in the
     * course by the time a later one throws, and abandoning the remaining presets would only add a
     * second kind of partial result. The caller gets both lists and reports them.
     *
     * The presets are all copied with the same $beforemod, which is what keeps them in selection
     * order: course_add_cm_to_section() splices each new module in immediately before $beforemod,
     * so successive copies land after each other rather than stacking up in reverse.
     *
     * @param preset[] $presets The presets to copy, in the order they should appear.
     * @param stdClass $course The target course.
     * @param int $sectionnum The section number to place the activities in.
     * @param int $beforemod Course module id to insert before, or 0 to append.
     * @param progress_base|null $progress Optional progress reporter, shared by every copy.
     * @return array{added: cm_info[], failed: string[], placed: array<int, array{cmid: int, notecmid: ?int}>}
     *               The new course modules, the titles of the presets that could not be copied, and
     *               where each preset that succeeded ended up, keyed by preset id.
     */
    public static function copy_many(
        array $presets,
        stdClass $course,
        int $sectionnum,
        int $beforemod = 0,
        ?progress_base $progress = null
    ): array {
        $added = [];
        $failed = [];
        $placed = [];

        foreach ($presets as $preset) {
            try {
                $result = self::copy_with_note($preset, $course, $sectionnum, $beforemod, $progress);
                $added[] = $result['cm'];
                $placed[(int)$preset->get('id')] = [
                    'cmid' => (int)$result['cm']->id,
                    'notecmid' => $result['notecmid'],
                ];
            } catch (\Throwable $e) {
                // The teacher is told which preset failed, but not why - the reasons are restore
                // internals. Keep the real one where an administrator can find it.
                $failed[] = $preset->get('title');
                debugging(
                    'mod_edpreset: could not copy preset ' . $preset->get('id') . ': ' . $e->getMessage(),
                    DEBUG_NORMAL
                );
            }
        }

        return ['added' => $added, 'failed' => $failed, 'placed' => $placed];
    }

    /**
     * Copy a whole section template into a course, optionally interleaving it with what is there.
     *
     * The section is snapshotted before anything is copied, because working out which teacher note
     * belongs to which pre-existing activity means reading pairs that are still adjacent - and the
     * copy itself can splice new modules between them.
     *
     * @param section_template $template The template to copy.
     * @param stdClass $course The target course.
     * @param int $sectionnum The section number to copy into.
     * @param string[] $order The teacher's chosen order as p<presetid>/c<cmid> tokens. Empty to
     *                        leave the section in whatever order the copy produced.
     * @param int $beforemod Course module id to insert before, or 0 to append.
     * @param progress_base|null $progress Optional progress reporter.
     * @return array{added: cm_info[], failed: string[]}
     */
    public static function copy_template(
        section_template $template,
        stdClass $course,
        int $sectionnum,
        array $order = [],
        int $beforemod = 0,
        ?progress_base $progress = null
    ): array {
        $before = self::section_cmids($course, $sectionnum);

        $result = self::copy_many($template->get_members(), $course, $sectionnum, $beforemod, $progress);

        if ($order && $result['added']) {
            self::reorder_section(
                $course,
                $sectionnum,
                self::expand_order($course, $sectionnum, $order, $result['placed'], $before)
            );
        }

        return ['added' => $result['added'], 'failed' => $result['failed']];
    }

    /**
     * Turn the teacher's chosen order into the full run of course modules the section should hold.
     *
     * Teacher notes are never offered to the teacher to arrange - a note is chrome belonging to one
     * activity, and letting it be dragged away from that activity would only produce orphans - so
     * they are re-attached here instead: each note is emitted immediately above its own activity.
     *
     * Anything in the section that the order does not mention is appended, keeping its relative
     * order. That is what implements "activities the teacher left alone end up below the template",
     * and it doubles as the safety net that stops a hand-edited order from losing an activity.
     *
     * @param stdClass $course The target course.
     * @param int $sectionnum The section being reordered.
     * @param string[] $order The p<presetid>/c<cmid> tokens, in the order asked for.
     * @param array $placed Where each preset landed, keyed by preset id: cmid and notecmid.
     * @param int[] $before The section's course module ids as they were before the copy.
     * @return int[] Course module ids, in the order the section should hold them.
     */
    protected static function expand_order(
        stdClass $course,
        int $sectionnum,
        array $order,
        array $placed,
        array $before
    ): array {
        $current = self::section_cmids($course, $sectionnum);
        $noteof = self::note_pairs($course, $before, $placed);

        $sequence = [];
        $emitted = [];

        // The teacher's order first, then everything the order did not mention - which is both how
        // untouched activities end up below the template, and the safety net that stops a stale or
        // hand-edited order from losing one.
        foreach (array_merge(self::resolve_order($order, $placed, $current), $current) as $cmid) {
            foreach ([$noteof[$cmid] ?? 0, $cmid] as $each) {
                if ($each && !isset($emitted[$each])) {
                    $emitted[$each] = true;
                    $sequence[] = $each;
                }
            }
        }

        return $sequence;
    }

    /**
     * Which teacher note belongs above which activity.
     *
     * Two sources. Notes this copy just made are known exactly, because the copy reported them.
     * Notes that were already in the section have to be inferred, and the inference is only sound
     * against the section as it was *before* the copy - which is why the caller snapshots it.
     *
     * @param stdClass $course The target course.
     * @param int[] $before The section's course module ids as they were before the copy.
     * @param array $placed Where each preset landed, keyed by preset id: cmid and notecmid.
     * @return array Activity course module id => the course module id of its note.
     */
    protected static function note_pairs(stdClass $course, array $before, array $placed): array {
        $modinfo = get_fast_modinfo($course->id);
        $cms = $modinfo->get_cms();

        $noteof = [];
        foreach ($before as $index => $cmid) {
            $next = $before[$index + 1] ?? 0;
            if (!isset($cms[$cmid], $cms[$next])) {
                continue;
            }
            // A note describes the activity immediately below it. A note with another note below it
            // describes nothing, and is left to be appended wherever it falls.
            if ($cms[$cmid]->modname === self::NOTE_MODNAME && $cms[$next]->modname !== self::NOTE_MODNAME) {
                $noteof[$next] = $cmid;
            }
        }

        foreach ($placed as $placement) {
            if ($placement['notecmid']) {
                $noteof[$placement['cmid']] = $placement['notecmid'];
            }
        }

        return $noteof;
    }

    /**
     * Turn the order tokens into the course module ids they name.
     *
     * Tokens that no longer mean anything are dropped rather than raised: the list was assembled in
     * the browser and can go stale between the dialogue opening and the form arriving - a preset that
     * failed to restore, an activity someone else deleted - and neither is worth failing the add for.
     *
     * @param string[] $order The p<presetid>/c<cmid> tokens, in the order asked for.
     * @param array $placed Where each preset landed, keyed by preset id: cmid and notecmid.
     * @param int[] $current The course module ids the section holds now.
     * @return int[]
     */
    protected static function resolve_order(array $order, array $placed, array $current): array {
        $cmids = [];
        foreach ($order as $token) {
            $id = (int)substr($token, 1);
            $cmid = match ($id ? $token[0] : '') {
                'p' => (int)($placed[$id]['cmid'] ?? 0),
                'c' => $id,
                default => 0,
            };

            if ($cmid && in_array($cmid, $current, true)) {
                $cmids[] = $cmid;
            }
        }

        return $cmids;
    }

    /**
     * Rearrange a section so its activities appear in the given order.
     *
     * There is no supported way to write a section's sequence in one go - both course_update_section()
     * and \core_courseformat\local\sectionactions::update() strip the field out - so this replays
     * core's own idiom from \core_courseformat\stateactions::cm_move(): walk the wanted order
     * backwards, moving each module in front of the one placed just after it.
     *
     * The modinfo has to be re-read every iteration because each move rebuilds the course cache.
     *
     * @param stdClass $course The course.
     * @param int $sectionnum The section to rearrange.
     * @param int[] $cmids The course module ids, in the order the section should hold them.
     */
    public static function reorder_section(stdClass $course, int $sectionnum, array $cmids): void {
        global $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        $beforecmid = 0;
        foreach (array_reverse($cmids) as $cmid) {
            $modinfo = get_fast_modinfo($course->id);

            $section = $modinfo->get_section_info($sectionnum);
            if (!$section || !isset($modinfo->get_cms()[$cmid])) {
                continue;
            }

            // Already in place. Skipping matters for more than speed: moving a module in front of
            // itself would delete it from the section and re-append it.
            if ($beforecmid === $cmid) {
                continue;
            }

            moveto_module($modinfo->get_cm($cmid), $section, $beforecmid ?: null);
            $beforecmid = $cmid;
        }

        rebuild_course_cache($course->id, true);
    }

    /**
     * The course module ids a section holds, in order.
     *
     * @param stdClass $course The course.
     * @param int $sectionnum The section number.
     * @return int[]
     */
    public static function section_cmids(stdClass $course, int $sectionnum): array {
        $modinfo = get_fast_modinfo($course->id);

        return array_map('intval', $modinfo->sections[$sectionnum] ?? []);
    }

    /**
     * Add a teacher note above a newly copied activity, if the preset has guidance to show.
     *
     * The note carries the preset id rather than the guidance itself, so that later edits in the
     * template course reach every course that has already added this preset. The guidance is also
     * written into the note's own body as a fallback, for the case where mod_ednote outlives
     * mod_edpreset or the preset is deleted.
     *
     * Silently does nothing when mod_ednote is not available. That is the whole reason this plugin
     * declares no dependency on it: a site can run mod_edpreset alone and simply not get notes.
     *
     * @param preset $preset The preset being copied.
     * @param stdClass $course The target course.
     * @param int $sectionnum The section the activity was placed in.
     * @param int $activitycmid The course module id of the activity the note belongs above.
     * @return int|null The note's course module id, or null if no note was added.
     */
    protected static function emit_note(preset $preset, stdClass $course, int $sectionnum, int $activitycmid): ?int {
        global $CFG, $DB;

        $guidance = trim((string)$preset->get('teacherguidance'));
        if ($guidance === '') {
            return null;
        }

        // Whether mod_ednote is here at all, and switched on. This cannot be left to
        // course_allowed_module() below, which does the opposite of what its name suggests for a
        // module that is not installed: with no mod/ednote:addinstance capability to check it
        // returns true - "if the capability does not exist, the module can always be added" - and
        // create_module() then throws dml_missing_record_exception looking the module row up with
        // MUST_EXIST. Since a preset's guidance is optional chrome, that would turn every copy of a
        // preset carrying guidance into a failed copy on any site without mod_ednote, which is
        // precisely the arrangement this plugin promises to support.
        if (!$DB->record_exists('modules', ['name' => self::NOTE_MODNAME, 'visible' => 1])) {
            return null;
        }

        // Whether this user may add one here.
        if (!course_allowed_module($course, self::NOTE_MODNAME)) {
            return null;
        }

        require_once($CFG->dirroot . '/course/modlib.php');

        $note = (object)[
            'modulename' => self::NOTE_MODNAME,
            'course' => $course->id,
            'section' => $sectionnum,
            'visible' => 1,
            'name' => get_string('notename', 'mod_edpreset', $preset->get('title')),
            'presetid' => (int)$preset->get('id'),
            // Core's create_module() insists on the editor-shaped field for any module that
            // supports an intro, and add_moduleinfo() unpacks it back into intro/introformat.
            // Passing a plain intro instead throws createmodulemissingattribut.
            //
            // The text is already cleaned HTML - mod_edpreset renders the curator's markdown once,
            // at bake time - so it is stored as-is rather than run through a format again.
            'introeditor' => [
                'text' => $guidance,
                'format' => FORMAT_HTML,
                'itemid' => 0,
            ],
        ];

        $created = create_module($note);

        // Above the activity it describes. create_module() appends to the section, so this is a
        // second move rather than a placement.
        self::place($course, (int)$created->coursemodule, $sectionnum, $activitycmid);

        return (int)$created->coursemodule;
    }

    /**
     * Tear a restore controller down, whether or not its plan ran to completion.
     *
     * Both halves matter only because several restores can now run in one request.
     *
     * backup_ids_temp and backup_files_temp are real database temp tables, and there is one pair
     * per connection rather than one per restore. The plan's last step drops them, so a restore
     * that threw part way through leaves them behind - and the next restore in the same request
     * adopts them, because create_restore_temp_tables() returns early whenever the table already
     * exists without checking whose it is. Rows are keyed by restore id so the results stay
     * correct, but the stale rows would otherwise accumulate for the rest of the request.
     *
     * destroy() is what releases the plan and the logger. Its own docblock warns that a script
     * performing several operations without it runs out of memory, which is exactly this case.
     *
     * @param restore_controller|null $rc The controller, or null if it never got built.
     */
    protected static function dispose(?restore_controller $rc): void {
        global $DB;

        if (!$rc) {
            return;
        }

        // Ask before dropping: drop_restore_temp_tables() drops unconditionally, and on the
        // ordinary path the plan has already done it, so this would throw ddl_table_missing.
        if ($DB->get_manager()->table_exists('backup_ids_temp')) {
            \restore_controller_dbops::drop_restore_temp_tables($rc->get_restoreid());
        }

        // An archive that is not moodle2 format stops at STATUS_REQUIRE_CONV, before load_plan(),
        // and destroy() dereferences that plan unguarded. Presets are always baked by this plugin
        // so this should not happen, but a fatal here would mask whatever really went wrong.
        if ($rc->get_status() !== backup::STATUS_REQUIRE_CONV) {
            $rc->destroy();
        }
    }

    /**
     * Turn off everything that would carry user data or template-course specifics across.
     *
     * Settings that the site has locked are left alone; set_value() on a locked setting throws.
     *
     * @param restore_controller $rc The controller.
     */
    protected static function disable_user_data_settings(restore_controller $rc): void {
        $unwanted = [
            'users', 'role_assignments', 'groups', 'grade_histories', 'userscompletion',
            'logs', 'comments', 'badges', 'calendarevents',
        ];

        $plan = $rc->get_plan();
        foreach ($unwanted as $name) {
            if (!$plan->setting_exists($name)) {
                continue;
            }
            $setting = $plan->get_setting($name);
            if ($setting->get_status() === \base_setting::NOT_LOCKED) {
                $setting->set_value(false);
            }
        }
    }

    /**
     * Find the course module the restore just created.
     *
     * A TYPE_1ACTIVITY archive contains exactly one activity task, so the sole task is taken rather
     * than matching on the exemplar's stored context id. That keeps this working even if the
     * exemplar has since been deleted and recreated, which would leave the stored context id
     * pointing at nothing.
     *
     * @param restore_controller $rc The controller, after execute_plan().
     * @return int The new course module id.
     * @throws moodle_exception If the archive did not contain exactly one activity.
     */
    protected static function find_restored_cmid(restore_controller $rc): int {
        $cmids = [];
        foreach ($rc->get_plan()->get_tasks() as $task) {
            if (is_subclass_of($task, 'restore_activity_task')) {
                $cmids[] = (int)$task->get_moduleid();
            }
        }

        if (count($cmids) !== 1) {
            throw new moodle_exception(
                'restorewrongactivitycount',
                'mod_edpreset',
                '',
                count($cmids)
            );
        }

        return $cmids[0];
    }

    /**
     * Put the restored activity in the requested section, at the requested position.
     *
     * The restore places the activity by matching the *exemplar's* section number in the target
     * course (restore_module_structure_step::process_module), falling back to the lowest section.
     * Exemplars all live in sections 1 and above, so that is almost never where the teacher asked
     * for it - hence placing it explicitly here.
     *
     * @param stdClass $course The target course.
     * @param int $newcmid The restored course module id.
     * @param int $sectionnum The requested section number.
     * @param int $beforemod Course module id to insert before, or 0 to append.
     */
    protected static function place(stdClass $course, int $newcmid, int $sectionnum, int $beforemod): void {
        global $DB;

        if (!get_fast_modinfo($course)->get_section_info($sectionnum)) {
            \core_courseformat\formatactions::section($course)->create_if_missing([$sectionnum]);
        }

        $section = $DB->get_record(
            'course_sections',
            ['course' => $course->id, 'section' => $sectionnum],
            '*',
            MUST_EXIST
        );

        // Core does not verify that beforemod belongs to this course either; a value that is not
        // in the section's sequence simply degrades to appending.
        $newcm = get_coursemodule_from_id('', $newcmid, $course->id, false, MUST_EXIST);
        moveto_module($newcm, $section, $beforemod ?: null);
    }
}
