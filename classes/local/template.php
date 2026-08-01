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

use stdClass;

/**
 * Resolves the template course that exemplar activities are curated in.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template {
    /**
     * Whether the plugin is switched on at all.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool)get_config('mod_edpreset', 'enabled');
    }

    /**
     * The configured template course id, or 0 if none is set.
     *
     * @return int
     */
    public static function get_courseid(): int {
        return (int)get_config('mod_edpreset', 'templatecourseid');
    }

    /**
     * Every configured template course id.
     *
     * There is one today. Everything that asks "is this a template course?" goes through here or
     * through is_template_course(), so adding a second is a change to these two methods and the
     * admin setting, not to every caller.
     *
     * @return int[]
     */
    public static function get_courseids(): array {
        return array_values(array_filter([self::get_courseid()]));
    }

    /**
     * Whether a course is one of the template courses.
     *
     * @param int $courseid The course id.
     * @return bool
     */
    public static function is_template_course(int $courseid): bool {
        return $courseid > 0 && in_array($courseid, self::get_courseids(), true);
    }

    /**
     * The template course record, or null if unset or the course has since been deleted.
     *
     * Deliberately not get_course(): its second argument is $clone, not a strictness flag, so it
     * always uses MUST_EXIST and would throw. This runs on every activity chooser open in every
     * course, so an admin deleting the template course must degrade to "no presets", never to a
     * site-wide exception.
     *
     * @return stdClass|null
     */
    public static function get(): ?stdClass {
        global $DB;

        $courseid = self::get_courseid();
        if (!$courseid) {
            return null;
        }
        return $DB->get_record('course', ['id' => $courseid]) ?: null;
    }

    /**
     * Whether the plugin is enabled and pointed at a course that still exists.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return self::is_enabled() && self::get() !== null;
    }

    /**
     * The lowest section number scanned for exemplars.
     *
     * Section 0 is reserved for admin-facing instructions to whoever curates the template course,
     * so it is never turned into presets.
     *
     * @return int
     */
    public static function first_scanned_section(): int {
        return 1;
    }

    /**
     * The section whose presets go into the standard activity chooser.
     *
     * Everything above it is reached through the preset chooser page instead. The split exists
     * because the standard chooser shows every item at once, so it stops being usable once the
     * template course grows past a handful of exemplars.
     *
     * @return int
     */
    public static function priority_section(): int {
        return 1;
    }
}
