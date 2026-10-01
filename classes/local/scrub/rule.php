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

namespace mod_edpreset\local\scrub;

/**
 * One tidy-up applied to a freshly copied activity.
 *
 * Rules exist because an exemplar carries things that should not follow it into a teacher's course.
 * Only clearing dates ships today, but the shape is deliberate: the other candidates (completion
 * criteria that reference sibling activities, grade category assignments, group and grouping
 * references, competency links) are all the same operation on a different set of fields, and
 * should not each require reworking the scrubber.
 *
 * A rule runs on the copy after it has been restored, so a wrong rule can leave a copy oddly set up
 * but can never stop it arriving.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface rule {
    /**
     * A short machine-readable name, for debugging output.
     *
     * @return string
     */
    public function get_name(): string;

    /**
     * Whether this rule has anything to do for the given module.
     *
     * @param string $modname The module name.
     * @return bool
     */
    public function applies_to(string $modname): bool;

    /**
     * Apply the rule to a freshly copied activity.
     *
     * @param string $modname The copy's module name.
     * @param int $instanceid The copy's instance id.
     * @return string[] What was changed. Empty if nothing changed.
     */
    public function apply(string $modname, int $instanceid): array;
}
