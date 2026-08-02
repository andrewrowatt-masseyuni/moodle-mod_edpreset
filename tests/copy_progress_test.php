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

/**
 * Tests for the copy page's progress display.
 *
 * @package    mod_edpreset
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_edpreset;

use mod_edpreset\local\copy_progress;

/**
 * Tests for the copy page's progress display.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_edpreset\local\copy_progress
 */
final class copy_progress_test extends \advanced_testcase {
    /**
     * Load the clock-controlled subclass used by the slow-copy test.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/edpreset/tests/fixtures/testable_copy_progress.php');
        parent::setUpBeforeClass();
    }

    /**
     * A copy that finishes quickly prints nothing at all.
     *
     * This is what lets copy.php finish with a real 303. Print the page header up front instead and
     * moodle_page moves to STATE_IN_BODY, after which redirect() cannot send a Location header and
     * complains that output has already started - a debugging message that is a hard failure on a
     * site running with developer debugging.
     */
    public function test_a_fast_copy_prints_nothing(): void {
        $this->resetAfterTest();

        $progress = new copy_progress('Creating activity', 'Copying');
        $progress->set_display_names();

        ob_start();
        $progress->start_progress('', 2);
        $progress->progress(1);
        $progress->progress(2);
        $progress->end_progress();
        $output = ob_get_clean();

        $this->assertSame('', $output);
        $this->assertFalse($progress->has_printed_header());
    }

    /**
     * A copy slow enough to draw a bar prints the page header first.
     *
     * The bar cannot be the first thing on the page, so whatever triggers it has to bring the
     * header with it.
     */
    public function test_a_slow_copy_prints_the_header_before_the_bar(): void {
        $this->resetAfterTest();

        // A negative delay is already overdue, which is the state a genuinely slow restore reaches
        // once its delay expires - without the test sleeping for the default five seconds.
        $progress = new \testable_copy_progress('Creating activity', 'Copying', -1);
        $progress->set_display_names();

        ob_start();
        $progress->start_progress('', 2);
        $progress->progress(1);
        $progress->end_progress();
        $output = ob_get_clean();

        $this->assertTrue($progress->has_printed_header());
        $this->assertStringContainsString('Creating activity', $output);
        // The header has to come first, or the bar lands outside the page.
        $this->assertLessThan(
            strpos($output, 'core_progress_display_if_slow'),
            strpos($output, 'Creating activity')
        );
    }
}
