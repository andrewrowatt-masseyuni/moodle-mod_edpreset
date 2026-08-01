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

use mod_edpreset\output\chooser_page;

/**
 * Tests for the preset chooser page's renderable.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_edpreset\output\chooser_page
 */
final class chooser_page_test extends \advanced_testcase {
    /**
     * Export a page's context for a course and section.
     *
     * @param \stdClass $course The course.
     * @param int $sectionnum The section the chooser was opened from.
     * @return \stdClass
     */
    private function export(\stdClass $course, int $sectionnum): \stdClass {
        global $PAGE;

        return (new chooser_page($course, $sectionnum))->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * A batch add returns to the section page, not to the course page.
     *
     * The course page carrying expandsection= and a #section-N fragment is not good enough:
     * expandsection only un-collapses a section that was collapsed to begin with, and the fragment
     * is resolved before the reactive course editor has rendered the section it points at. Either
     * way the teacher ends up at the top of their course.
     */
    public function test_return_url_is_the_section_page(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 4, 'format' => 'topics']);
        $sectionid = get_fast_modinfo($course)->get_section_info(3)->id;

        $returnurl = $this->export($course, 3)->returnurl;

        $this->assertStringContainsString('/course/section.php', $returnurl);
        $this->assertStringContainsString('id=' . $sectionid, $returnurl);
    }

    /**
     * Section 0 has no section page worth visiting, so it falls back to the course page.
     */
    public function test_return_url_for_section_zero_is_the_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 4, 'format' => 'topics']);

        $returnurl = $this->export($course, 0)->returnurl;

        $this->assertStringContainsString('/course/view.php', $returnurl);
        $this->assertStringNotContainsString('/course/section.php', $returnurl);
    }

    /**
     * A section that does not exist yet has no id to link to, so it falls back to the course page.
     *
     * The return URL is built when the page is rendered, but the section is only created when the
     * first activity lands in it, so this is reachable rather than theoretical.
     */
    public function test_return_url_for_a_missing_section_is_the_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['numsections' => 2, 'format' => 'topics']);

        $returnurl = $this->export($course, 5)->returnurl;

        $this->assertStringContainsString('/course/view.php', $returnurl);
        $this->assertStringNotContainsString('/course/section.php', $returnurl);
    }
}
