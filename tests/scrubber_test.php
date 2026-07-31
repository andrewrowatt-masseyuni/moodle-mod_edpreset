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

use mod_edpreset\local\activity_copier;
use mod_edpreset\local\backup_baker;
use mod_edpreset\local\scrub\clear_dates;
use mod_edpreset\local\validator;

/**
 * Tests for the archive scrubber and its date-clearing rule.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_edpreset\local\scrubber
 * @covers     \mod_edpreset\local\scrub\clear_dates
 */
final class scrubber_test extends \advanced_testcase {
    /**
     * Load the backup and restore APIs.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        parent::setUpBeforeClass();
    }

    /**
     * Bake an exemplar and publish it through the real validate-then-promote path.
     *
     * @param string $modname The module to use.
     * @param array $moddata Module settings, typically the dates under test.
     * @return preset
     */
    protected function publish(string $modname, array $moddata = []): preset {
        $generator = $this->getDataGenerator();

        // Some module generators (lesson, workshop) refuse to run without a real logged-in user.
        $this->setAdminUser();

        $templatecourse = $generator->create_course(['numsections' => 2]);
        $module = $generator->create_module($modname, $moddata + [
            'course' => $templatecourse->id,
            'section' => 1,
            'name' => 'Exemplar ' . $modname,
        ]);
        $exemplarcm = get_coursemodule_from_instance($modname, $module->id, $templatecourse->id);

        set_config('templatecourseid', $templatecourse->id, 'mod_edpreset');
        set_config('enabled', 1, 'mod_edpreset');

        $preset = $generator->get_plugin_generator('mod_edpreset')->create_preset([
            'templatecourseid' => $templatecourse->id,
            'templatecmid' => $exemplarcm->id,
            'modname' => $modname,
            'instanceid' => $module->id,
            'contextid' => \context_module::instance($exemplarcm->id)->id,
            'title' => 'Exemplar ' . $modname,
            'live' => false,
        ]);

        $this->setAdminUser();
        backup_baker::bake($preset);
        $this->assertTrue(validator::process($preset), 'the exemplar failed to publish');

        return preset::get_record(['id' => $preset->get('id')]);
    }

    /**
     * Copy a published preset into a fresh course and return its instance record.
     *
     * @param preset $preset The preset.
     * @return \stdClass The restored instance record.
     */
    protected function copy_and_read(preset $preset): \stdClass {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $cm = activity_copier::copy($preset, $course, 1);

        return $DB->get_record($preset->get('modname'), ['id' => $cm->instance], '*', MUST_EXIST);
    }

    /**
     * Dates set on the exemplar do not follow the copy.
     *
     * @param string $modname The module.
     * @param array $dates Field => timestamp to set on the exemplar.
     * @dataProvider dates_provider
     */
    public function test_dates_are_cleared(string $modname, array $dates): void {
        $this->resetAfterTest();

        $preset = $this->publish($modname, $dates);
        $instance = $this->copy_and_read($preset);

        foreach (array_keys($dates) as $field) {
            $this->assertSame(
                0,
                (int)$instance->$field,
                "$modname.$field survived the scrub with value {$instance->$field}"
            );
        }
    }

    /**
     * Modules and their date fields, with values well in the past.
     *
     * lesson is included specifically because its date fields (available, deadline) are named in a
     * way no sensible column-name heuristic would catch, which is why the map is curated by hand.
     *
     * @return array
     */
    public static function dates_provider(): array {
        $past = 1600000000;

        return [
            'assign' => ['assign', [
                'allowsubmissionsfromdate' => $past,
                'duedate' => $past + 86400,
                'cutoffdate' => $past + 172800,
                'gradingduedate' => $past + 259200,
            ]],
            'quiz' => ['quiz', ['timeopen' => $past, 'timeclose' => $past + 86400]],
            'choice' => ['choice', ['timeopen' => $past, 'timeclose' => $past + 86400]],
            'lesson' => ['lesson', ['available' => $past, 'deadline' => $past + 86400]],
            'feedback' => ['feedback', ['timeopen' => $past, 'timeclose' => $past + 86400]],
            'workshop' => ['workshop', [
                'submissionstart' => $past,
                'submissionend' => $past + 86400,
            ]],
        ];
    }

    /**
     * Non-date settings must survive untouched.
     *
     * This is the failure the curated map exists to prevent. A column-name heuristic over these
     * modules would have zeroed assign's sendnotifications and quiz's timelimit, silently changing
     * what the exemplar does - and validation could not catch it, because the archive still
     * restores perfectly.
     */
    public function test_non_date_settings_are_not_touched(): void {
        $this->resetAfterTest();

        $preset = $this->publish('assign', [
            'duedate' => 1600000000,
            'sendnotifications' => 1,
            'sendlatenotifications' => 1,
            'timelimit' => 3600,
        ]);
        $instance = $this->copy_and_read($preset);

        $this->assertSame(0, (int)$instance->duedate, 'sanity: the date should have been cleared');
        $this->assertSame(1, (int)$instance->sendnotifications);
        $this->assertSame(1, (int)$instance->sendlatenotifications);
        $this->assertSame(3600, (int)$instance->timelimit, 'timelimit is a duration, not a date');
    }

