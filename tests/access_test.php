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

use mod_edpreset\local\access;
use mod_edpreset\local\coursedefault;
use mod_edpreset\local\section_template;

/**
 * Tests for the checks copy.php applies to what it has been asked to do.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_edpreset\local\access
 */
final class access_test extends \advanced_testcase {
    /**
     * An editing teacher may copy into their own course.
     */
    public function test_can_copy_into_allows_an_editing_teacher(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->assertTrue(access::can_copy_into($course, 1));
    }

    /**
     * A student may not, and asking must answer rather than throw - callers use this to decide
     * whether to offer a link at all.
     */
    public function test_can_copy_into_refuses_a_student(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->assertFalse(access::can_copy_into($course, 1));
    }

    /**
     * The section bound is part of the same gate, so it answers here too.
     */
    public function test_can_copy_into_refuses_a_section_beyond_the_maximum(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $maxsections = course_get_format($course)->get_max_sections();

        $this->assertFalse(access::can_copy_into($course, $maxsections + 1));
    }

    /**
     * The activity chooser sends one id; the chooser page's form sends a list.
     */
    public function test_clean_presets_accepts_one_or_many(): void {
        $this->assertSame([7], access::clean_presets('7'));
        $this->assertSame([7, 3, 11], access::clean_presets('7,3,11'));
    }

    /**
     * Selection order is the order the activities end up in, so it must survive cleaning.
     */
    public function test_clean_presets_preserves_order_and_reindexes(): void {
        $ids = access::clean_presets('11,3,7');

        $this->assertSame([11, 3, 7], $ids);
        $this->assertSame([0, 1, 2], array_keys($ids));
    }

    /**
     * A repeated id copies the preset once, not twice.
     */
    public function test_clean_presets_deduplicates(): void {
        $this->assertSame([7, 3], access::clean_presets('7,3,7'));
    }

    /**
     * Zeroes and empty entries are dropped rather than looked up and failed.
     */
    public function test_clean_presets_drops_empty_entries(): void {
        $this->assertSame([7], access::clean_presets('0,7,,0'));
    }

    /**
     * Asking for nothing is a bad request, not an empty batch.
     */
    public function test_clean_presets_rejects_an_empty_list(): void {
        $this->expectException(\moodle_exception::class);
        access::clean_presets('0,,0');
    }

    /**
     * The restores run in this request, so the count has to be bounded somewhere.
     *
     * Without this a hand-edited URL could hold a PHP worker - and the user's copy lock - for as
     * long as the time limit allows.
     */
    public function test_clean_presets_rejects_more_than_the_maximum(): void {
        $ids = implode(',', range(1, access::MAX_PRESETS + 1));

        $this->expectException(\moodle_exception::class);
        access::clean_presets($ids);
    }

    /**
     * Exactly the maximum is allowed; the cap is not off by one.
     */
    public function test_clean_presets_allows_exactly_the_maximum(): void {
        $ids = range(1, access::MAX_PRESETS);

        $this->assertSame($ids, access::clean_presets(implode(',', $ids)));
    }

    /**
     * A template's activity may be added on its own only to a course built from that template.
     */
    public function test_can_add_on_its_own(): void {
        $this->resetAfterTest();
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_edpreset');
        $course = $this->getDataGenerator()->create_course();

        $individual = $plugingenerator->create_preset();
        $member = $plugingenerator->create_preset(['sectionnum' => 3, 'templatename' => 'Weekly cycle']);

        $this->assertTrue(access::can_add_on_its_own($course, $individual, '', false));
        $this->assertTrue(access::can_add_on_its_own($course, $individual, 'Weekly cycle', false));

        $this->assertTrue(access::can_add_on_its_own($course, $member, 'Weekly cycle', false));
        $this->assertFalse(access::can_add_on_its_own($course, $member, '', false));
        $this->assertFalse(access::can_add_on_its_own($course, $member, 'Induction', false));
        // An exact match, as the one-template lock makes it.
        $this->assertFalse(access::can_add_on_its_own($course, $member, 'weekly cycle', false));
        // Being able to review is no help with an activity that is not in review.
        $this->assertFalse(access::can_add_on_its_own($course, $member, '', true));
    }

