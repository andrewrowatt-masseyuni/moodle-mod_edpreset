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
use mod_edpreset\local\backup_baker;

/**
 * Tests for the cross-course single-activity copy.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_edpreset\local\activity_copier
 * @covers     \mod_edpreset\local\backup_baker
 */
final class activity_copier_test extends \advanced_testcase {
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
     * Skip the calling test unless mod_ednote is installed.
     *
     * mod_edpreset deliberately declares no dependency on mod_ednote: a preset's guidance becomes a
     * teacher note if that plugin happens to be there, and is simply not shown if it is not. So the
     * plugin is routinely run - including by its own CI, which checks out this repository alone -
     * on a site where mod_ednote does not exist, and the tests that assert a note was created have
     * nothing to assert about.
     *
     * Only those tests skip. The ones asserting that no note appears are still worth running: that
     * is the behaviour a site without mod_ednote actually gets.
     */
    protected function skip_without_ednote(): void {
        if (!\core_component::get_component_directory('mod_ednote')) {
            $this->markTestSkipped('mod_ednote is not installed, so a preset\'s guidance produces no note.');
        }
    }

    /**
     * Create a template course with one exemplar, and bake it into a live preset.
     *
     * @param string $modname The module to use as the exemplar.
     * @param array $moddata Extra module settings.
     * @return array [$templatecourse, $preset, $exemplarcm]
     */
    protected function bake_exemplar(string $modname = 'assign', array $moddata = []): array {
        $generator = $this->getDataGenerator();
        $plugingenerator = $generator->get_plugin_generator('mod_edpreset');

        $templatecourse = $generator->create_course(['numsections' => 2]);
        $module = $generator->create_module($modname, $moddata + [
            'course' => $templatecourse->id,
            'section' => 1,
            'name' => 'Exemplar ' . $modname,
        ]);
        $exemplarcm = get_coursemodule_from_instance($modname, $module->id, $templatecourse->id);

        set_config('templatecourseid', $templatecourse->id, 'mod_edpreset');
        set_config('enabled', 1, 'mod_edpreset');

        $preset = $plugingenerator->create_preset([
            'templatecourseid' => $templatecourse->id,
            'templatecmid' => $exemplarcm->id,
            'modname' => $modname,
            'instanceid' => $module->id,
            'contextid' => \context_module::instance($exemplarcm->id)->id,
            'title' => 'Exemplar ' . $modname,
            'live' => false,
        ]);

        // Bake for real, then promote the staged archive as the validator will later do.
        $this->setAdminUser();
        $staged = backup_baker::bake($preset);
        $this->promote($preset, $staged);

        return [$templatecourse, $preset, $exemplarcm];
    }

    /**
     * Bake several live presets out of one template course, named "Exemplar 1", "Exemplar 2", ...
     *
     * Pages rather than assignments: these tests are about how a batch is placed, and a page is the
     * cheapest thing to back up and restore several times over.
     *
     * @param int $count How many presets to bake.
     * @return preset[] The presets, in name order.
     */
    protected function bake_exemplars(int $count): array {
        $generator = $this->getDataGenerator();
        $plugingenerator = $generator->get_plugin_generator('mod_edpreset');

        $templatecourse = $generator->create_course(['numsections' => 2]);
        set_config('templatecourseid', $templatecourse->id, 'mod_edpreset');
        set_config('enabled', 1, 'mod_edpreset');
        $this->setAdminUser();

        $presets = [];
        for ($i = 1; $i <= $count; $i++) {
            $module = $generator->create_module('page', [
                'course' => $templatecourse->id,
                'section' => 1,
                'name' => 'Exemplar ' . $i,
            ]);
            $cm = get_coursemodule_from_instance('page', $module->id, $templatecourse->id);

            $preset = $plugingenerator->create_preset([
                'templatecourseid' => $templatecourse->id,
                'templatecmid' => $cm->id,
                'modname' => 'page',
                'instanceid' => $module->id,
                'contextid' => \context_module::instance($cm->id)->id,
                'title' => 'Exemplar ' . $i,
                'live' => false,
            ]);

            $this->promote($preset, backup_baker::bake($preset));
            $presets[] = $preset;
        }

        return $presets;
    }

    /**
     * Move a staged archive into the live area, as the validator does once it has proven it.
     *
     * @param preset $preset The preset.
     * @param \stored_file $staged The staged archive.
     */
    protected function promote(preset $preset, \stored_file $staged): void {
        // A re-bake replaces the live archive, so clear it first.
        backup_baker::clear_area($preset, preset::FILEAREA_BACKUP);

        $live = get_file_storage()->create_file_from_storedfile([
            'contextid' => \context_system::instance()->id,
            'component' => 'mod_edpreset',
            'filearea' => preset::FILEAREA_BACKUP,
            'itemid' => $preset->get('id'),
            'filepath' => '/',
            'filename' => 'preset_' . $preset->get('id') . '.mbz',
        ], $staged);

        $preset->set('backupcontenthash', $live->get_contenthash());
        $preset->set('backupfilesize', $live->get_filesize());
        $preset->set('status', preset::STATUS_READY);
        $preset->update();

        backup_baker::clear_area($preset, preset::FILEAREA_STAGING);
    }

