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

use context_course;
use moodle_exception;
use stdClass;

/**
 * The checks that must pass before a preset may be copied into a course.
 *
 * Three entry points reach the copier - the page the activity chooser links to, the preset chooser
 * page, and the external function the preset chooser page's batch add calls - and all three must
 * apply exactly the same rules, so they live here rather than in any one of them.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {
    /**
     * Require that the current user may copy a preset into a course section.
     *
     * @param stdClass $course The target course.
     * @param int $sectionnum The target section number.
     * @throws moodle_exception If the user may not, or the section number is out of range.
     */
    public static function require_can_copy_into(stdClass $course, int $sectionnum): void {
        global $CFG;

        // Note that course/lib.php is not part of the standard bootstrap - require_login() only
        // pulls it in down one branch - so an external function reaching here has nothing loaded.
        // It also brings in course/format/lib.php for course_get_format().
        require_once($CFG->dirroot . '/course/lib.php');

        $coursecontext = context_course::instance($course->id);

        require_capability('moodle/course:manageactivities', $coursecontext);
        // Checked explicitly so the teacher gets this plugin's error rather than a bare restore
        // failure part way through.
        require_capability('moodle/restore:restoretargetimport', $coursecontext);

        if (!course_allowed_module($course, 'edpreset')) {
            throw new moodle_exception('moduledisable');
        }

        // Same guard core applies in modedit.php (MDL-69431): a section number beyond the format's
        // maximum would leave non-sequential rows in course_sections.
        $maxsections = course_get_format($course)->get_max_sections();
        if ($sectionnum < 0 || $sectionnum > $maxsections) {
            throw new moodle_exception('maxsectionslimit', 'moodle', '', $maxsections);
        }
    }

    /**
     * Discard a beforemod that does not belong to the target course.
     *
     * Core tolerates this by falling back to appending; do the same, explicitly.
     *
     * @param stdClass $course The target course.
     * @param int $beforemod The course module id to insert before, or 0.
     * @return int The beforemod to use.
     */
    public static function clean_beforemod(stdClass $course, int $beforemod): int {
        global $DB;

        if (!$beforemod) {
            return 0;
        }

        return $DB->record_exists('course_modules', ['id' => $beforemod, 'course' => $course->id])
            ? $beforemod
            : 0;
    }
}
