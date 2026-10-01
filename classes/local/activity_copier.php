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
use backup_controller;
use cm_info;
use core\progress\base as progress_base;
use mod_edpreset\preset;
use moodle_exception;
use restore_controller;
use stdClass;
use Throwable;

/**
 * Copies an exemplar activity into a course: a backup and a restore, one straight after the other.
 *
 * This is the cross-course equivalent of core's duplicate_module(), and does what it does - an
 * import-mode backup handed straight to an import-mode restore, with no archive in between. It
 * cannot simply call it: everything duplicate_module() does after the restore is hardcoded to the
 * *source* course - get_coursemodule_from_id(..., $cm->course), the course_sections lookup filtered
 * on $cm->course, and get_fast_modinfo($cm->course) - so it silently fails when the target course is
 * a different one.
 *
 * Nothing is stored between copies. Each one backs up the exemplar as it is at that moment, so a
 * teacher always gets the curator's current version, and there is no archive that could fall out
 * of step with it. Which presets are offered at all is the curator's release status, not anything
 * this class proves.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_copier {
    /**
     * Copy a preset into a course.
     *
     * Any teacher guidance comes with it and needs nothing done here: it is embedded in the
     * exemplar's own text as local_edguidance tokens, whose blocks ride along in the activity's
     * backup - see local/edguidance/README.md.
     *
     * @param preset $preset The preset to copy.
     * @param stdClass $course The target course.
     * @param int $sectionnum The section number to place the activity in.
     * @param int $beforemod Course module id to insert before, or 0 to append.
     * @param progress_base|null $progress Optional progress reporter.
     * @return cm_info The new course module.
     * @throws moodle_exception If the exemplar has gone, or the backup or restore fails.
     */
    public static function copy(
        preset $preset,
        stdClass $course,
        int $sectionnum,
        int $beforemod = 0,
        ?progress_base $progress = null
    ): cm_info {
        global $CFG;

        // Holds moveto_module() and set_coursemodule_name(), and is not part of the standard
        // bootstrap - so an external function reaching here has nothing loaded.
        require_once($CFG->dirroot . '/course/lib.php');

        $exemplar = $preset->get_exemplar();
        if (!$exemplar) {
            throw new moodle_exception('exemplarmissing', 'mod_edpreset');
        }

        $newcmid = self::copy_activity($exemplar, $course, $sectionnum, $beforemod, $progress);

        // Before the modinfo read below, not after: set_coursemodule_name() purges and rebuilds
        // the course cache, so a rename afterwards would leave the returned cm_info holding the
        // exemplar's name - which is exactly what the caller displays.
        $defaultname = trim((string)$preset->get('defaultname'));
        if ($defaultname !== '') {
            set_coursemodule_name($newcmid, $defaultname);
        }

        return get_fast_modinfo($course->id)->get_cm($newcmid);
    }

    /**
     * Back an activity up and restore it into a course, then place it and tidy it up.
     *
     * The backup runs as the site administrator. An import-mode backup needs
     * moodle/backup:backuptargetimport in the source course, and teachers do not hold that in the
     * template course - nor should they, since it would let them import anything from it through
     * core's own import page. Running as the administrator for this one step is what core's recycle
     * bin does too (tool_recyclebin\course_bin::store_item() backs activities up as get_admin() from
     * whoever deleted them). It widens nothing: the caller has already decided this exemplar is one
     * the teacher may be offered, and an import-mode backup never carries user data - backup_check
     * forces the users setting off and locks it.
     *
     * The restore runs as the requesting user, which needs moodle/restore:restoretargetimport in
     * the target course. Editing teachers hold that by default, and access::require_can_copy_into()
     * checks it before anything gets here.
     *
     * @param cm_info $source The activity to copy.
     * @param stdClass $course The target course.
     * @param int $sectionnum The section number to place the activity in.
     * @param int $beforemod Course module id to insert before, or 0 to append.
     * @param progress_base|null $progress Optional progress reporter, used by both halves.
     * @return int The new course module id.
     * @throws moodle_exception If the backup or the restore fails.
     */
    public static function copy_activity(
        cm_info $source,
        stdClass $course,
        int $sectionnum,
        int $beforemod = 0,
        ?progress_base $progress = null
    ): int {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        [$backupid, $basepath] = self::backup($source, $progress);
        try {
            $newcmid = self::restore($backupid, $course, (int)$USER->id, $progress);
        } finally {
            if (empty($CFG->keeptempdirectoriesonbackup)) {
                fulldelete($basepath);
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

        // Before the calendar is refreshed below, so the events are built from the cleared dates
        // rather than the exemplar's.
        $newcm = get_coursemodule_from_id('', $newcmid, $course->id, false, MUST_EXIST);
        scrubber::scrub($newcm->modname, (int)$newcm->instance);

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
     * Take an import-mode backup of one activity.
     *
     * Import mode is what keeps this cheap: it writes the backup to a temp directory without
     * zipping it, and includes no file content - the restore re-links the files already in the
     * file pool by content hash (restore_dbops::send_files_to_pool()), so a large package costs no
     * more to copy than a small one.
     *
     * @param cm_info $source The activity.
     * @param progress_base|null $progress Optional progress reporter.
     * @return array{0: string, 1: string} The backup id, which is also the restore's temp directory
     *     name, and the full path of that directory for the caller to remove.
     * @throws moodle_exception If the backup fails or the site will not back activities up.
     */
    protected static function backup(cm_info $source, ?progress_base $progress): array {
        global $CFG;

        $bc = new backup_controller(
            backup::TYPE_1ACTIVITY,
            $source->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            get_admin()->id
        );

        $backupid = $bc->get_backupid();
        $basepath = $bc->get_plan()->get_basepath();

        try {
            if ($progress) {
                $bc->set_progress($progress);
            }

            self::disable_backup_settings($bc);

            // A site can lock the import defaults so that activities are left out, in which case
            // the backup would run, contain nothing, and the restore would fail with a message
            // about the backup. Say what is actually wrong instead.
            $plan = $bc->get_plan();
            if ($plan->setting_exists('activities') && !$plan->get_setting('activities')->get_value()) {
                throw new moodle_exception('backupnoactivities', 'mod_edpreset');
            }

            $bc->execute_plan();
        } catch (Throwable $e) {
            if (empty($CFG->keeptempdirectoriesonbackup)) {
                fulldelete($basepath);
            }
            throw $e;
        } finally {
            $bc->destroy();
        }

        return [$backupid, $basepath];
    }

    /**
     * Restore a backup into a course, leaving nothing behind if it fails.
     *
     * @param string $backupid The backup id, which names the temp directory holding it.
     * @param stdClass $course The target course.
     * @param int $userid The user to run the restore as.
     * @param progress_base|null $progress Optional progress reporter.
     * @return int The new course module id.
     * @throws moodle_exception If the restore fails its precheck or produces no activity.
     */
    protected static function restore(string $backupid, stdClass $course, int $userid, ?progress_base $progress): int {
        $rc = null;
        try {
            $rc = new restore_controller(
                $backupid,
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

            try {
                $rc->execute_plan();
            } catch (Throwable $e) {
                self::remove_partial_restore($rc, $course);
                throw $e;
            }

            // Before dispose() below, which empties the plan's task list and takes the only record
            // of the new course module id with it.
            return self::find_restored_cmid($rc);
        } finally {
            self::dispose($rc);
        }
    }

    /**
     * Delete whatever a failed restore had already put into the teacher's course.
     *
     * Nothing proves an exemplar restores before a teacher asks for it, so a restore that breaks
     * part way breaks in their course. The activity task records the course module as soon as it
     * creates one, which is what lets this find it.
     *
     * Best effort: the restore's own failure is what the caller reports, so a failure here is
     * logged rather than thrown over it.
     *
     * @param restore_controller $rc The controller whose plan threw.
     * @param stdClass $course The target course.
     */
    protected static function remove_partial_restore(restore_controller $rc, stdClass $course): void {
        global $CFG, $DB;

        foreach ($rc->get_plan()->get_tasks() as $task) {
            if (!is_subclass_of($task, 'restore_activity_task')) {
                continue;
            }

            $cmid = (int)$task->get_moduleid();
            if (!$cmid || !$DB->record_exists('course_modules', ['id' => $cmid, 'course' => $course->id])) {
                continue;
            }

            // A failed copy is not something the teacher deleted, so it must not turn up in their
            // course's recycle bin. Forcing the setting for the length of the delete is the same
            // device the recycle bin itself uses on the backup settings.
            $forced = $CFG->forced_plugin_settings['tool_recyclebin'] ?? null;
            $CFG->forced_plugin_settings['tool_recyclebin']['coursebinenable'] = 0;
            try {
                course_delete_module($cmid);
            } catch (Throwable $e) {
                // The restore can stop before it has created the activity's own record, and
                // course_delete_module() refuses to go on without one. What exists by then is the
                // course module and its place in the section.
                try {
                    self::remove_course_module($cmid);
                } catch (Throwable $e) {
                    debugging(
                        'mod_edpreset: could not remove a partly restored activity (course module ' . $cmid . '): '
                            . $e->getMessage(),
                        DEBUG_NORMAL
                    );
                }
            } finally {
                if ($forced === null) {
                    unset($CFG->forced_plugin_settings['tool_recyclebin']);
                } else {
                    $CFG->forced_plugin_settings['tool_recyclebin'] = $forced;
                }
            }
        }

        rebuild_course_cache($course->id, true);
    }

    /**
     * Remove a course module that never got as far as having an activity behind it.
     *
     * @param int $cmid The course module id.
     */
    protected static function remove_course_module(int $cmid): void {
        global $DB;

        $cm = $DB->get_record('course_modules', ['id' => $cmid]);
        if (!$cm) {
            return;
        }

        delete_mod_from_section($cmid, $cm->section);
        \context_helper::delete_instance(CONTEXT_MODULE, $cmid);
        $DB->delete_records('course_modules', ['id' => $cmid]);
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
     * @return array{added: cm_info[], failed: string[], placed: array<int, int>}
     *               The new course modules, the titles of the presets that could not be copied, and
     *               the course module each preset that succeeded became, keyed by preset id.
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
                $cm = self::copy($preset, $course, $sectionnum, $beforemod, $progress);
                $added[] = $cm;
                $placed[(int)$preset->get('id')] = (int)$cm->id;
                $preset->clear_copy_error();
            } catch (Throwable $e) {
                // The teacher is told which preset failed, but not why - the reasons are backup and
                // restore internals. Keep the real one where an administrator will see it: against
                // the preset, for the manage page, and in the debugging output.
                $failed[] = $preset->get('title');
                $preset->record_copy_error(get_class($e) . ': ' . $e->getMessage());
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
        $result = self::copy_many($template->get_members(), $course, $sectionnum, $beforemod, $progress);

        if ($order && $result['added']) {
            self::reorder_section(
                $course,
                $sectionnum,
                self::expand_order($course, $sectionnum, $order, $result['placed'])
            );
        }

        return ['added' => $result['added'], 'failed' => $result['failed']];
    }

    /**
     * Turn the teacher's chosen order into the full run of course modules the section should hold.
     *
     * Anything in the section that the order does not mention is appended, keeping its relative
     * order. That is what implements "activities the teacher left alone end up below the template",
     * and it doubles as the safety net that stops a hand-edited order from losing an activity.
     *
     * @param stdClass $course The target course.
     * @param int $sectionnum The section being reordered.
     * @param string[] $order The p<presetid>/c<cmid> tokens, in the order asked for.
     * @param int[] $placed The course module each preset became, keyed by preset id.
     * @return int[] Course module ids, in the order the section should hold them.
     */
    protected static function expand_order(stdClass $course, int $sectionnum, array $order, array $placed): array {
        $current = self::section_cmids($course, $sectionnum);

        return array_values(array_unique(array_merge(self::resolve_order($order, $placed, $current), $current)));
    }

    /**
     * Turn the order tokens into the course module ids they name.
     *
     * Tokens that no longer mean anything are dropped rather than raised: the list was assembled in
     * the browser and can go stale between the dialogue opening and the form arriving - a preset that
     * failed to restore, an activity someone else deleted - and neither is worth failing the add for.
     *
     * @param string[] $order The p<presetid>/c<cmid> tokens, in the order asked for.
     * @param int[] $placed The course module each preset became, keyed by preset id.
     * @param int[] $current The course module ids the section holds now.
     * @return int[]
     */
    protected static function resolve_order(array $order, array $placed, array $current): array {
        $cmids = [];
        foreach ($order as $token) {
            $id = (int)substr($token, 1);
            $cmid = match ($id ? $token[0] : '') {
                'p' => (int)($placed[$id] ?? 0),
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
     * Tear a restore controller down, whether or not its plan ran to completion.
     *
     * Both halves matter only because several restores can run in one request.
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

        // A backup that is not moodle2 format stops at STATUS_REQUIRE_CONV, before load_plan(),
        // and destroy() dereferences that plan unguarded. The backup is always one this class has
        // just taken, so this should not happen, but a fatal here would mask whatever really went
        // wrong.
        if ($rc->get_status() !== backup::STATUS_REQUIRE_CONV) {
            $rc->destroy();
        }
    }

    /**
     * Turn off everything in the backup that would carry user data or template-course specifics.
     *
     * Import mode already forces user data off; these are the course-level extras its defaults may
     * still include. Settings that the site has locked are left alone; set_value() on a locked
     * setting throws.
     *
     * @param backup_controller $bc The controller.
     */
    protected static function disable_backup_settings(backup_controller $bc): void {
        $unwanted = [
            'users', 'anonymize', 'role_assignments', 'userscompletion', 'logs',
            'grade_histories', 'groups', 'comments', 'badges', 'calendarevents',
            'contentbankcontent', 'legacyfiles',
        ];

        $plan = $bc->get_plan();
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
     * A TYPE_1ACTIVITY backup contains exactly one activity task, so the sole task is taken rather
     * than matching on the exemplar's context id the way duplicate_module() does.
     *
     * @param restore_controller $rc The controller, after execute_plan().
     * @return int The new course module id.
     * @throws moodle_exception If the backup did not contain exactly one activity.
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
