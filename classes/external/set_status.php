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

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_edpreset\local\review;
use mod_edpreset\preset;
use moodle_exception;

/**
 * Release a preset that is ready for review, or return it to its curator as a draft.
 *
 * Asked of the course the preset chooser page is open for, because that is where the capability to
 * review presets is checked everywhere else: a reviewer is someone who holds it there.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_status extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'presetid' => new external_value(PARAM_INT, 'The preset that is ready for review'),
            'courseid' => new external_value(PARAM_INT, 'The course the preset chooser page is open for'),
            'status' => new external_value(PARAM_ALPHA, 'released to release it, draft to return it to its curator'),
        ]);
    }

    /**
     * Record the review's outcome.
     *
     * @param int $presetid The preset.
     * @param int $courseid The course the page is open for.
     * @param string $status released or draft.
     * @return array
     */
    public static function execute(int $presetid, int $courseid, string $status): array {
        [
            'presetid' => $presetid,
            'courseid' => $courseid,
            'status' => $status,
        ] = self::validate_parameters(
            self::execute_parameters(),
            ['presetid' => $presetid, 'courseid' => $courseid, 'status' => $status]
        );

        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('mod/edpreset:reviewpresets', $context);

        $preset = preset::get_record(['id' => $presetid, 'enabled' => 1]);
        if (!$preset) {
            throw new moodle_exception('invalidpreset', 'mod_edpreset');
        }

        review::decide($preset, $status);

        return ['status' => $status];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'The preset\'s status now'),
        ]);
    }
}
