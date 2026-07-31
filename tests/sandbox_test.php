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

use mod_edpreset\local\backup_baker;
use mod_edpreset\local\sandbox;
use mod_edpreset\local\validator;

/**
 * Tests for the validation sandbox and the validate-then-publish gate.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_edpreset\local\sandbox
 * @covers     \mod_edpreset\local\validator
 */
final class sandbox_test extends \advanced_testcase {
    /**
     * Load the backup and restore APIs.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/course/lib.php');
        parent::setUpBeforeClass();
    }

    /**
     * Create an exemplar and bake it, leaving the archive staged and awaiting validation.
     *
     * @param string $modname The module to use as the exemplar.
     * @return preset
     */
    protected function stage_preset(string $modname = 'assign'): preset {
        $generator = $this->getDataGenerator();

        $templatecourse = $generator->create_course(['numsections' => 2]);
        $module = $generator->create_module($modname, [
            'course' => $templatecourse->id,
            'section' => 1,
            'name' => 'Exemplar ' . $modname,
        ]);
        $exemplarcm = get_coursemodule_from_instance($modname, $module->id, $templatecourse->id);

        set_config('templatecourseid', $templatecourse->id, 'mod_edpreset');
        set_config('enabled', 1, 'mod_edpreset');

        $preset = $generator->get_plugin_generator('mod_edpreset')->create_preset([
            'templatecourseid' => $templatecourse->id,
            'templatecmid' => $exemplarcm->id,
            'modname' => $modname,
            'instanceid' => $module->id,
            'contextid' => \context_module::instance($exemplarcm->id)->id,
            'title' => 'Exemplar ' . $modname,
            'live' => false,
        ]);

        $this->setAdminUser();
        backup_baker::bake($preset);

        return $preset;
    }

    /**
     * A proven archive is promoted, and only then does the preset become live.
     */
    public function test_validation_publishes_a_working_archive(): void {
        $this->resetAfterTest();
        $preset = $this->stage_preset();

        $this->assertFalse($preset->is_live(), 'a staged preset must not be offered yet');

        $this->assertTrue(validator::process($preset));

        $preset = preset::get_record(['id' => $preset->get('id')]);
        $this->assertSame(preset::STATUS_READY, $preset->get('status'));
        $this->assertTrue($preset->is_live());
        $this->assertFalse($preset->get_staging_file(), 'staging should be cleared after promotion');
    }

    /**
     * A broken archive is refused, and the previously working one keeps serving.
     *
     * This is the property that lets a re-bake happen without pulling a working preset out of the
     * chooser.
     */
    public function test_failed_validation_leaves_the_previous_archive_serving(): void {
        $this->resetAfterTest();
        $preset = $this->stage_preset();
        validator::process($preset);

        $goodhash = $preset->get('backupcontenthash');
        $this->assertTrue($preset->is_live());

        // Stage something that cannot possibly restore.
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'mod_edpreset',
            'filearea' => preset::FILEAREA_STAGING,
            'itemid' => $preset->get('id'),
            'filepath' => '/',
            'filename' => 'preset_' . $preset->get('id') . '.mbz',
        ], 'this is definitely not a valid moodle backup archive');

        $this->assertFalse(validator::process($preset));

        // Core's zip handling emits a debugging notice for a file that is not an archive. That is
        // the expected path here - the point is that it is caught and turned into a failed
        // validation rather than escaping to the caller.
        $this->assertDebuggingCalled();

        $preset = preset::get_record(['id' => $preset->get('id')]);
        $this->assertSame(preset::STATUS_FAILED, $preset->get('status'));
        $this->assertNotEmpty($preset->get('statusdetail'));
        $this->assertSame($goodhash, $preset->get('backupcontenthash'));
        $this->assertTrue($preset->is_live(), 'the working archive must keep serving');
    }

    /**
     * The sandbox is wiped before each validation, so nothing carries over between runs.
     *
     * This is the executable form of "no contamination from a previous restore": an activity, an
     * extra section and a course-context question category are all left behind deliberately, and
     * none of them may survive into the next validation.
     */
    public function test_sandbox_is_wiped_before_each_validation(): void {
        global $DB;
        $this->resetAfterTest();
        $preset = $this->stage_preset();

        $sandbox = sandbox::get();

        // Leave debris of exactly the kinds a previous validation could leave.
        $this->getDataGenerator()->create_module('page', ['course' => $sandbox->id, 'section' => 1]);
        \core_courseformat\formatactions::section($sandbox)->create_if_missing([2, 3]);
        $this->getDataGenerator()->get_plugin_generator('core_question')->create_question_category(
            ['contextid' => \context_course::instance($sandbox->id)->id]
        );

        $sandboxcontext = \context_course::instance($sandbox->id);
        $this->assertNotEmpty(get_fast_modinfo($sandbox)->get_cms(), 'sanity: debris was created');
        $this->assertTrue($DB->record_exists('question_categories', ['contextid' => $sandboxcontext->id]));

        sandbox::wipe(sandbox::get());

        $modinfo = get_fast_modinfo($sandbox->id);
        $this->assertEmpty($modinfo->get_cms(), 'activities survived the wipe');
        $this->assertCount(
            sandbox::SECTION + 1,
            $modinfo->get_section_info_all(),
            'extra sections survived the wipe'
        );
        $this->assertFalse(
            $DB->record_exists('question_categories', ['contextid' => $sandboxcontext->id]),
            'course-context question categories survived the wipe'
        );

        // And a validation still succeeds afterwards.
        $this->assertTrue(validator::process($preset));
    }

    /**
     * The sandbox is left empty once a validation has finished.
     */
    public function test_sandbox_is_empty_after_validation(): void {
        $this->resetAfterTest();
        $preset = $this->stage_preset();

        validator::process($preset);

        $sandbox = sandbox::find();
        $this->assertNotNull($sandbox);
        $this->assertEmpty(get_fast_modinfo($sandbox->id)->get_cms());
    }

    /**
     * An admin deleting the sandbox is survivable: it is recreated on next use.
     */
    public function test_sandbox_is_recreated_after_deletion(): void {
        $this->resetAfterTest();
        $preset = $this->stage_preset();

        $original = sandbox::get();
        delete_course($original->id, false);
        $this->assertNull(sandbox::find(), 'sanity: the sandbox was deleted');

        $this->assertTrue(validator::process($preset), 'validation must survive a deleted sandbox');

        $recreated = sandbox::find();
        $this->assertNotNull($recreated);
        $this->assertNotEquals($original->id, $recreated->id);
        $this->assertSame(sandbox::DEFAULT_SHORTNAME, $recreated->shortname);
    }

    /**
     * An existing course with the configured shortname is reused, never duplicated.
     */
    public function test_existing_sandbox_is_reused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $first = sandbox::get();
        $second = sandbox::get();

        $this->assertSame((int)$first->id, (int)$second->id);
    }

    /**
     * The sandbox is hidden, so nobody stumbles into it.
     */
    public function test_sandbox_is_hidden(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertSame(0, (int)sandbox::get()->visible);
    }

    /**
     * A configured shortname is honoured.
     */
    public function test_configured_shortname_is_used(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sandboxshortname', 'my_own_restore_test', 'mod_edpreset');

        $this->assertSame('my_own_restore_test', sandbox::get()->shortname);
    }
}
