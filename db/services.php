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
 * External functions used by the preset chooser page.
 *
 * Ajax-only: this exists for that page's JavaScript, not as a public API, so it is not added to any
 * service. Copying is not here - it is a form post to copy.php, so that one restore or ten happen
 * in a single request rather than one per activity.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_edpreset_set_favourite' => [
        'classname' => 'mod_edpreset\external\set_favourite',
        'description' => 'Star or unstar a preset activity for the current user.',
        'type' => 'write',
        'ajax' => true,
    ],
];
