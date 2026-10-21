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
 * Tests for the adhoc task the observers queue to rescan the template course.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_edpreset\task\rebuild_presets
 */
final class rebuild_presets_test extends \advanced_testcase {
    /**
     * Running the task scans the template course and says what it did.
     */
    public function test_execute_rescans_the_template_course(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->get_plugin_generator('mod_edpreset')->create_template_course([
            1 => [['modname' => 'page', 'name' => 'Exemplar page']],
        ]);

        $this->expectOutputString("mod_edpreset: 1 exemplars scanned, 0 presets removed.\n");
        (new rebuild_presets())->execute();

        $this->assertSame(['Exemplar page'], array_map(fn(preset $preset) => $preset->get('title'), preset::get_records()));
    }
}
