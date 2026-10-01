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

use mod_edpreset\local\activity_copier;

/**
 * Tests for copying a preset's exemplar into a course.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_edpreset\local\activity_copier
 * @covers     \mod_edpreset\preset
 */
final class activity_copier_test extends \advanced_testcase {
    /**
     * Create a template course with one exemplar, and a released preset for it.
     *
     * @param string $modname The module to use as the exemplar.
     * @param array $moddata Extra module settings.
     * @return array [$templatecourse, $preset, $exemplarcm]
     */
    protected function exemplar(string $modname = 'assign', array $moddata = []): array {
        $generator = $this->getDataGenerator();

        $templatecourse = $generator->create_course(['numsections' => 2]);
        $module = $generator->create_module($modname, $moddata + [
            'course' => $templatecourse->id,
            'section' => 1,
            'name' => 'Exemplar ' . $modname,
        ]);
        $exemplarcm = get_coursemodule_from_instance($modname, $module->id, $templatecourse->id);

        set_config('templatecourseid', $templatecourse->id, 'mod_edpreset');
        set_config('enabled', 1, 'mod_edpreset');

        $preset = $this->preset_for($exemplarcm, 'Exemplar ' . $modname);

        return [$templatecourse, $preset, $exemplarcm];
    }

    /**
     * Create several released presets in one template course, named "Exemplar 1", "Exemplar 2", ...
     *
     * Pages rather than assignments: these tests are about how a batch is placed, and a page is the
     * cheapest thing to back up and restore several times over.
     *
     * @param int $count How many presets to create.
     * @return preset[] The presets, in name order.
     */
    protected function exemplars(int $count): array {
        $generator = $this->getDataGenerator();

        $templatecourse = $generator->create_course(['numsections' => 2]);
        set_config('templatecourseid', $templatecourse->id, 'mod_edpreset');
        set_config('enabled', 1, 'mod_edpreset');

        $presets = [];
        for ($i = 1; $i <= $count; $i++) {
            $module = $generator->create_module('page', [
                'course' => $templatecourse->id,
                'section' => 1,
                'name' => 'Exemplar ' . $i,
            ]);
            $cm = get_coursemodule_from_instance('page', $module->id, $templatecourse->id);
            $presets[] = $this->preset_for($cm, 'Exemplar ' . $i);
        }

        return $presets;
    }

    /**
     * A released preset record for an exemplar.
     *
     * @param \stdClass $cm The exemplar's course module.
     * @param string $title The preset's title.
     * @return preset
     */
    protected function preset_for(\stdClass $cm, string $title): preset {
        return $this->getDataGenerator()->get_plugin_generator('mod_edpreset')->create_preset([
            'templatecourseid' => $cm->course,
            'templatecmid' => $cm->id,
            'modname' => $cm->modname,
            'instanceid' => $cm->instance,
            'contextid' => \context_module::instance($cm->id)->id,
            'title' => $title,
        ]);
    }

    /**
     * Nothing is left behind: no backup file on the exemplar and no backup temp directory.
     *
     * The backup never becomes a file at all - import mode writes a temp directory and the restore
     * reads it - so the only thing that could be left is that directory. The backup and restore
     * logs are deliberately left, exactly as duplicate_module() leaves them: they are what an
     * administrator reads when a copy fails, and core's backup cleanup task removes them.
     */
    public function test_a_copy_leaves_no_backup_behind(): void {
        $this->resetAfterTest();
        [, $preset, $exemplarcm] = $this->exemplar();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $this->setAdminUser();

        $before = $this->backup_temp_entries();
        activity_copier::copy($preset, $course, 1);

        $this->assertSame($before, $this->backup_temp_entries(), 'a backup temp directory was left behind');
        $this->assertEmpty(
            get_file_storage()->get_area_files(
                \context_module::instance($exemplarcm->id)->id,
                'backup',
                'activity',
                false,
                'itemid',
                false
            ),
            'a backup file was left attached to the exemplar'
        );
    }

