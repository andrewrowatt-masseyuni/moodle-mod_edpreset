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
 * One transformation applied to an extracted backup before it is published.
 *
 * Rules exist because a backup taken from a curated exemplar carries things that should not follow
 * it into a teacher's course. Only clearing dates ships today, but the shape is deliberate: the
 * other candidates (completion criteria that reference sibling activities, grade category
 * assignments, group and grouping references, competency links) are all the same operation on a
 * different set of elements, and should not each require reworking the scrubber.
 *
 * A rule may be wrong without being dangerous: every scrubbed archive is test-restored before it
 * is published, and an archive that a rule has broken is replaced by the unscrubbed original.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface rule {
    /**
     * A short machine-readable name, recorded against the preset so a curator can see what ran.
     *
     * @return string
     */
    public function get_name(): string;

    /**
     * Whether this rule has anything to do for the given module.
     *
     * @param string $modname The exemplar's module name.
     * @return bool
     */
    public function applies_to(string $modname): bool;

    /**
     * Apply the rule to an extracted backup.
     *
     * @param string $basepath Absolute path of the extracted archive.
     * @param string $modname The exemplar's module name.
     * @param int $cmid The exemplar's course module id, which names the activity directory.
     * @return string[] What was changed, for display on the manage page. Empty if nothing changed.
     */
    public function apply(string $basepath, string $modname, int $cmid): array;
}
