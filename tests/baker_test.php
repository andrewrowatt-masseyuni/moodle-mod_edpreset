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

use mod_edpreset\local\baker;
use mod_edpreset\local\template;

/**
 * Tests for scanning the template course into preset records.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_edpreset\local\baker
 * @covers     \mod_edpreset\observer
 */
final class baker_test extends \advanced_testcase {

    /**
     * Load the backup and restore APIs.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        parent::setUpBeforeClass();
    }

    /**
     * A template course with named sections and a spread of activities.
     *
     * @return \stdClass
     */
    protected function make_template_course(): \stdClass {
        $generator = $this->getDataGenerator();
        $this->setAdminUser();

        $course = $generator->create_course(['numsections' => 3, 'format' => 'topics']);

        // Section 0 is reserved for curator instructions and must never become a preset.
        $generator->create_module('page', [
            'course' => $course->id, 'section' => 0, 'name' => 'How to use this course',
        ]);

        $generator->create_module('assign', [
            'course' => $course->id, 'section' => 1, 'name' => 'Reflective journal',
        ]);
        $generator->create_module('forum', [
            'course' => $course->id, 'section' => 1, 'name' => 'Discussion starter',
        ]);
        $generator->create_module('quiz', [
            'course' => $course->id, 'section' => 2, 'name' => 'Practice quiz',
        ]);

        // Name the sections; these become the preset categories.
        $formatactions = \core_courseformat\formatactions::section($course);
        $modinfo = get_fast_modinfo($course);
        $formatactions->update($modinfo->get_section_info(1), ['name' => 'Formative assessment']);
        $formatactions->update($modinfo->get_section_info(2), ['name' => 'Knowledge check']);

        set_config('templatecourseid', $course->id, 'mod_edpreset');
        set_config('enabled', 1, 'mod_edpreset');

        return $course;
    }

    /**
     * Scanning finds the exemplars, and only the exemplars.
     */
    public function test_rebuild_scans_sections_one_and_above(): void {
        $this->resetAfterTest();
        $this->make_template_course();

        $result = baker::rebuild();

        $this->assertSame(3, $result['scanned']);

        $titles = array_map(fn($p) => $p->get('title'), preset::get_records());
        sort($titles);
        $this->assertSame(['Discussion starter', 'Practice quiz', 'Reflective journal'], $titles);
        $this->assertNotContains('How to use this course', $titles, 'section 0 must be ignored');
    }

    /**
     * The section name becomes the preset's category.
     */
    public function test_section_names_become_categories(): void {
        $this->resetAfterTest();
        $this->make_template_course();

        baker::rebuild();

        $journal = preset::get_record(['title' => 'Reflective journal']);
        $quiz = preset::get_record(['title' => 'Practice quiz']);

        $this->assertSame('Formative assessment', $journal->get('category'));
        $this->assertSame('Knowledge check', $quiz->get('category'));
    }

    /**
     * The category also lands in the description, which is what makes it searchable.
     *
     * The chooser's search matches descriptions as well as titles, so this is how a teacher can
     * find every activity in a pedagogical category without a dedicated tab.
     */
    public function test_category_is_searchable_via_the_description(): void {
        $this->resetAfterTest();
        set_config('showcategoryinhelp', 1, 'mod_edpreset');
        $this->make_template_course();

        baker::rebuild();

        $journal = preset::get_record(['title' => 'Reflective journal']);
        $this->assertStringContainsString('Formative assessment', $journal->get('help'));
    }

    /**
     * Hidden sections and hidden activities are left out, so a curator can stage work in progress.
     */
    public function test_hidden_content_is_excluded(): void {
        $this->resetAfterTest();
        $course = $this->make_template_course();

        $modinfo = get_fast_modinfo($course);
        \core_courseformat\formatactions::section($course)->update(
            $modinfo->get_section_info(2),
            ['visible' => 0]
        );
        set_coursemodule_visible($modinfo->instances['forum'][array_key_first($modinfo->instances['forum'])]->id, 0);
        rebuild_course_cache($course->id, true);

        baker::rebuild();

        $titles = array_map(fn($p) => $p->get('title'), preset::get_records());
        $this->assertSame(['Reflective journal'], array_values($titles));
    }