    /**
     * A reviewer may add a template's activity in review on its own, wherever its template could go.
     */
    public function test_a_reviewer_can_add_a_template_activity_in_review_on_its_own(): void {
        [$course, $top, , $teacher] = $this->setup_restricted();
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_edpreset');

        $member = $plugingenerator->create_preset([
            'sectionnum' => 5,
            'templatename' => 'Weekly cycle',
            'status' => meta::STATUS_REVIEW,
        ]);
        $restricted = $plugingenerator->create_preset([
            'sectionnum' => 6,
            'templatename' => 'Learning model',
            'templaterestricted' => 1,
            'status' => meta::STATUS_REVIEW,
        ]);

        $this->setUser($teacher);
        $this->assertTrue(access::can_add_on_its_own($course, $member, '', true));
        $this->assertFalse(access::can_add_on_its_own($course, $member, '', false), 'only a reviewer may');

        // A restricted template's activity goes only where the template itself may.
        $this->assertFalse(access::can_add_on_its_own($course, $restricted, '', true));
        $this->assertTrue(access::can_add_on_its_own($course, $restricted, 'Learning model', true));

        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id, \context_coursecat::instance($top->id)->id);
        $this->setUser($manager);
        $this->assertTrue(access::can_add_on_its_own($course, $restricted, '', true));
    }

    /**
     * A course nested two categories down, an editing teacher in it, and a restricted template.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass, 3: \stdClass, 4: section_template}
     *     The course, its top-level category, its subcategory, its teacher, and the template.
     */
    private function setup_restricted(): array {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $top = $generator->create_category(['name' => 'College']);
        $sub = $generator->create_category(['name' => 'School', 'parent' => $top->id]);
        $course = $generator->create_course(['category' => $sub->id]);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');

        $template = new section_template(3, 'Learning model', '', [], true);

        return [$course, $top, $sub, $teacher, $template];
    }

    /**
     * An unrestricted template is for everyone who may copy into the course.
     */
    public function test_an_unrestricted_template_is_usable_by_a_teacher(): void {
        [$course, , , $teacher] = $this->setup_restricted();
        $this->setUser($teacher);

        $this->assertTrue(access::can_use_template($course, new section_template(3, 'Learning model', '', [])));
    }

    /**
     * A teacher cannot see a restricted template their course has not used.
     */
    public function test_a_restricted_template_is_refused_to_a_teacher(): void {
        [$course, , , $teacher, $template] = $this->setup_restricted();
        $this->setUser($teacher);

        $this->assertFalse(access::can_use_template($course, $template));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('templaterestricted', 'mod_edpreset'));
        access::require_can_use_template($course, $template);
    }

    /**
     * A course that has already used a restricted template keeps it, whoever is teaching it.
     */
    public function test_a_restricted_template_is_usable_by_a_course_that_used_it(): void {
        [$course, , , $teacher, $template] = $this->setup_restricted();
        $this->setUser($teacher);

        coursedefault::set((int)$course->id, 'Learning model');

        $this->assertTrue(access::can_use_template($course, $template));
        // The match is exact, as the one-template lock's is.
        $this->assertFalse(access::can_use_template($course, $template, 'Learning'));
        // A record the caller has already read is used in place of reading it again.
        $this->assertFalse(access::can_use_template($course, $template, 'Something else'));
    }

    /**
     * Someone who can manage activities across the course's top-level category may use it anywhere
     * beneath that category.
     */
    public function test_a_restricted_template_is_usable_from_the_top_level_category(): void {
        [$course, $top, , , $template] = $this->setup_restricted();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id, \context_coursecat::instance($top->id)->id);
        $this->setUser($manager);

        $this->assertTrue(access::can_use_template($course, $template));
    }

    /**
     * A role in an intermediate category is not enough: the rule names the top-level category.
     */
    public function test_a_restricted_template_is_refused_from_a_subcategory(): void {
        [$course, , $sub, , $template] = $this->setup_restricted();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id, \context_coursecat::instance($sub->id)->id);
        $this->setUser($manager);

        $this->assertFalse(access::can_use_template($course, $template));
    }

    /**
     * Someone who can manage activities at system level may use it in any course.
     */
    public function test_a_restricted_template_is_usable_with_a_system_role(): void {
        [$course, , , , $template] = $this->setup_restricted();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id, \context_system::instance()->id);
        $this->setUser($manager);

        $this->assertTrue(access::can_use_template($course, $template));
    }
}
