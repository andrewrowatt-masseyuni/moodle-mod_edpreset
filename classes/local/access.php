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
 * Copying has one entry point, copy.php, reached both from the standard activity chooser and from
 * the preset chooser page's batch add. The rules live here rather than in it so that the validator
 * and the tests can apply the same ones.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {
    /**
     * @var int How many presets one request may copy.
     *
     * The restores run sequentially in the request that asked for them, so this is what stops a
     * hand-edited URL from occupying a PHP worker - and the user's copy lock - for an hour. It is
     * well above any plausible selection on the chooser page.
     */
    public const MAX_PRESETS = 20;

    /**
     * @var int How many positions one reorder may describe.
     *
     * The list covers the template's own activities plus everything already in the target section,
     * teacher notes included, so it is legitimately longer than MAX_PRESETS. This is only here to
     * stop a hand-edited request from making the reorder loop - which rebuilds the course cache
     * twice per position - run for an unbounded time.
     */
    public const MAX_ORDER_TOKENS = 200;

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
     * Turn the presets request parameter into a list of ids to copy, in the order asked for.
     *
     * @param string $sequence A PARAM_SEQUENCE list of preset ids.
     * @return int[] The ids, deduplicated, order preserved.
     * @throws moodle_exception If the list is empty or longer than MAX_PRESETS.
     */
    public static function clean_presets(string $sequence): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $sequence)))));

        if (!$ids) {
            throw new moodle_exception('invalidpreset', 'mod_edpreset');
        }

        if (count($ids) > self::MAX_PRESETS) {
            throw new moodle_exception('toomanypresets', 'mod_edpreset', '', self::MAX_PRESETS);
        }

        return $ids;
    }

    /**
     * Turn the order request parameter into the list of positions to apply.
     *
     * Unrecognised tokens are dropped rather than rejected: the list is assembled in the browser
     * from a section that may have changed since, and a stale entry should not cost the teacher the
     * whole add. What it must not do is let something arbitrary through to be resolved later.
     *
     * @param string $order Comma-separated p<presetid> / c<cmid> tokens.
     * @return string[] The recognised tokens, order preserved, deduplicated.
     * @throws moodle_exception If the list is longer than MAX_ORDER_TOKENS.
     */
    public static function clean_order(string $order): array {
        if (trim($order) === '') {
            return [];
        }

        $tokens = explode(',', $order);
        if (count($tokens) > self::MAX_ORDER_TOKENS) {
            throw new moodle_exception('toomanypositions', 'mod_edpreset', '', self::MAX_ORDER_TOKENS);
        }

        $clean = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if (preg_match('/^[pc][1-9][0-9]*$/', $token)) {
                $clean[$token] = true;
            }
        }

        return array_keys($clean);
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
