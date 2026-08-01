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
                    $rc->destroy();
                    throw new moodle_exception(
                        'restoreprecheckfailed',
                        'mod_edpreset',
                        '',
                        implode('; ', $results['errors'])
                    );
                }
            }

            $rc->execute_plan();
            $newcmid = self::find_restored_cmid($rc);
            $rc->destroy();
        } finally {
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

        return get_fast_modinfo($course->id)->get_cm($newcmid);
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