    /**
     * What is in the backup temp directory, other than logs.
     *
     * @return string[]
     */
    protected function backup_temp_entries(): array {
        global $CFG;

        if (!is_dir($CFG->backuptempdir)) {
            return [];
        }

        $entries = array_values(array_filter(
            array_diff(scandir($CFG->backuptempdir), ['.', '..']),
            fn($entry) => !str_ends_with($entry, '.log')
        ));
        sort($entries);

        return $entries;
    }

    /**
     * The whole point: a teacher with no access at all to the template course can still copy.
     *
     * An import-mode backup needs moodle/backup:backuptargetimport in the SOURCE course, which a
     * teacher does not have there; the restore needs moodle/restore:restoretargetimport in the
     * TARGET course, which an editing teacher does have. Taking the backup as the site
     * administrator is what makes this asymmetry work.
     */
    public function test_copy_as_teacher_with_no_access_to_template_course(): void {
        $this->resetAfterTest();
        [$templatecourse, $preset] = $this->exemplar();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->assertFalse(
            is_enrolled(\context_course::instance($templatecourse->id), $teacher),
            'sanity: the teacher must not be enrolled in the template course'
        );
        $this->assertFalse(
            has_capability('moodle/backup:backuptargetimport', \context_course::instance($templatecourse->id), $teacher),
            'sanity: the teacher must not be able to back up the template course'
        );

        $cm = activity_copier::copy($preset, $course, 2);

        $this->assertSame('assign', $cm->modname);
        $this->assertSame((int)$course->id, (int)$cm->course);
        // No default activity name is set on this preset, so the exemplar's own name carries over.
        $this->assertSame('Exemplar assign', $cm->name);
    }

    /**
     * A copy is of the exemplar as it is now, not as it was when the preset was scanned.
     */
    public function test_copy_reflects_the_exemplar_as_it_is_now(): void {
        global $DB;
        $this->resetAfterTest();
        [, $preset, $exemplarcm] = $this->exemplar('page');

        $DB->set_field('page', 'content', '<p>Edited after the scan.</p>', ['id' => $exemplarcm->instance]);
        rebuild_course_cache($exemplarcm->course, true);

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->setAdminUser();

        $cm = activity_copier::copy($preset, $course, 1);

        $this->assertSame('<p>Edited after the scan.</p>', $DB->get_field('page', 'content', ['id' => $cm->instance]));
    }

