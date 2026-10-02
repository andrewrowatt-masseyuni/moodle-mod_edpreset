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

use mod_edpreset\external\get_template_items;
use mod_edpreset\external\set_favourite;
use mod_edpreset\external\set_status;
use mod_edpreset\local\coursedefault;
use mod_edpreset\output\chooser_page;

/**
 * Tests for the external functions the preset chooser page calls.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_edpreset\external\set_favourite
 * @covers     \mod_edpreset\external\get_template_items
 * @covers     \mod_edpreset\external\set_status
 * @covers     \mod_edpreset\local\review
 */
final class external_test extends \advanced_testcase {
    /**
     * Starring is idempotent in both directions.
     *
     * Neither core call is safe to repeat: create_favourite() inserts straight into a table with a
     * unique index, and delete_favourite() throws when there is nothing to delete. A double-click
     * on the star, or the same page open twice, would otherwise produce a 500.
     */
    public function test_set_favourite_is_idempotent(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingenerator = $generator->get_plugin_generator('mod_edpreset');

        $templatecourse = $plugingenerator->create_template_course();
        $preset = $plugingenerator->create_preset([
            'templatecourseid' => $templatecourse->id,
            'sectionnum' => 2,
        ]);
        $presetid = (int)$preset->get('id');

        $user = $generator->create_user();
        $this->setUser($user);

        $criteria = [
            'component' => preset::FAVOURITE_COMPONENT,
            'itemtype' => preset::FAVOURITE_ITEMTYPE,
            'itemid' => $presetid,
            'userid' => $USER->id,
        ];

        $this->assertSame(['favourite' => true], set_favourite::execute($presetid, true));
        $this->assertSame(['favourite' => true], set_favourite::execute($presetid, true));
        $this->assertSame(1, $DB->count_records('favourite', $criteria));
        $this->assertSame([$presetid], chooser_page::get_favourited_ids());

        $this->assertSame(['favourite' => false], set_favourite::execute($presetid, false));
        $this->assertSame(['favourite' => false], set_favourite::execute($presetid, false));
        $this->assertSame(0, $DB->count_records('favourite', $criteria));
        $this->assertSame([], chooser_page::get_favourited_ids());
    }

    /**
     * One user's stars are not another's.
     */
    public function test_favourites_are_per_user(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingenerator = $generator->get_plugin_generator('mod_edpreset');

        $templatecourse = $plugingenerator->create_template_course();
        $preset = $plugingenerator->create_preset([
            'templatecourseid' => $templatecourse->id,
            'sectionnum' => 2,
        ]);

        $this->setUser($generator->create_user());
        set_favourite::execute((int)$preset->get('id'), true);
        $this->assertSame([(int)$preset->get('id')], chooser_page::get_favourited_ids());

        $this->setUser($generator->create_user());
        $this->assertSame([], chooser_page::get_favourited_ids());
    }

    /**
     * A preset that does not exist cannot be starred.
     *
     * Nothing sweeps up a favourite row pointing at nothing, so it has to be refused up front.
     */
    public function test_set_favourite_rejects_an_unknown_preset(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        set_favourite::execute(99999, true);
    }

    /**
     * A course with a reviewer in it, and a preset ready for review.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: preset} The course, the reviewer, and the preset.
     */
    private function setup_review(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $plugingenerator = $generator->get_plugin_generator('mod_edpreset');

        $plugingenerator->create_template_course([
            1 => [['modname' => 'page', 'name' => 'Revised page', 'meta' => ['status' => meta::STATUS_REVIEW]]],
        ]);
        $plugingenerator->scan();

        $course = $generator->create_course();
        $reviewer = $generator->create_and_enrol($course, 'editingteacher');
        assign_capability(
            'mod/edpreset:reviewpresets',
            CAP_ALLOW,
            $DB->get_field('role', 'id', ['shortname' => 'editingteacher']),
            \context_course::instance($course->id)
        );
        return [$course, $reviewer, preset::get_record(['title' => 'Revised page'])];
    }

