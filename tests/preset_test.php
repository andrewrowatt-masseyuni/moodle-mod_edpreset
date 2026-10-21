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

/**
 * Tests for the preset persistent.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_edpreset\preset
 */
final class preset_test extends \advanced_testcase {
    /**
     * A preset id from a request finds a preset only in the configured template course.
     *
     * A copy backs its exemplar up as the administrator, so a row an earlier template course left
     * behind must not be reachable by its id - the course may by now be an ordinary one.
     */
    public function test_get_from_template_course(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingenerator = $generator->get_plugin_generator('mod_edpreset');

        $templatecourse = $plugingenerator->create_template_course();
        $current = $plugingenerator->create_preset(['templatecourseid' => $templatecourse->id]);
        $disabled = $plugingenerator->create_preset(['templatecourseid' => $templatecourse->id, 'enabled' => 0]);
        $stale = $plugingenerator->create_preset(['templatecourseid' => $generator->create_course()->id]);

        $found = preset::get_from_template_course((int)$current->get('id'));
        $this->assertSame((int)$current->get('id'), (int)$found->get('id'));
        $this->assertNull(preset::get_from_template_course((int)$disabled->get('id')));
        $this->assertNull(preset::get_from_template_course((int)$stale->get('id')));
        $this->assertNull(preset::get_from_template_course(-1));

        set_config('templatecourseid', 0, 'mod_edpreset');
        $this->assertNull(preset::get_from_template_course((int)$current->get('id')));
    }

    /**
     * Text from a template course that has since been deleted still formats, in the system context.
     *
     * Such rows outlive the course until the next rescan, and the manage page lists them meanwhile.
     */
    public function test_formatting_survives_a_deleted_template_course(): void {
        $this->resetAfterTest();
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_edpreset');

        $preset = $plugingenerator->create_preset([
            'templatecourseid' => 999999,
            'category' => 'Tips & tricks',
            'sectionsummary' => '<p>Summary</p>',
        ]);

        $this->assertSame('Tips & tricks', $preset->get_formatted_category());
        $this->assertSame('<p>Summary</p>', $preset->get_formatted_sectionsummary());
    }
}
