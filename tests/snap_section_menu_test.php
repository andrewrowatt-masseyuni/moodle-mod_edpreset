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
 * Tests that theme_snap's section menu really carries the "Apply template" item.
 *
 * The item depends on a two-line patch to vendor code that a theme upgrade replaces wholesale
 * (README.md, "The theme_snap section menu"), so the point of this test is to notice when that
 * patch has gone - which nothing else would. It skips where theme_snap is not installed, which
 * includes CI: the plugin does not depend on the theme.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_edpreset\local\section_action
 */
final class snap_section_menu_test extends \advanced_testcase {
    /**
     * Skip unless theme_snap is installed on this site.
     */
    public function setUp(): void {
        global $CFG;

        parent::setUp();
        section_action::reset_caches();

        if (!file_exists($CFG->dirroot . '/theme/snap/classes/output/format_section_trait.php')) {
            $this->markTestSkipped('theme_snap is not installed.');
        }
    }

    /**
     * Render one section's action controls the way Snap's course page does.
     *
     * @param \stdClass $course The course.
     * @param int $sectionnum The section number.
     * @return string The controls, concatenated.
     */
    protected function render_controls(\stdClass $course, int $sectionnum): string {
        global $PAGE;

        $PAGE->set_url('/course/view.php', ['id' => $course->id]);
        $PAGE->set_course($course);
        $PAGE->force_theme('snap');

        $renderer = $PAGE->get_renderer('format_topics');
        $section = get_fast_modinfo($course)->get_section_info($sectionnum, MUST_EXIST);

        // The whole menu is assembled inside this one protected method, and there is no public way
        // in short of rendering a course page.
        $method = new \ReflectionMethod($renderer, 'section_edit_control_items');
        $method->setAccessible(true);

        return implode("\n", $method->invoke($renderer, $course, $section, false));
    }

    /**
     * A teacher sees the item in the extra actions dropdown, linking to this section's templates.
     */
    public function test_the_item_appears_in_the_extra_actions_menu(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $generator->get_plugin_generator('mod_edpreset')->create_template_course();

        $course = $generator->create_course(['format' => 'topics', 'numsections' => 3]);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $sectionid = get_fast_modinfo($course)->get_section_info(1, MUST_EXIST)->id;
        $html = $this->render_controls($course, 1);

        $this->assertStringContainsString('extra-actions-menu', $html);
        $this->assertStringContainsString(get_string('section:applytemplate', 'mod_edpreset'), $html);
        $this->assertStringContainsString('edpreset-applytemplate', $html);
        $this->assertStringContainsString('/mod/edpreset/chooser.php?sectionid=' . $sectionid, $html);
        // The theme's own actions must survive the patch.
        $this->assertStringContainsString('snap-permalink', $html);
    }

    /**
     * With nothing to offer, the menu is exactly as the theme built it.
     */
    public function test_the_item_is_absent_when_no_template_course_is_configured(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course(['format' => 'topics', 'numsections' => 3]);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $html = $this->render_controls($course, 1);

        $this->assertStringContainsString('extra-actions-menu', $html);
        $this->assertStringNotContainsString('edpreset', $html);
    }
}
