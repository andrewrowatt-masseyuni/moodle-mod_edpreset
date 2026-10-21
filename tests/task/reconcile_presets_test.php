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

namespace mod_edpreset\task;

use mod_edpreset\preset;

/**
 * Tests for the nightly task that catches the presets up with anything an observer missed.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_edpreset\task\reconcile_presets
 */
final class reconcile_presets_test extends \advanced_testcase {
    /**
     * Running the task rescans the template course, removing what has gone from it.
     */
    public function test_execute_reconciles_the_template_course(): void {
        global $DB;
        $this->resetAfterTest();
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_edpreset');
        $course = $plugingenerator->create_template_course([
            1 => [['modname' => 'page', 'name' => 'Kept page'], ['modname' => 'page', 'name' => 'Removed page']],
        ]);
        $plugingenerator->scan();

        // Gone without the observer hearing of it - which is what the task is there to catch.
        $cmid = (int)preset::get_record(['title' => 'Removed page'])->get('templatecmid');
        $DB->set_field('course_modules', 'visible', 0, ['id' => $cmid]);
        rebuild_course_cache($course->id, true);

        $this->expectOutputString("mod_edpreset: 1 exemplars scanned, 1 presets removed.\n");
        (new reconcile_presets())->execute();

        $this->assertSame(['Kept page'], array_map(fn(preset $preset) => $preset->get('title'), preset::get_records()));
    }

    /**
     * With no template course configured there is nothing to reconcile, and it says so.
     */
    public function test_execute_without_a_template_course(): void {
        $this->resetAfterTest();
        set_config('templatecourseid', 0, 'mod_edpreset');

        $this->expectOutputString("mod_edpreset: no template course configured, nothing to reconcile.\n");
        (new reconcile_presets())->execute();
    }
}
