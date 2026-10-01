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

namespace mod_edpreset;

use cm_info;
use core\event\base;
use mod_edpreset\local\baker;
use mod_edpreset\local\template;
use Throwable;

/**
 * Keeps presets current as the template course is edited.
 *
 * Every handler is deliberately cheap and non-throwing: these fire on activity edits site-wide, and
 * the overwhelming majority are in courses this plugin does not care about, so the first thing each
 * one does is compare a config value and give up.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * An activity was added to the template course.
     *
     * @param \core\event\course_module_created $event The event.
     */
    public static function course_module_created(\core\event\course_module_created $event): void {
        if (!self::concerns_template_course($event)) {
            return;
        }

        self::copy_details_to_duplicate((int)$event->courseid, (int)$event->objectid);

        // A new activity has no preset row yet, so the whole course is rescanned.
        baker::queue_rebuild();
    }

    /**
     * Give an activity the curator has just duplicated a draft copy of the original's preset details.
     *
     * Core's duplicate fires nothing that names the original, so it is recognised by what
     * duplicate_module() leaves behind: the copy directly after the original in the same section,
     * of the same module, named with the original's name and core's "(copy)" suffix - the same
     * string in the same language, since this runs in the request that did the duplicating.
     *
     * Getting this wrong either way is harmless, which is why a heuristic is good enough. A duplicate
     * it misses simply has no preset details until the curator fills them in, as any new activity
     * would; an activity it wrongly matches is given details as a draft, which is offered to nobody.
     *
     * @param int $courseid The template course.
     * @param int $cmid The new activity.
     */
    protected static function copy_details_to_duplicate(int $courseid, int $cmid): void {
        try {
            $modinfo = get_fast_modinfo($courseid);
            $cm = $modinfo->get_cm($cmid);
            $original = self::previous_in_section($cm);
            if (!$original || $original->modname !== $cm->modname) {
                return;
            }

            if ($cm->name !== get_string('duplicatedmodule', 'moodle', $original->name)) {
                return;
            }

            meta::get_for_cm((int)$original->id)?->copy_to_duplicate($cmid);
        } catch (Throwable $e) {
            // Every observer here must stay non-throwing; at worst the curator types the details in.
            debugging('mod_edpreset: could not copy preset details to a duplicate: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * The activity immediately before another in its section.
     *
     * @param cm_info $cm The activity.
     * @return cm_info|null Null if it is the first in its section.
     */
    protected static function previous_in_section(cm_info $cm): ?cm_info {
        $modinfo = $cm->get_modinfo();
        $sequence = array_map('intval', $modinfo->sections[$cm->sectionnum] ?? []);

        $position = array_search((int)$cm->id, $sequence, true);
        if (!$position) {
            return null;
        }

        return $modinfo->get_cm($sequence[$position - 1]);
    }

    /**
     * An activity in the template course was edited.
     *
     * @param \core\event\course_module_updated $event The event.
     */
    public static function course_module_updated(\core\event\course_module_updated $event): void {
        if (!self::concerns_template_course($event)) {
            return;
        }
        // The copy itself needs nothing - every copy takes a fresh backup - but the edit may have
        // hidden the exemplar or moved it to another section, which changes the preset's record.
        baker::queue_rebuild();
    }

    /**
     * An activity was removed from the template course.
     *
     * @param \core\event\course_module_deleted $event The event.
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        if (!self::concerns_template_course($event)) {
            return;
        }

        // XMLDB foreign keys are not enforced by the database, so nothing cascades the curator's
        // preset details away with the activity they describe.
        meta::delete_for_cm((int)$event->objectid);

        $preset = preset::get_record(['templatecmid' => (int)$event->objectid]);
        if ($preset) {
            baker::delete_preset($preset);
        }
    }

    /**
     * A section of the template course was updated.
     *
     * @param \core\event\course_section_updated $event The event.
     */
    public static function course_section_updated(\core\event\course_section_updated $event): void {
        if (!self::concerns_template_course($event)) {
            return;
        }
        // Usually a rename, which changes the category shown against every preset in that section.
        baker::queue_rebuild();
    }

    /**
     * The template course itself was deleted.
     *
     * @param \core\event\course_deleted $event The event.
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;

        if (!template::is_template_course((int)$event->objectid)) {
            return;
        }

        foreach (preset::get_records(['templatecourseid' => (int)$event->objectid]) as $preset) {
            baker::delete_preset($preset);
        }

        // Deleting a course removes its modules without necessarily leaving anything behind to
        // match on, so the preset details are swept by orphan-hood rather than by course.
        $DB->delete_records_select(
            meta::TABLE,
            'cmid NOT IN (SELECT id FROM {course_modules})'
        );
    }

    /**
     * Whether an event happened in the configured template course.
     *
     * @param base $event The event.
     * @return bool
     */
    protected static function concerns_template_course(base $event): bool {
        if (!template::is_enabled()) {
            return false;
        }

        return template::is_template_course((int)$event->courseid);
    }
}
