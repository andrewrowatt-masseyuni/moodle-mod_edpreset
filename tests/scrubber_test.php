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
use mod_edpreset\local\scrub\clear_dates;
use mod_edpreset\local\scrubber;

/**
 * Tests for the scrubber and its date-clearing rule, which tidy a copy once it has been restored.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_edpreset\local\scrubber
 * @covers     \mod_edpreset\local\scrub\clear_dates
 */
final class scrubber_test extends \advanced_testcase {
    /**
     * Create an exemplar and a released preset for it.
     *
     * @param string $modname The module to use.
     * @param array $moddata Module settings, typically the dates under test.
     * @return preset
     */
    protected function exemplar(string $modname, array $moddata = []): preset {
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

        return $generator->get_plugin_generator('mod_edpreset')->create_preset([
            'templatecourseid' => $templatecourse->id,
            'templatecmid' => $exemplarcm->id,
            'modname' => $modname,
            'instanceid' => $module->id,
            'contextid' => \context_module::instance($exemplarcm->id)->id,
            'title' => 'Exemplar ' . $modname,
        ]);
    }

    /**
     * Copy a preset into a fresh course and return the copy's instance record.
     *
     * @param preset $preset The preset.
     * @return \stdClass The copy's instance record.
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

        $preset = $this->exemplar($modname, $dates);
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
     * what the copy does - and nothing would notice, because the activity still works.
     */
    public function test_non_date_settings_are_not_touched(): void {
        $this->resetAfterTest();

        $preset = $this->exemplar('assign', [
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
     * Bookkeeping timestamps are not dates to clear.
     */
    public function test_timecreated_and_timemodified_survive(): void {
        $this->resetAfterTest();

        $preset = $this->exemplar('quiz', ['timeopen' => 1600000000]);
        $instance = $this->copy_and_read($preset);

        $this->assertGreaterThan(0, (int)$instance->timemodified);
    }

    /**
     * The exemplar keeps its dates: only the copy is touched.
     */
    public function test_the_exemplar_keeps_its_dates(): void {
        global $DB;
        $this->resetAfterTest();

        $preset = $this->exemplar('assign', ['duedate' => 1600000000]);
        $this->copy_and_read($preset);

        $this->assertSame(1600000000, (int)$DB->get_field('assign', 'duedate', ['id' => $preset->get('instanceid')]));
    }

    /**
     * The copy's calendar is built from its cleared dates, not the exemplar's.
     *
     * The dates are cleared before the copier refreshes the copy's calendar events, so a due date
     * the copy no longer has must not appear on the teacher's calendar.
     */
    public function test_calendar_events_follow_the_cleared_dates(): void {
        global $DB;
        $this->resetAfterTest();

        $preset = $this->exemplar('assign', ['duedate' => time() + WEEKSECS]);
        $this->assertTrue(
            $DB->record_exists('event', ['modulename' => 'assign', 'instance' => $preset->get('instanceid'), 'eventtype' => 'due']),
            'sanity: the exemplar has a due date event'
        );

        $instance = $this->copy_and_read($preset);

        $this->assertFalse(
            $DB->record_exists('event', ['modulename' => 'assign', 'instance' => $instance->id, 'eventtype' => 'due'])
        );
    }

    /**
     * The scrubber reports what each rule changed.
     */
    public function test_scrub_reports_what_it_cleared(): void {
        $this->resetAfterTest();

        $preset = $this->exemplar('assign', ['duedate' => 1600000000, 'cutoffdate' => 0]);

        $changes = scrubber::scrub('assign', (int)$preset->get('instanceid'));

        $this->assertSame(['clear_dates' => ['duedate']], $changes);
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
     * An admin's field that is not a column of the module is skipped, not written.
     */
    public function test_a_configured_field_that_does_not_exist_is_skipped(): void {
        $this->resetAfterTest();
        set_config('datefields', 'assign: nosuchcolumn', 'mod_edpreset');

        $preset = $this->exemplar('assign', ['duedate' => 1600000000]);
        $instance = $this->copy_and_read($preset);

        $this->assertSame(0, (int)$instance->duedate, 'the mapped fields should still be cleared');
    }

    /**
     * A rule that throws is reported and skipped; it does not fail whatever asked for the scrub.
     *
     * A copy that is sitting in the teacher's course and reported as failed would be worse than a
     * copy with a stale date.
     */
    public function test_a_rule_that_throws_is_skipped(): void {
        $this->resetAfterTest();
        // A module with no table: the rule applies, then cannot read the instance.
        set_config('datefields', 'nosuchmodule: somedate', 'mod_edpreset');

        $this->assertSame([], scrubber::scrub('nosuchmodule', 1));
        $this->assertDebuggingCalled();
    }
}
