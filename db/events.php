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

/**
 * Event observers for the activity preset provider.
 *
 * All are external (internal => false) so they run after the transaction has committed: they queue
 * adhoc tasks, which must not be queued from inside a transaction that might roll back.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_module_created',
        'callback' => '\mod_edpreset\observer::course_module_created',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_module_updated',
        'callback' => '\mod_edpreset\observer::course_module_updated',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback' => '\mod_edpreset\observer::course_module_deleted',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_section_updated',
        'callback' => '\mod_edpreset\observer::course_section_updated',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\grading_definition_created',
        'callback' => '\mod_edpreset\observer::grading_definition_changed',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\grading_definition_updated',
        'callback' => '\mod_edpreset\observer::grading_definition_changed',
        'internal' => false,
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback' => '\mod_edpreset\observer::course_deleted',
        'internal' => false,
    ],
];