    /**
     * A reviewer can release a preset in review, or return it as a draft, and it takes effect at once.
     *
     * @param string $status The outcome.
     * @dataProvider review_outcome_provider
     */
    public function test_set_status_decides_a_review(string $status): void {
        $this->resetAfterTest();
        [$course, $reviewer, $preset] = $this->setup_review();
        $this->setUser($reviewer);

        $result = set_status::execute((int)$preset->get('id'), (int)$course->id, $status);

        $this->assertSame(['status' => $status], $result);
        // Both the curator's details, which are the source of truth, and the preset itself.
        $this->assertSame($status, meta::get_for_cm((int)$preset->get('templatecmid'))->get('status'));
        $this->assertSame($status, preset::get_record(['id' => $preset->get('id')])->get('status'));
    }

    /**
     * The two ways a review can end.
     *
     * @return array
     */
    public static function review_outcome_provider(): array {
        return [
            'released' => [meta::STATUS_RELEASED],
            'returned as a draft' => [meta::STATUS_DRAFT],
        ];
    }

    /**
     * Only someone who can review presets in the course may decide a review.
     */
    public function test_set_status_needs_the_review_capability(): void {
        $this->resetAfterTest();
        [$course, , $preset] = $this->setup_review();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'teacher'));

        $this->expectException(\required_capability_exception::class);
        set_status::execute((int)$preset->get('id'), (int)$course->id, meta::STATUS_RELEASED);
    }

    /**
     * Only a preset still in review can be decided on, so a stale page cannot withdraw a release.
     */
    public function test_set_status_refuses_a_preset_no_longer_in_review(): void {
        $this->resetAfterTest();
        [$course, $reviewer, $preset] = $this->setup_review();
        $this->setUser($reviewer);
        set_status::execute((int)$preset->get('id'), (int)$course->id, meta::STATUS_RELEASED);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('notinreview', 'mod_edpreset'));
        set_status::execute((int)$preset->get('id'), (int)$course->id, meta::STATUS_DRAFT);
    }

    /**
     * A review ends in release or a draft, nothing else.
     */
    public function test_set_status_refuses_any_other_status(): void {
        $this->resetAfterTest();
        [$course, $reviewer, $preset] = $this->setup_review();
        $this->setUser($reviewer);

        $this->expectException(\moodle_exception::class);
        set_status::execute((int)$preset->get('id'), (int)$course->id, meta::STATUS_ARCHIVED);
    }

    /**
     * The reorder dialogue lists only the template members the user would be given.
     */
    public function test_get_template_items_lists_only_offered_members(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingenerator = $generator->get_plugin_generator('mod_edpreset');

        $templatecourse = $plugingenerator->create_template_course();
        $member = ['templatecourseid' => $templatecourse->id, 'sectionnum' => 3, 'templatename' => 'Weekly cycle'];
        $released = $plugingenerator->create_preset($member);
        $plugingenerator->create_preset($member + ['status' => meta::STATUS_DRAFT]);
        $plugingenerator->create_preset($member + ['status' => meta::STATUS_REVIEW]);

        $course = $generator->create_course(['numsections' => 2]);
        $this->setUser($generator->create_and_enrol($course, 'editingteacher'));

        $result = get_template_items::execute((int)$course->id, 1, 3);
        $this->assertSame(['p' . $released->get('id')], array_column($result['templateitems'], 'token'));
    }

    /**
     * The reorder dialogue lists a restricted template's activities only where the chooser shows it.
     */
    public function test_get_template_items_respects_a_restricted_template(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingenerator = $generator->get_plugin_generator('mod_edpreset');

        $templatecourse = $plugingenerator->create_template_course();
        $plugingenerator->create_preset([
            'templatecourseid' => $templatecourse->id,
            'sectionnum' => 3,
            'templatename' => 'Learning model',
            'templaterestricted' => 1,
        ]);

        // A course that has used the template gets its activities.
        $used = $generator->create_course(['numsections' => 2]);
        coursedefault::set((int)$used->id, 'Learning model');
        $this->setUser($generator->create_and_enrol($used, 'editingteacher'));

        $result = get_template_items::execute((int)$used->id, 1, 3);
        $this->assertCount(1, $result['templateitems']);

        // One that has not is refused.
        $fresh = $generator->create_course(['numsections' => 2]);
        $this->setUser($generator->create_and_enrol($fresh, 'editingteacher'));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('templaterestricted', 'mod_edpreset'));
        get_template_items::execute((int)$fresh->id, 1, 3);
    }
}
