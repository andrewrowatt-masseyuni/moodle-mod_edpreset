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
 * Install-time setup for the activity preset provider.
 *
 * Runs once, after db/install.xml has been applied, and only on a fresh install. There is
 * deliberately no matching upgrade step: the course custom field created here is a convenience for
 * administrators rather than something the plugin depends on, and everything that touches it treats
 * its absence as normal. See \mod_edpreset\local\coursedefault.
 *
 * @package    mod_edpreset
 * @category   upgrade
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Custom code to be run on installing the plugin.
 *
 * @return bool
 */
function xmldb_edpreset_install() {
    \mod_edpreset\local\coursedefault::install();

    return true;
}