    /**
     * Preset ids must survive a rebuild.
     *
     * User favourites and recommendations key on the preset id, so delete-and-reinsert would
     * silently re-point everyone's starred activities at different presets.
     */
    public function test_rebuild_preserves_preset_ids(): void {
        $this->resetAfterTest();
        $this->make_template_course();

        baker::rebuild();
        $before = [];
        foreach (preset::get_records() as $preset) {
            $before[(int)$preset->get('templatecmid')] = (int)$preset->get('id');
        }

        baker::rebuild();
        $after = [];
        foreach (preset::get_records() as $preset) {
            $after[(int)$preset->get('templatecmid')] = (int)$preset->get('id');
        }

        ksort($before);
        ksort($after);
        $this->assertSame($before, $after);
    }

    /**
     * Removing an exemplar removes its preset, its files and its favourites.
     */
    public function test_removed_exemplar_is_cleaned_up(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->make_template_course();

        baker::rebuild();
        $preset = preset::get_record(['title' => 'Reflective journal']);
        $presetid = (int)$preset->get('id');

        // Star it, as a user would.
        $DB->insert_record('favourite', (object)[
            'component' => 'core_course',
            'itemtype' => 'contentitem_mod_edpreset',
            'itemid' => $presetid,
            'contextid' => \context_system::instance()->id,
            'userid' => get_admin()->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        course_delete_module((int)$preset->get('templatecmid'));
        rebuild_course_cache($course->id, true);

        baker::rebuild();

        $this->assertFalse(preset::get_record(['id' => $presetid]));
        $this->assertFalse($DB->record_exists('favourite', [
            'component' => 'core_course',
            'itemtype' => 'contentitem_mod_edpreset',
            'itemid' => $presetid,
        ]));
    }

    /**
     * Scanning queues one bake per exemplar rather than baking inline.
     */
    public function test_rebuild_queues_bakes(): void {
        $this->resetAfterTest();
        $this->make_template_course();

        $result = baker::rebuild();

        $this->assertSame(3, $result['queued']);
        $queued = \core\task\manager::get_adhoc_tasks(\mod_edpreset\task\bake_preset::class);
        $this->assertCount(3, $queued);
    }

    /**
     * Run an action and deliver whatever events it fires to this plugin's observers.
     *
     * The observers are registered as external (internal => false) deliberately: they queue adhoc
     * tasks, which must not be queued from inside a transaction that could still roll back. Moodle
     * only dispatches external observers once no transaction is open
     * (\core\event\manager::process_buffers), and its PHPUnit harness holds one open for the whole
     * test - so an external observer can never fire by itself in a test.
     *
     * Capturing the real events and routing them through the mapping declared in db/events.php
     * gets the same coverage, and has the side benefit of failing if that wiring is ever wrong.
     *
     * @param callable $action The thing that fires the events.
     */
    protected function deliver_events(callable $action): void {
        global $CFG;

        $observers = [];
        require($CFG->dirroot . '/mod/edpreset/db/events.php');

        $sink = $this->redirectEvents();
        $action();
        $events = $sink->get_events();
        $sink->close();

        foreach ($events as $event) {
            foreach ($observers as $observer) {
                if (ltrim($observer['eventname'], '\\') !== ltrim(get_class($event), '\\')) {
                    continue;
                }
                $callback = explode('::', ltrim($observer['callback'], '\\'));
                call_user_func([$callback[0], $callback[1]], $event);
            }
        }
    }

    /**
     * Discard anything already queued, so a later assertion counts only new work.
     */
    protected function clear_queued_tasks(): void {
        global $DB;
        $DB->delete_records('task_adhoc');
    }

    /**
     * Editing an exemplar queues a rescan, and leaves the live archive serving meanwhile.
     */
    public function test_editing_an_exemplar_queues_work(): void {
        $this->resetAfterTest();
        $course = $this->make_template_course();
        baker::rebuild();
        $this->clear_queued_tasks();

        $modinfo = get_fast_modinfo($course);
        $assigns = $modinfo->instances['assign'];
        $cm = reset($assigns);
        $this->deliver_events(fn() => set_coursemodule_name($cm->id, 'Renamed journal'));

        $this->assertNotEmpty(
            \core\task\manager::get_adhoc_tasks(\mod_edpreset\task\rebuild_presets::class),
            'renaming an exemplar should queue a rescan'
        );
    }

    /**
     * Deleting an exemplar removes its preset straight away.
     */
    public function test_deleting_an_exemplar_removes_the_preset(): void {
        $this->resetAfterTest();
        $course = $this->make_template_course();
        baker::rebuild();

        $preset = preset::get_record(['title' => 'Reflective journal']);
        $presetid = (int)$preset->get('id');

        $this->deliver_events(fn() => course_delete_module((int)$preset->get('templatecmid')));
        rebuild_course_cache($course->id, true);

        $this->assertFalse(preset::get_record(['id' => $presetid]));
    }

    /**
     * Events outside the template course are ignored.
     *
     * These observers fire on every activity edit on the site, so the cheap bail-out matters:
     * almost every event they see is from a course this plugin does not care about.
     */
    public function test_events_elsewhere_are_ignored(): void {
        $this->resetAfterTest();
        $this->make_template_course();
        baker::rebuild();
        $this->clear_queued_tasks();

        $other = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->deliver_events(
            fn() => $this->getDataGenerator()->create_module('assign', ['course' => $other->id, 'section' => 1])
        );

        $this->assertEmpty(
            \core\task\manager::get_adhoc_tasks(\mod_edpreset\task\rebuild_presets::class),
            'an activity added to an unrelated course must not queue any work'
        );
    }

    /**
     * Nothing is queued while the plugin is switched off.
     */
    public function test_observers_do_nothing_when_disabled(): void {
        $this->resetAfterTest();
        $course = $this->make_template_course();
        baker::rebuild();
        $this->clear_queued_tasks();
        set_config('enabled', 0, 'mod_edpreset');

        $this->deliver_events(
            fn() => $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1])
        );

        $this->assertEmpty(\core\task\manager::get_adhoc_tasks(\mod_edpreset\task\rebuild_presets::class));
    }

