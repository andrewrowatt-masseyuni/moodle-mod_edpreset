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

use core_course_category;
use stdClass;

/**
 * The hidden course that candidate archives are test-restored into.
 *
 * The course persists but its contents do not: it is wiped immediately before each validation.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sandbox {
    /** @var string Shortname used when none is configured. */
    public const DEFAULT_SHORTNAME = 'edpreset_restore_test';

    /** @var int The only section validations restore into. */
    public const SECTION = 1;

    /**
     * The configured shortname of the sandbox course.
     *
     * @return string
     */
    public static function get_shortname(): string {
        $shortname = trim((string)get_config('mod_edpreset', 'sandboxshortname'));
        return $shortname !== '' ? $shortname : self::DEFAULT_SHORTNAME;
    }

    /**
     * Find the sandbox course without creating it.
     *
     * @return stdClass|null
     */
    public static function find(): ?stdClass {
        global $DB;
        return $DB->get_record('course', ['shortname' => self::get_shortname()]) ?: null;
    }

    /**
     * The sandbox course, created if it does not exist.
     *
     * Resolved by shortname rather than by a stored course id on purpose. Admins delete this
     * course from time to time - it looks like clutter - and a stored id would then point at
     * nothing, or worse at whatever course later reused that id. Looking it up by name makes
     * deletion self-healing: the next validation simply recreates it.
     *
     * @return stdClass
     */
    public static function get(): stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        if ($course = self::find()) {
            return $course;
        }

        // Note that create_course() rejects a duplicate shortname, so the find-then-create
        // sequence above is its own guard. Concurrent validations are kept apart by the caller's lock.
        return create_course((object)[
            'shortname' => self::get_shortname(),
            'fullname' => get_string('sandboxfullname', 'mod_edpreset'),
            'category' => self::resolve_categoryid(),
            'format' => 'topics',
            'numsections' => self::SECTION,
            'visible' => 0,
            'summary' => get_string('sandboxsummary', 'mod_edpreset'),
            'summaryformat' => FORMAT_HTML,
        ]);
    }

    /**
     * Delete everything in the sandbox course.
     *
     * Called immediately *before* each validation rather than after. A validation that crashes,
     * times out or is killed mid-restore leaves debris behind; cleaning up on the way in means the
     * next validation starts clean however the previous one ended, whereas cleaning up on the way
     * out only works when the happy path completes.
     *
     * @param stdClass $course The sandbox course.
     */
    public static function wipe(stdClass $course): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/questionlib.php');

        $modinfo = get_fast_modinfo($course);

        foreach ($modinfo->get_cms() as $cm) {
            course_delete_module($cm->id);
        }

        // A TARGET_CURRENT_ADDING restore can add sections. Trim back to the baseline so section
        // numbers stay predictable for the next validation.
        $sectionactions = \core_courseformat\formatactions::section($course);
        $sections = $modinfo->get_section_info_all();
        foreach (array_reverse($sections) as $sectioninfo) {
            if ($sectioninfo->section > self::SECTION) {
                $sectionactions->delete($sectioninfo, true);
            }
        }

        // Deleting the modules removes a quiz's slots but leaves behind the question categories
        // the restore created in the COURSE context. Without this, a sandbox validating quiz
        // presets nightly would accumulate question banks indefinitely.
        question_delete_course($course);

        rebuild_course_cache($course->id, true);
    }

    /**
     * Whether a course is the sandbox.
     *
     * @param int $courseid The course id to test.
     * @return bool
     */
    public static function is_sandbox(int $courseid): bool {
        $sandbox = self::find();
        return $sandbox !== null && (int)$sandbox->id === $courseid;
    }

    /**
     * The category the sandbox course is created in.
     *
     * @return int
     */
    protected static function resolve_categoryid(): int {
        global $DB;

        $configured = (int)get_config('mod_edpreset', 'sandboxcategoryid');
        if ($configured && $DB->record_exists('course_categories', ['id' => $configured])) {
            return $configured;
        }

        return (int)core_course_category::get_default()->id;
    }
}