    /**
     * A preset whose exemplar has gone fails with a reason an administrator can act on.
     */
    public function test_copy_of_a_deleted_exemplar_fails(): void {
        $this->resetAfterTest();
        [, $preset, $exemplarcm] = $this->exemplar();

        course_delete_module($exemplarcm->id);

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->setAdminUser();

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('exemplarmissing', 'mod_edpreset'));
        activity_copier::copy($preset, $course, 1);
    }

    /**
     * A restore that breaks part way leaves nothing in the teacher's course.
     *
     * Nothing proves an exemplar restores before a teacher asks for it, so this is the only thing
     * between a broken exemplar and a half-built activity in someone's course. The failure is
     * injected through the progress reporter - see failing_restore_progress.
     */
    public function test_a_failed_restore_leaves_nothing_behind(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/edpreset/tests/fixtures/failing_restore_progress.php');
        $this->resetAfterTest();
        [, $preset] = $this->exemplar('page');

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->setAdminUser();

        $progress = new \failing_restore_progress((int)$course->id);

        try {
            activity_copier::copy($preset, $course, 1, 0, $progress);
            $this->fail('the injected failure did not stop the restore');
        } catch (\moodle_exception $e) {
            $this->assertSame('error', $e->errorcode);
        }

        $this->assertSame(0, $DB->count_records('course_modules', ['course' => $course->id]));
        $this->assertEmpty(get_fast_modinfo($course)->get_cms());
    }

    /**
     * A preset with a default activity name renames the copy.
     *
     * The rename has to happen before copy() reads modinfo: set_coursemodule_name() purges and
     * rebuilds the course cache, so renaming afterwards would hand the caller a cm_info still
     * carrying the exemplar's name - which is exactly what the batch-add progress display shows.
     */
    public function test_default_activity_name_is_applied(): void {
        $this->resetAfterTest();
        [, $preset] = $this->exemplar();

        $preset->set('defaultname', 'Weekly reflection');
        $preset->update();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $this->setAdminUser();

        $cm = activity_copier::copy($preset, $course, 1);

        $this->assertSame('Weekly reflection', $cm->name);
        $this->assertSame('Weekly reflection', get_fast_modinfo($course)->get_cm($cm->id)->name);
    }

    /**
     * A default activity name of whitespace is treated as none at all.
     */
    public function test_blank_default_activity_name_is_ignored(): void {
        $this->resetAfterTest();
        [, $preset] = $this->exemplar();

        $preset->set('defaultname', '   ');
        $preset->update();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $this->setAdminUser();

        $this->assertSame('Exemplar assign', activity_copier::copy($preset, $course, 1)->name);
    }

    /**
     * The activity lands in the requested section, not the exemplar's.
     *
     * The restore places activities by matching the exemplar's section number, so without explicit
     * placement an exemplar from section 1 would always land in section 1.
     */
    public function test_copy_lands_in_the_requested_section(): void {
        $this->resetAfterTest();
        [, $preset] = $this->exemplar();

        $course = $this->getDataGenerator()->create_course(['numsections' => 5]);
        $this->setAdminUser();

        $cm = activity_copier::copy($preset, $course, 4);

        $this->assertSame(4, (int)get_fast_modinfo($course)->get_cm($cm->id)->sectionnum);
    }

    /**
     * A section that does not exist yet is created rather than silently ignored.
     */
    public function test_copy_creates_a_missing_section(): void {
        $this->resetAfterTest();
        [, $preset] = $this->exemplar();

        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $this->setAdminUser();

        $cm = activity_copier::copy($preset, $course, 3);

        $this->assertSame(3, (int)get_fast_modinfo($course)->get_cm($cm->id)->sectionnum);
    }

    /**
     * beforemod puts the new activity ahead of an existing one.
     */
    public function test_copy_respects_beforemod(): void {
        $this->resetAfterTest();
        [, $preset] = $this->exemplar();

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $existing = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $this->setAdminUser();

        $cm = activity_copier::copy($preset, $course, 1, $existing->cmid);

        $sequence = get_fast_modinfo($course)->sections[1];
        $this->assertSame(
            [(int)$cm->id, (int)$existing->cmid],
            array_map('intval', array_values($sequence))
        );
    }

    /**
     * Several presets land in the order they were selected.
     *
     * The copies all share one $beforemod, and course_add_cm_to_section() splices each new module
     * in immediately before it, so a later copy lands after an earlier one rather than in front of
     * it. Getting this backwards would silently reverse every batch.
     */
    public function test_copy_many_lands_in_selection_order(): void {
        $this->resetAfterTest();
        $presets = $this->exemplars(3);

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $this->setAdminUser();

        $result = activity_copier::copy_many($presets, $course, 2);

        $this->assertCount(3, $result['added']);
        $this->assertSame([], $result['failed']);
        $this->assertSame(
            ['Exemplar 1', 'Exemplar 2', 'Exemplar 3'],
            array_map(fn($cm) => $cm->name, $result['added'])
        );
        $this->assertSame(
            array_map(fn($cm) => (int)$cm->id, $result['added']),
            array_map('intval', array_values(get_fast_modinfo($course)->sections[2]))
        );
    }

    /**
     * A batch inserted before an existing activity stays in order, and stays ahead of it.
     */
    public function test_copy_many_respects_beforemod(): void {
        $this->resetAfterTest();
        $presets = $this->exemplars(3);

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $existing = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $this->setAdminUser();

        $result = activity_copier::copy_many($presets, $course, 1, $existing->cmid);

        $expected = array_map(fn($cm) => (int)$cm->id, $result['added']);
        $expected[] = (int)$existing->cmid;

        $this->assertSame($expected, array_map('intval', array_values(get_fast_modinfo($course)->sections[1])));
    }

    /**
     * One preset failing must not cost the teacher the rest of the batch.
     *
     * Nothing wraps a restore in a transaction, so whatever has already been copied is really in
     * the course by the time a later one throws. Abandoning the remainder would only add a second
     * kind of partial result; the caller is told what landed and what did not instead.
     */
    public function test_copy_many_continues_past_a_failure(): void {
        $this->resetAfterTest();
        $presets = $this->exemplars(3);

        // Point the middle one at an exemplar that does not exist.
        $presets[1]->set('templatecmid', (int)$presets[1]->get('templatecmid') + 100000);
        $presets[1]->update();

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->setAdminUser();

        $result = activity_copier::copy_many($presets, $course, 1);

        $this->assertDebuggingCalledCount(1);
        $this->assertSame(['Exemplar 2'], $result['failed']);
        $this->assertSame(
            ['Exemplar 1', 'Exemplar 3'],
            array_map(fn($cm) => $cm->name, $result['added'])
        );
        $this->assertCount(2, get_fast_modinfo($course)->sections[1]);
    }

    /**
     * A failed copy is recorded against the preset for the manage page, as nobody but the copier.
     *
     * The record is written in a teacher's request, and must not stamp that teacher into
     * usermodified: the privacy provider declares that column as the curator who last saved it.
     */
    public function test_a_failure_is_recorded_without_claiming_authorship(): void {
        $this->resetAfterTest();
        $presets = $this->exemplars(1);
        $preset = $presets[0];

        $preset->set('templatecmid', (int)$preset->get('templatecmid') + 100000);
        $preset->update();
        $curator = (int)preset::get_record(['id' => $preset->get('id')])->get('usermodified');

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        activity_copier::copy_many([$preset], $course, 1);
        $this->assertDebuggingCalledCount(1);

        $reloaded = preset::get_record(['id' => $preset->get('id')]);
        $this->assertStringContainsString(get_string('exemplarmissing', 'mod_edpreset'), $reloaded->get('lasterror'));
        $this->assertGreaterThan(0, (int)$reloaded->get('timelasterror'));
        $this->assertSame($curator, (int)$reloaded->get('usermodified'));
        $this->assertNotSame((int)$teacher->id, (int)$reloaded->get('usermodified'));
    }

    /**
     * A copy that works clears the last failure, so the manage page shows only current problems.
     */
    public function test_a_success_clears_the_last_failure(): void {
        $this->resetAfterTest();
        [, $preset] = $this->exemplar('page');

        $preset->record_copy_error('moodle_exception: something went wrong last week');

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->setAdminUser();

        $result = activity_copier::copy_many([$preset], $course, 1);
        $this->assertCount(1, $result['added']);

        $reloaded = preset::get_record(['id' => $preset->get('id')]);
        $this->assertSame('', (string)$reloaded->get('lasterror'));
        $this->assertSame(0, (int)$reloaded->get('timelasterror'));
    }

    /**
     * course_module_created must be fired by hand; the restore subsystem does not fire it.
     *
     * Without it, completion, competencies and third-party observers never learn the activity
     * exists.
     */
    public function test_copy_fires_course_module_created(): void {
        $this->resetAfterTest();
        [, $preset] = $this->exemplar();

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->setAdminUser();

        $sink = $this->redirectEvents();
        $cm = activity_copier::copy($preset, $course, 1);
        $events = $sink->get_events();
        $sink->close();

        $created = array_filter(
            $events,
            fn($e) => $e instanceof \core\event\course_module_created && $e->objectid == $cm->id
        );
        $this->assertCount(1, $created);
    }

    /**
     * Template-course specifics must not follow the activity across.
     */
    public function test_copy_clears_idnumber_and_availability(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        $CFG->enableavailability = 1;

        [, $preset, $exemplarcm] = $this->exemplar('assign', ['idnumber' => 'TEMPLATE-001']);

        $DB->set_field('course_modules', 'availability', '{"op":"&","c":[],"showc":[]}', ['id' => $exemplarcm->id]);
        rebuild_course_cache($exemplarcm->course, true);

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->setAdminUser();
        $cm = activity_copier::copy($preset, $course, 1);

        $record = $DB->get_record('course_modules', ['id' => $cm->id]);
        $this->assertSame('', (string)$record->idnumber);
        $this->assertNull($record->availability);
        $this->assertSame(0, (int)$record->completionexpected);
    }

    /**
     * A quiz keeps its questions - the reason this uses backup/restore rather than form defaults.
     */
    public function test_copy_of_a_quiz_preserves_its_questions(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $templatecourse = $generator->create_course(['numsections' => 2]);
        $quiz = $generator->create_module('quiz', [
            'course' => $templatecourse->id,
            'section' => 1,
            'name' => 'Exemplar quiz',
        ]);

        // Put a real question in the quiz.
        $questiongenerator = $generator->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category(
            ['contextid' => \context_module::instance($quiz->cmid)->id]
        );
        $question = $questiongenerator->create_question('truefalse', null, ['category' => $cat->id]);
        quiz_add_quiz_question($question->id, $quiz);

        $exemplarcm = get_coursemodule_from_instance('quiz', $quiz->id, $templatecourse->id);
        set_config('templatecourseid', $templatecourse->id, 'mod_edpreset');
        set_config('enabled', 1, 'mod_edpreset');
        $preset = $this->preset_for($exemplarcm, 'Exemplar quiz');

        $course = $generator->create_course(['numsections' => 2]);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $cm = activity_copier::copy($preset, $course, 1);

        $slots = $DB->count_records('quiz_slots', ['quizid' => $cm->instance]);
        $this->assertSame(1, $slots, 'the copied quiz lost its question');
    }

    /**
     * The modules in a course, in the order they appear in their section.
     *
     * @param \stdClass $course The course.
     * @param int $sectionnum The section to look in.
     * @return \cm_info[]
     */
    protected function section_modules($course, int $sectionnum): array {
        $modinfo = get_fast_modinfo($course);

        $cms = [];
        foreach ($modinfo->sections[$sectionnum] ?? [] as $cmid) {
            $cms[] = $modinfo->get_cm($cmid);
        }

        return $cms;
    }

    /**
     * A copy adds exactly one activity: guidance no longer arrives as a separate module.
     */
    public function test_a_copy_adds_one_activity(): void {
        $this->resetAfterTest();
        [, $preset] = $this->exemplar();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $this->setAdminUser();

        $cm = activity_copier::copy($preset, $course, 1);

        $modules = $this->section_modules($course, 1);
        $this->assertCount(1, $modules);
        $this->assertSame((int)$cm->id, (int)$modules[0]->id);
    }

    /**
     * Guidance embedded in the exemplar arrives with the copy, still using the same site preset.
     *
     * Nothing in the copier does this: the token is in the exemplar's description, and
     * local_edguidance's blocks ride along in the activity's backup. This pins that the copy path
     * really does carry them, which is what lets this plugin know nothing about guidance.
     */
    public function test_embedded_guidance_travels_with_a_copy(): void {
        global $DB;

        if (!\core_component::get_component_directory('local_edguidance')) {
            $this->markTestSkipped('local_edguidance is not installed.');
        }

        $this->resetAfterTest();
        $key = \local_edguidance\token::new_key();

        [, $preset, $exemplarcm] = $this->exemplar(
            'assign',
            ['intro' => '<p>Exemplar.</p>' . \local_edguidance\token::html($key)]
        );
        $this->getDataGenerator()->get_plugin_generator('local_edguidance')->create_block([
            'cmid' => $exemplarcm->id,
            'embedkey' => $key,
            'presetslot' => 3,
            'introorder' => 1,
        ]);

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $this->setAdminUser();

        $cm = activity_copier::copy($preset, $course, 1);

        $this->assertStringContainsString($key, $DB->get_field('assign', 'intro', ['id' => $cm->instance]));
        $block = $DB->get_record('local_edguidance', ['cmid' => $cm->id, 'embedkey' => $key], '*', MUST_EXIST);
        $this->assertSame((int)$course->id, (int)$block->courseid);
        $this->assertSame(3, (int)$block->presetslot);
        $this->assertSame(1, (int)$block->introorder);
    }
}
