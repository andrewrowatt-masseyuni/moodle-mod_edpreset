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

namespace mod_edpreset\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_edpreset\local\access;
use mod_edpreset\local\activity_copier;
use mod_edpreset\preset;
use moodle_exception;

/**
 * Copy one preset into a course, without opening its settings form.
 *
 * This is the batch path. Adding a single preset still goes through copy.php, because that route
 * ends on the new activity's settings form and this one deliberately does not.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class copy_preset extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course to copy the preset into'),
            'presetid' => new external_value(PARAM_INT, 'The preset to copy'),
            'sectionnum' => new external_value(PARAM_INT, 'The section number to place it in'),
            'beforemod' => new external_value(PARAM_INT, 'Course module id to insert before, or 0', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Copy the preset.
     *
     * @param int $courseid The course to copy into.
     * @param int $presetid The preset to copy.
     * @param int $sectionnum The section number.
     * @param int $beforemod Course module id to insert before, or 0.
     * @return array
     */
    public static function execute(int $courseid, int $presetid, int $sectionnum, int $beforemod = 0): array {
        global $DB, $USER;

        [
            'courseid' => $courseid,
            'presetid' => $presetid,
            'sectionnum' => $sectionnum,
            'beforemod' => $beforemod,
        ] = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'presetid' => $presetid,
            'sectionnum' => $sectionnum,
            'beforemod' => $beforemod,
        ]);

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $context = \context_course::instance($course->id);
        self::validate_context($context);

        access::require_can_copy_into($course, $sectionnum);
        $beforemod = access::clean_beforemod($course, $beforemod);

        $preset = preset::get_record(['id' => $presetid, 'enabled' => 1]);
        if (!$preset || !$preset->is_live()) {
            throw new moodle_exception('invalidpreset', 'mod_edpreset');
        }

        // One restore per request, and a batch makes several in a row. The default limit is not
        // generous enough for a preset carrying a question bank or a content package.
        \core_php_time_limit::raise();

        // The same lock name copy.php takes, deliberately: a teacher with the activity chooser
        // open in another tab must not be able to start a second restore alongside this one.
        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_edpreset');
        $lock = $lockfactory->get_lock('copy_' . $USER->id, 0);
        if (!$lock) {
            throw new moodle_exception('copyinprogress', 'mod_edpreset');
        }

        try {
            $cm = activity_copier::copy($preset, $course, $sectionnum, $beforemod);
        } finally {
            $lock->release();
        }

        return [
            'cmid' => (int)$cm->id,
            'name' => format_string($cm->name, true, ['context' => $context]),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'The new course module id'),
            'name' => new external_value(PARAM_TEXT, 'The name the new activity was given'),
        ]);
    }
}
