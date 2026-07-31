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

use core\event\base;
use mod_edpreset\local\baker;
use mod_edpreset\local\template;

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
        // A new activity has no preset row yet, so the whole course is rescanned rather than baked
        // directly. The rescan is cheap; it only queues work.
        baker::queue_rebuild();
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
        // Rescan rather than bake directly: the edit may have renamed it, hidden it or moved it to
        // another section, all of which change the preset's metadata as well as its archive.
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
        // The rescan refreshes metadata without touching any archive.
        baker::queue_rebuild();
    }

    /**
     * A grading form was created or changed.
     *
     * Editing a rubric or marking guide does not fire course_module_updated, so without this an
     * exemplar's grading form could change without its archive being refreshed until the nightly
     * reconcile.
     *
     * @param base $event The grading definition event.
     */
    public static function grading_definition_changed(base $event): void {
        if (!template::is_configured()) {
            return;
        }

        $context = $event->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm || (int)$cm->course !== template::get_courseid()) {
            return;
        }

        baker::mark_stale((int)$cm->id);
    }

    /**
     * The template course itself was deleted.
     *
     * @param \core\event\course_deleted $event The event.
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        if ((int)$event->objectid !== template::get_courseid()) {
            return;
        }

        foreach (preset::get_records(['templatecourseid' => (int)$event->objectid]) as $preset) {
            baker::delete_preset($preset);
        }
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

        $templatecourseid = template::get_courseid();

        return $templatecourseid > 0 && (int)$event->courseid === $templatecourseid;
    }
}
