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

use mod_edpreset\local\section_action;

/**
 * Tests for the "Apply template" item offered on a course section's action menu.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_edpreset\local\section_action
 */
final class section_action_test extends \advanced_testcase {
    /**
     * The class caches its answer per course, and these cases change user and configuration.
     */
    public function setUp(): void {
        parent::setUp();
        section_action::reset_caches();
    }

    /**
     * Set up a template course and an ordinary course with a teacher in it.
     *
     * @return array [$course, $teacher, $templatecourse]
     */
    protected function setup_site(): array {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingenerator = $generator->get_plugin_generator('mod_edpreset');

        $templatecourse = $plugingenerator->create_template_course();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');

        return [$course, $teacher, $templatecourse];
    }

    /**
     * The section the theme is drawing a menu for.
     *
     * @param \stdClass $course The course.
     * @param int $sectionnum The section number.
     * @return \section_info
     */
    protected function section(\stdClass $course, int $sectionnum): \section_info {
        return get_fast_modinfo($course)->get_section_info($sectionnum, MUST_EXIST);
    }

    /**
     * A teacher gets one item pointing at the templates-only chooser for that section.
     */
    public function test_for_snap_offers_the_item_to_a_teacher(): void {
        [$course, $teacher] = $this->setup_site();
        $this->setUser($teacher);
        $section = $this->section($course, 1);

        $items = section_action::for_snap($course, $section);

        $this->assertCount(1, $items);
        $item = reset($items);
        $this->assertSame(get_string('section:applytemplate', 'mod_edpreset'), $item->title);
        $this->assertTrue($item->isinmenu);
        $this->assertSame('edpreset-applytemplate', $item->class);
        // The section id resolves the course and the section number, and selects the
        // templates-only form of the page; the sesskey is what chooser.php requires of every link.
        $this->assertSame((int)$section->id, (int)$item->url->param('sectionid'));
        $this->assertSame(sesskey(), $item->url->param('sesskey'));
        $this->assertStringEndsWith('/mod/edpreset/chooser.php', $item->url->out_omit_querystring());
    }

    /**
     * A student may not add activities, so there is nothing to offer them.
     */
    public function test_for_snap_offers_nothing_to_a_student(): void {
        [$course] = $this->setup_site();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->assertSame([], section_action::for_snap($course, $this->section($course, 1)));
    }

    /**
     * Inside the template course the curator is editing the exemplars, not copying them.
     */
    public function test_for_snap_offers_nothing_in_the_template_course(): void {
        [, , $templatecourse] = $this->setup_site();
        $this->setAdminUser();

        $this->assertSame([], section_action::for_snap($templatecourse, $this->section($templatecourse, 1)));
    }

    /**
     * With no template course configured the plugin offers nothing anywhere.
     */
    public function test_for_snap_offers_nothing_without_a_template_course(): void {
        [$course, $teacher] = $this->setup_site();
        $this->setUser($teacher);
        set_config('templatecourseid', 0, 'mod_edpreset');
        section_action::reset_caches();

        $this->assertSame([], section_action::for_snap($course, $this->section($course, 1)));
    }

    /**
     * Switching the plugin off takes the menu item with it.
     */
    public function test_for_snap_offers_nothing_when_the_plugin_is_disabled(): void {
        [$course, $teacher] = $this->setup_site();
        $this->setUser($teacher);
        set_config('enabled', 0, 'mod_edpreset');
        section_action::reset_caches();

        $this->assertSame([], section_action::for_snap($course, $this->section($course, 1)));
    }
}
