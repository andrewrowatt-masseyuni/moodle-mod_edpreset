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

use mod_edpreset\external\set_favourite;
use mod_edpreset\output\chooser_page;

/**
 * Tests for the external functions the preset chooser page calls.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_edpreset\external\set_favourite
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
}
