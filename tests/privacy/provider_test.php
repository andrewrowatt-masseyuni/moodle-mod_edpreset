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

namespace mod_edpreset\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Tests for the curator authorship stamp the privacy provider covers.
 *
 * Both preset tables are core\persistent subclasses, so every save records the curator in
 * usermodified. The rows themselves are site configuration and are never the requesting user's to
 * delete, so what every deletion path must do is unstamp them and leave them standing.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_edpreset\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Create a preset and a metadata row saved by a new user.
     *
     * @return array [$user, $preset, $meta]
     */
    protected function setup_curator(): array {
        static $cmid = 0;
        $cmid--;

        $generator = $this->getDataGenerator();
        $plugingenerator = $generator->get_plugin_generator('mod_edpreset');

        $user = $generator->create_user();
        $this->setUser($user);

        // Negative cmids, as the preset generator uses: no exemplar activity is needed to record a
        // stamp, and edpreset_meta.cmid is unique.
        $preset = $plugingenerator->create_preset();
        $meta = $plugingenerator->create_metadata($cmid);

        return [$user, $preset, $meta];
    }

    /**
     * Both tables holding a user field are declared, so core's table coverage check passes.
     */
    public function test_get_metadata_covers_both_tables(): void {
        $collection = provider::get_metadata(new collection('mod_edpreset'));

        $tables = [];
        foreach ($collection->get_collection() as $item) {
            if ($item instanceof database_table) {
                $tables[$item->get_name()] = array_keys($item->get_privacy_fields());
            }
        }

        $this->assertArrayHasKey('edpreset_meta', $tables);
        $this->assertArrayHasKey('edpreset_item', $tables);
        $this->assertContains('usermodified', $tables['edpreset_meta']);
        $this->assertContains('usermodified', $tables['edpreset_item']);
    }

    /**
     * A curator's stamp puts them in the system context, and exports what they saved.
     */
    public function test_export_authorship(): void {
        $this->resetAfterTest();
        [$user, $preset, $meta] = $this->setup_curator();
        $context = \context_system::instance();

        $contextlist = provider::get_contexts_for_userid((int)$user->id);
        $this->assertEquals([$context->id], $contextlist->get_contextids());

        $this->export_context_data_for_user((int)$user->id, $context, 'mod_edpreset');
        $data = writer::with_context($context)->get_data([get_string('privacy:path:authored', 'mod_edpreset')]);

        $this->assertCount(1, $data->presets);
        $this->assertEquals($preset->get('title'), $data->presets[0]['title']);
        $this->assertCount(1, $data->presetdetails);
        $this->assertEquals($meta->get('presetname'), $data->presetdetails[0]['presetname']);
    }

    /**
     * A user with no stamp anywhere is not put in the system context.
     */
    public function test_get_contexts_for_userid_without_authorship(): void {
        $this->resetAfterTest();
        $this->setup_curator();

        $other = $this->getDataGenerator()->create_user();

        $this->assertEmpty(provider::get_contexts_for_userid((int)$other->id)->get_contextids());
    }

    /**
     * The system context reports its curators, and only real ones.
     */
    public function test_get_users_in_context(): void {
        $this->resetAfterTest();
        [$user] = $this->setup_curator();
        $context = \context_system::instance();

        // An unstamped row - what a cron rebuild leaves behind - is nobody's data.
        $this->setUser(null);
        $this->getDataGenerator()->get_plugin_generator('mod_edpreset')->create_preset();

        $userlist = new userlist($context, 'mod_edpreset');
        provider::get_users_in_context($userlist);

        $this->assertEquals([(int)$user->id], $userlist->get_userids());
    }

    /**
     * Deleting one user's data unstamps their rows and leaves everyone else's alone.
     */
    public function test_delete_data_for_user_anonymises(): void {
        global $DB;

        $this->resetAfterTest();
        [$user, $preset, $meta] = $this->setup_curator();
        [$other, $otherpreset] = $this->setup_curator();
        $context = \context_system::instance();

        provider::delete_data_for_user(new approved_contextlist($user, 'mod_edpreset', [$context->id]));

        // The rows survive - they are what the chooser offers - with the curator removed.
        $this->assertEquals(0, $DB->get_field('edpreset_item', 'usermodified', ['id' => $preset->get('id')]));
        $this->assertEquals(0, $DB->get_field('edpreset_meta', 'usermodified', ['id' => $meta->get('id')]));
        $this->assertEquals(
            $other->id,
            $DB->get_field('edpreset_item', 'usermodified', ['id' => $otherpreset->get('id')])
        );
        $this->assertEquals(2, $DB->count_records('edpreset_item'));
        $this->assertEquals(2, $DB->count_records('edpreset_meta'));
    }

    /**
     * Deleting an approved set of users unstamps exactly those users.
     */
    public function test_delete_data_for_users_anonymises(): void {
        global $DB;

        $this->resetAfterTest();
        [$user, $preset] = $this->setup_curator();
        [, $otherpreset] = $this->setup_curator();
        $context = \context_system::instance();

        $userlist = new approved_userlist($context, 'mod_edpreset', [(int)$user->id]);
        provider::delete_data_for_users($userlist);

        $this->assertEquals(0, $DB->get_field('edpreset_item', 'usermodified', ['id' => $preset->get('id')]));
        $this->assertNotEquals(0, $DB->get_field('edpreset_item', 'usermodified', ['id' => $otherpreset->get('id')]));
    }

    /**
     * Deleting everything in the system context unstamps every row.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setup_curator();
        $this->setup_curator();

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertEquals(2, $DB->count_records('edpreset_item'));
        $this->assertEquals(0, $DB->count_records_select('edpreset_item', 'usermodified <> 0'));
        $this->assertEquals(0, $DB->count_records_select('edpreset_meta', 'usermodified <> 0'));
    }

    /**
     * A course context is not this plugin's, and must leave the stamps standing.
     */
    public function test_delete_data_for_all_users_in_other_context(): void {
        global $DB;

        $this->resetAfterTest();
        [$user] = $this->setup_curator();
        $course = $this->getDataGenerator()->create_course();

        provider::delete_data_for_all_users_in_context(\context_course::instance($course->id));

        $this->assertEquals(1, $DB->count_records('edpreset_item', ['usermodified' => $user->id]));
        $this->assertEquals(1, $DB->count_records('edpreset_meta', ['usermodified' => $user->id]));
    }
}
