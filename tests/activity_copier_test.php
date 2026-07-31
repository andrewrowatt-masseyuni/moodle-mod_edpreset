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
        $this->assertSame('Exemplar assign', $cm->name);
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
}