    /**
     * A backup can be produced and staged, and it is not left behind in the template course.
     */
    public function test_bake_stages_an_archive(): void {
        $this->resetAfterTest();
        [$templatecourse, $preset, $exemplarcm] = $this->bake_exemplar();

        $live = $preset->get_live_file();
        $this->assertNotFalse($live);
        $this->assertGreaterThan(0, $live->get_filesize());
        $this->assertTrue($preset->is_live());

        // The backup lands in the exemplar's module context by default; it must not be left there.
        $strays = get_file_storage()->get_area_files(
            \context_module::instance($exemplarcm->id)->id,
            'backup',
            'activity',
            0,
            'itemid',
            false
        );
        $this->assertEmpty($strays, 'a backup archive was left attached to the exemplar');
        unset($templatecourse);
    }

    /**
     * The whole point: a teacher with no access at all to the template course can still copy.
     *
     * Backup in import mode needs moodle/backup:backuptargetimport in the SOURCE course, which a
     * teacher does not have there; restore needs moodle/restore:restoretargetimport in the TARGET
     * course, which an editing teacher does have. Baking ahead of time as an admin is what makes
     * this asymmetry work.
     */
    public function test_copy_as_teacher_with_no_access_to_template_course(): void {
        $this->resetAfterTest();
        [$templatecourse, $preset] = $this->bake_exemplar();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->assertFalse(
            is_enrolled(\context_course::instance($templatecourse->id), $teacher),
            'sanity: the teacher must not be enrolled in the template course'
        );

        $cm = activity_copier::copy($preset, $course, 2);

        $this->assertSame('assign', $cm->modname);
        $this->assertSame((int)$course->id, (int)$cm->course);
        // No default activity name is set on this preset, so the exemplar's own name carries over.
        $this->assertSame('Exemplar assign', $cm->name);
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
        [, $preset] = $this->bake_exemplar();

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
        [, $preset] = $this->bake_exemplar();

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
        [, $preset] = $this->bake_exemplar();

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
        [, $preset] = $this->bake_exemplar();

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
        [, $preset] = $this->bake_exemplar();

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
        $presets = $this->bake_exemplars(3);

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
        $presets = $this->bake_exemplars(3);

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
        $presets = $this->bake_exemplars(3);

        // Break the middle one, exactly as a re-bake in flight would: the file no longer matches
        // the hash the record claims, so is_live() is false and copy() refuses it.
        $presets[1]->set('backupcontenthash', sha1('a different archive entirely'));
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
     * course_module_created must be fired by hand; the restore subsystem does not fire it.
     *
     * Without it, completion, competencies and third-party observers never learn the activity
     * exists.
     */
    public function test_copy_fires_course_module_created(): void {
        $this->resetAfterTest();
        [, $preset] = $this->bake_exemplar();

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

        [, $preset, $exemplarcm] = $this->bake_exemplar('assign', ['idnumber' => 'TEMPLATE-001']);

        // Give the exemplar an availability rule, then re-bake so the archive carries it.
        $DB->set_field('course_modules', 'availability', '{"op":"&","c":[],"showc":[]}', ['id' => $exemplarcm->id]);
        rebuild_course_cache($exemplarcm->course, true);
        $this->setAdminUser();
        $this->promote($preset, backup_baker::bake($preset));

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
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

        $preset = $generator->get_plugin_generator('mod_edpreset')->create_preset([
            'templatecourseid' => $templatecourse->id,
            'templatecmid' => $exemplarcm->id,
            'modname' => 'quiz',
            'instanceid' => $quiz->id,
            'contextid' => \context_module::instance($exemplarcm->id)->id,
            'title' => 'Exemplar quiz',
            'live' => false,
        ]);

        $this->setAdminUser();
        $this->promote($preset, backup_baker::bake($preset));

        $course = $generator->create_course(['numsections' => 2]);
        $cm = activity_copier::copy($preset, $course, 1);

        $slots = $DB->count_records('quiz_slots', ['quizid' => $cm->instance]);
        $this->assertSame(1, $slots, 'the copied quiz lost its question');
    }

    /**
     * A preset whose archive no longer matches its recorded hash is refused.
     */
    public function test_copy_refuses_a_stale_archive(): void {
        $this->resetAfterTest();
        [, $preset] = $this->bake_exemplar();

        $preset->set('backupcontenthash', sha1('a different archive entirely'));
        $preset->update();

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->setAdminUser();

        $this->expectException(\moodle_exception::class);
        activity_copier::copy($preset, $course, 1);
    }

    /**
     * The notes in a course, in the order they appear in their section.
     *
     * @param stdClass $course The course.
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
     * A preset with guidance drops a teacher note in above the activity.
     */
    public function test_guidance_emits_a_note_above_the_activity(): void {
        $this->skip_without_ednote();
        $this->resetAfterTest();
        [, $preset] = $this->bake_exemplar();

        $preset->set('teacherguidance', '<p>Set the due date first.</p>');
        $preset->update();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $this->setAdminUser();

        $cm = activity_copier::copy($preset, $course, 1);

        $modules = $this->section_modules($course, 1);
        $this->assertCount(2, $modules);
        $this->assertSame('ednote', $modules[0]->modname, 'the note belongs above the activity');
        $this->assertSame((int)$cm->id, (int)$modules[1]->id);

        // Linked to the preset rather than carrying a copy, so later edits reach it...
        $note = \mod_ednote\guidance::for_cm((int)$course->id, (int)$modules[0]->id);
        $this->assertSame((int)$preset->get('id'), $note->presetid);
        $this->assertStringContainsString('Set the due date first.', $note->content);

        // ...but a snapshot is stored too, for the day mod_edpreset or the preset is not there.
        $this->assertStringContainsString(
            'Set the due date first.',
            $this->note_record($modules[0])->intro
        );
    }

    /**
     * The note is a normal visible activity, hidden from students by capability instead.
     */
    public function test_the_emitted_note_is_not_a_hidden_activity(): void {
        $this->skip_without_ednote();
        $this->resetAfterTest();
        [, $preset] = $this->bake_exemplar();

        $preset->set('teacherguidance', '<p>Guidance.</p>');
        $preset->update();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $this->setAdminUser();

        activity_copier::copy($preset, $course, 1);

        $note = $this->section_modules($course, 1)[0];
        $this->assertSame('ednote', $note->modname);
        $this->assertEquals(1, $note->visible);

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->assertFalse(get_fast_modinfo($course, $student->id)->get_cm($note->id)->uservisible);
    }

    /**
     * A preset with no guidance copies exactly as before.
     */
    public function test_no_guidance_emits_no_note(): void {
        $this->resetAfterTest();
        [, $preset] = $this->bake_exemplar();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $this->setAdminUser();

        activity_copier::copy($preset, $course, 1);

        $modules = $this->section_modules($course, 1);
        $this->assertCount(1, $modules);
        $this->assertSame('assign', $modules[0]->modname);
    }

    /**
     * Guidance that is only whitespace is treated as none at all.
     */
    public function test_blank_guidance_emits_no_note(): void {
        $this->resetAfterTest();
        [, $preset] = $this->bake_exemplar();

        $preset->set('teacherguidance', "   \n  ");
        $preset->update();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $this->setAdminUser();

        activity_copier::copy($preset, $course, 1);

        $this->assertCount(1, $this->section_modules($course, 1));
    }

    /**
     * Without mod_ednote the copy still works; it just arrives without its note.
     *
     * This is what lets mod_edpreset declare no dependency on mod_ednote. Deliberately NOT skipped
     * when mod_ednote is absent - that is the case it is about, and on a site without the plugin it
     * stops being a simulation and becomes the real thing.
     *
     * Hiding the module rather than deleting its row keeps the test honest on a site that does have
     * mod_ednote: emit_note() has to decline for a module that is installed but switched off as well
     * as for one that was never installed, and both go through the same guard.
     */
    public function test_copy_still_works_when_ednote_is_unavailable(): void {
        global $DB;

        $this->resetAfterTest();
        [, $preset] = $this->bake_exemplar();

        $preset->set('teacherguidance', '<p>Guidance.</p>');
        $preset->update();

        $DB->set_field('modules', 'visible', 0, ['name' => 'ednote']);

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $this->setAdminUser();

        $cm = activity_copier::copy($preset, $course, 1);

        $this->assertSame('assign', $cm->modname);
        $this->assertCount(1, $this->section_modules($course, 1));
    }

    /**
     * Validating a preset must not leave notes behind in the sandbox course.
     *
     * The validator shares restore_into() rather than copy(), which is the only thing keeping the
     * two apart - so this asserts the note is emitted by the outer call and not the inner one.
     */
    public function test_restore_into_alone_emits_no_note(): void {
        $this->resetAfterTest();
        [, $preset] = $this->bake_exemplar();

        $preset->set('teacherguidance', '<p>Guidance.</p>');
        $preset->update();

        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $this->setAdminUser();

        activity_copier::restore_into($preset->get_live_file(), $course, 1);

        $this->assertCount(1, $this->section_modules($course, 1));
    }

    /**
     * The stored note record behind a course module.
     *
     * @param \cm_info $cm The note's course module.
     * @return \stdClass
     */
    protected function note_record(\cm_info $cm): \stdClass {
        global $DB;

        return $DB->get_record('ednote', ['id' => $cm->instance], '*', MUST_EXIST);
    }
}