    /**
     * Bookkeeping timestamps must survive: the restore relies on them.
     */
    public function test_timecreated_and_timemodified_survive(): void {
        $this->resetAfterTest();

        $preset = $this->publish('quiz', ['timeopen' => 1600000000]);
        $instance = $this->copy_and_read($preset);

        $this->assertGreaterThan(0, (int)$instance->timemodified);
    }

    /**
     * What was cleared is recorded against the preset, so a curator can see it.
     */
    public function test_cleared_fields_are_recorded(): void {
        $this->resetAfterTest();

        $preset = $this->publish('assign', ['duedate' => 1600000000]);

        $this->assertSame(1, (int)$preset->get('scrubbed'));
        $this->assertStringContainsString('clear_dates', $preset->get('datescleared'));
        $this->assertStringContainsString('duedate', $preset->get('datescleared'));
    }

    /**
     * A module the map does not cover is left alone rather than guessed at.
     */
    public function test_unmapped_module_is_left_alone(): void {
        $this->resetAfterTest();

        $rule = new clear_dates();
        $this->assertFalse($rule->applies_to('definitely_not_a_real_module'));
    }

    /**
     * An admin can declare date fields for a module the map does not cover.
     */
    public function test_admin_can_add_date_fields(): void {
        $this->resetAfterTest();
        set_config('datefields', "somemod: fieldone, fieldtwo\nothermod: other", 'mod_edpreset');

        $rule = new clear_dates();

        $this->assertTrue($rule->applies_to('somemod'));
        $this->assertContains('fieldone', $rule->get_fields('somemod'));
        $this->assertContains('fieldtwo', $rule->get_fields('somemod'));
        $this->assertNotContains('other', $rule->get_fields('somemod'));
    }

    /**
     * The published archive still restores after scrubbing - the assertion that actually matters.
     *
     * Clearing a field the restore needs would be far worse than leaving a stale date behind, so
     * every module in the map is round-tripped.
     *
     * @param string $modname The module.
     * @param array $dates Dates to set on the exemplar.
     * @dataProvider dates_provider
     */
    public function test_scrubbed_archive_still_restores(string $modname, array $dates): void {
        $this->resetAfterTest();

        $preset = $this->publish($modname, $dates);

        // Publishing already proved it through the sandbox; prove it again into a real course.
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $cm = activity_copier::copy($preset, $course, 1);

        $this->assertSame($modname, $cm->modname);
        $this->assertSame(1, (int)$preset->get('scrubbed'));
    }

    /**
     * If a scrub rule breaks the restore, the untouched backup is published instead.
     *
     * This is what makes best-effort scrubbing safe: an over-aggressive rule degrades to "dates
     * not cleared" rather than "preset unavailable", automatically and per module.
     */
    public function test_a_rule_that_breaks_the_restore_falls_back_to_the_original(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $templatecourse = $generator->create_course(['numsections' => 2]);
        $module = $generator->create_module('assign', [
            'course' => $templatecourse->id,
            'section' => 1,
            'name' => 'Exemplar assign',
            'duedate' => 1600000000,
        ]);
        $exemplarcm = get_coursemodule_from_instance('assign', $module->id, $templatecourse->id);

        set_config('templatecourseid', $templatecourse->id, 'mod_edpreset');
        set_config('enabled', 1, 'mod_edpreset');

        $preset = $generator->get_plugin_generator('mod_edpreset')->create_preset([
            'templatecourseid' => $templatecourse->id,
            'templatecmid' => $exemplarcm->id,
            'modname' => 'assign',
            'instanceid' => $module->id,
            'contextid' => \context_module::instance($exemplarcm->id)->id,
            'live' => false,
        ]);

        $this->setAdminUser();
        backup_baker::bake($preset);

        // Corrupt the staged archive so it cannot restore, while leaving the untouched copy that
        // bake() kept alongside it intact. This is the shape of an over-aggressive scrub rule.
        get_file_storage()->delete_area_files(
            \context_system::instance()->id,
            'mod_edpreset',
            preset::FILEAREA_STAGING,
            $preset->get('id')
        );
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'mod_edpreset',
            'filearea' => preset::FILEAREA_STAGING,
            'itemid' => $preset->get('id'),
            'filepath' => '/',
            'filename' => 'preset_' . $preset->get('id') . '.mbz',
        ], 'a scrub rule mangled this archive beyond repair');
        $preset->set('scrubbed', 1);
        $preset->update();

        $this->assertTrue(validator::process($preset), 'the fallback should still publish');
        $this->assertDebuggingCalled();

        $preset = preset::get_record(['id' => $preset->get('id')]);
        $this->assertSame(preset::STATUS_READY, $preset->get('status'));
        $this->assertTrue($preset->is_live());
        $this->assertSame(0, (int)$preset->get('scrubbed'), 'the unscrubbed archive should be live');
        $this->assertSame(
            get_string('scrubbrokerestore', 'mod_edpreset'),
            $preset->get('datescleared')
        );

        // And the published preset genuinely works - with the exemplar's date still on it.
        $instance = $this->copy_and_read($preset);
        $this->assertSame(1600000000, (int)$instance->duedate);
    }
}
