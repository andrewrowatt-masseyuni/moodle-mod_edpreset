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
}