    /**
     * Clearing the cache pulls every preset out of the chooser and queues a rebuild.
     */
    public function test_clear_cache_unpublishes_everything(): void {
        $this->resetAfterTest();
        $this->make_template_course();
        baker::rebuild();

        // Publish one for real.
        $preset = preset::get_record(['title' => 'Reflective journal']);
        baker::bake_one((int)$preset->get('templatecmid'));
        \mod_edpreset\local\validator::process(preset::get_record(['id' => $preset->get('id')]));
        $this->assertTrue(preset::get_record(['id' => $preset->get('id')])->is_live());

        $count = baker::clear_cache();

        $this->assertSame(3, $count);
        foreach (preset::get_records() as $reloaded) {
            $this->assertFalse($reloaded->is_live(), 'no preset should be offered after a cache clear');
            $this->assertSame(preset::STATUS_PENDING, $reloaded->get('status'));
        }
    }

    /**
     * A full pass - scan, bake, validate - publishes the exemplars.
     */
    public function test_full_pipeline_publishes_presets(): void {
        $this->resetAfterTest();
        $this->make_template_course();

        baker::rebuild();

        foreach (preset::get_records() as $preset) {
            baker::bake_one((int)$preset->get('templatecmid'));
            \mod_edpreset\local\validator::process(preset::get_record(['id' => $preset->get('id')]));
        }

        $live = array_filter(preset::get_records(), fn($p) => $p->is_live());
        $this->assertCount(3, $live);
    }

    /**
     * Nothing happens when no template course is configured.
     */
    public function test_rebuild_without_a_template_course(): void {
        $this->resetAfterTest();
        set_config('templatecourseid', 0, 'mod_edpreset');

        $this->assertSame(
            ['scanned' => 0, 'queued' => 0, 'removed' => 0],
            baker::rebuild()
        );
        $this->assertFalse(template::is_configured());
    }
}
