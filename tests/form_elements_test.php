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

use MoodleQuickForm;
use ReflectionClass;
use stdClass;

/**
 * Tests for the "Preset details" group this plugin splices into other modules' settings forms.
 *
 * This is the highest-risk part of the plugin: the callback runs against every activity settings
 * form on the site, including third-party ones, and it manipulates the form by splicing elements in
 * front of an element another plugin owns. These tests build real mod forms and inspect them.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::mod_edpreset_coursemodule_standard_elements
 * @covers     ::mod_edpreset_coursemodule_validation
 * @covers     ::mod_edpreset_coursemodule_edit_post_actions
 */
final class form_elements_test extends \advanced_testcase {
    /** @var string[] The elements the callback adds, in the order they must appear. */
    private const EXPECTED = [
        'edpreset_detailsheading',
        'edpreset_presetname',
        'edpreset_description',
        'edpreset_teacherguidance',
        'edpreset_tags',
        'edpreset_defaultname',
    ];

    /**
     * Load the library and the form scaffolding under test.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/edpreset/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/course/moodleform_mod.php');
        parent::setUpBeforeClass();
    }

    /**
     * Build a real settings form for a new activity and hand back its MoodleQuickForm.
     *
     * @param stdClass $course The course the activity is being added to.
     * @param string $modname The module name.
     * @param int $sectionnum The section the activity is being added to.
     * @return MoodleQuickForm
     */
    private function build_form(stdClass $course, string $modname, int $sectionnum): MoodleQuickForm {
        global $CFG;
        require_once($CFG->dirroot . "/mod/$modname/mod_form.php");

        $this->set_current_course($course);

        [, , , $cm, $data] = prepare_new_moduleinfo_data($course, $modname, $sectionnum);

        $classname = 'mod_' . $modname . '_mod_form';
        $form = new $classname($data, $sectionnum, $cm, $course);

        $property = (new ReflectionClass($classname))->getProperty('_form');

        return $property->getValue($form);
    }

    /**
     * Point the page - and with it the global $COURSE - at the course the form is being built for.
     *
     * moodleform_mod::standard_coursemodule_elements() looks the section up against the global
     * $COURSE rather than against the course it was handed, and in a test that global is still the
     * site course, whose sections do not line up. Production always arrives at these forms through
     * require_login(), which has set both; this is the test's stand-in for that.
     *
     * @param stdClass $course The course the form is being built for.
     */
    private function set_current_course(stdClass $course): void {
        global $PAGE;

        $PAGE->set_course($course);
    }

    /**
     * The names of a form's elements, in render order.
     *
     * @param MoodleQuickForm $mform The form.
     * @return string[]
     */
    private function element_names(MoodleQuickForm $mform): array {
        $names = [];
        foreach ($mform->_elements as $element) {
            $name = $element->getName();
            if ($name !== null && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Set up a template course with an admin user at the controls.
     *
     * @return stdClass The template course.
     */
    private function setup_template_course(): stdClass {
        $this->resetAfterTest();
        $this->setAdminUser();

        return $this->getDataGenerator()
            ->get_plugin_generator('mod_edpreset')
            ->create_template_course();
    }

    /**
     * Modules whose settings forms must gain the group, and those that must not.
     *
     * @return array[]
     */
    public static function module_provider(): array {
        return [
            // A resource, an activity, and a module with no intro of its own.
            'page' => ['page', true],
            'assign' => ['assign', true],
            'label' => ['label', true],
            // A delegated module carries a whole section with it and can never become a preset.
            'subsection' => ['subsection', false],
        ];
    }

    /**
     * The group is added to modules that can become presets, and only those.
     *
     * @dataProvider module_provider
     * @param string $modname The module name.
     * @param bool $expected Whether the group should be added.
     */
    public function test_group_is_added_per_module(string $modname, bool $expected): void {
        $course = $this->setup_template_course();

        $mform = $this->build_form($course, $modname, 1);
        $names = $this->element_names($mform);

        foreach (self::EXPECTED as $element) {
            $this->assertSame(
                $expected,
                in_array($element, $names, true),
                "mod_$modname: unexpected presence/absence of $element"
            );
        }
    }

    /**
     * The group sits above the activity name, in the documented order.
     *
     * insertElementBefore() places each element immediately before its anchor, so inserting in
     * reverse order would silently produce the reverse of the intended layout. Nothing else in the
     * plugin would notice.
     */
    public function test_group_is_ordered_above_the_activity_name(): void {
        $course = $this->setup_template_course();

        $names = $this->element_names($this->build_form($course, 'page', 1));

        $positions = [];
        foreach (self::EXPECTED as $element) {
            $this->assertContains($element, $names);
            $positions[] = array_search($element, $names, true);
        }

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'The preset detail fields are out of order.');

        $this->assertLessThan(
            array_search('name', $names, true),
            max($positions),
            'The preset detail fields must all appear above the activity name.'
        );
    }

    /**
     * Nothing is added outside a template course.
     */
    public function test_group_is_absent_from_ordinary_courses(): void {
        $this->setup_template_course();
        $ordinary = $this->getDataGenerator()->create_course(['numsections' => 3]);

        $names = $this->element_names($this->build_form($ordinary, 'page', 1));

        $this->assertNotContains('edpreset_presetname', $names);
    }

    /**
     * Nothing is added in section 0, which holds instructions rather than exemplars.
     */
    public function test_group_is_absent_from_section_zero(): void {
        $course = $this->setup_template_course();

        $names = $this->element_names($this->build_form($course, 'page', 0));

        $this->assertNotContains('edpreset_presetname', $names);
    }

    /**
     * Nothing is added while the plugin is switched off.
     */
    public function test_group_is_absent_when_disabled(): void {
        $course = $this->setup_template_course();
        set_config('enabled', 0, 'mod_edpreset');

        $names = $this->element_names($this->build_form($course, 'page', 1));

        $this->assertNotContains('edpreset_presetname', $names);
    }

    /**
     * The added elements must not collide with the ones core has already added.
     *
     * standard_coursemodule_elements() adds an element named 'tags' before this plugin's callback
     * runs. HTML_QuickForm drops an incompatible duplicate silently, so an unprefixed name here
     * would simply vanish with no error anywhere.
     */
    public function test_added_elements_do_not_collide_with_core(): void {
        $course = $this->setup_template_course();

        $names = $this->element_names($this->build_form($course, 'page', 1));

        $this->assertSame(count($names), count(array_unique($names)));
        // Core's own tags element must survive alongside ours.
        $this->assertContains('tags', $names);
        $this->assertContains('edpreset_tags', $names);
    }

    /**
     * Existing details are loaded back into the form.
     */
    public function test_existing_details_are_loaded_as_defaults(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/page/mod_form.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $plugingenerator = $generator->get_plugin_generator('mod_edpreset');

        $course = $plugingenerator->create_template_course([
            1 => [['modname' => 'page', 'name' => 'Exemplar page', 'meta' => [
                'presetname' => 'Weekly reading',
                'description' => 'Use this for a **weekly** reading.',
                'teacherguidance' => 'Set the **due date** before releasing.',
                'tags' => 'Content, Engage with content',
                'defaultname' => 'This week\'s reading',
            ]]],
        ]);

        $cm = get_fast_modinfo($course)->get_cms();
        $cm = reset($cm);

        $this->set_current_course($course);

        [$cmrec, , , $data] = get_moduleinfo_data($cm, $course);
        $form = new \mod_page_mod_form($data, $cm->sectionnum, $cmrec, $course);
        $mform = (new ReflectionClass(\mod_page_mod_form::class))->getProperty('_form')->getValue($form);

        $this->assertSame('Weekly reading', $mform->getElement('edpreset_presetname')->getValue());
        $this->assertSame('Use this for a **weekly** reading.', $mform->getElement('edpreset_description')->getValue());
        $this->assertSame(
            'Set the **due date** before releasing.',
            $mform->getElement('edpreset_teacherguidance')->getValue()
        );
        $this->assertSame('Content, Engage with content', $mform->getElement('edpreset_tags')->getValue());
        $this->assertSame("This week's reading", $mform->getElement('edpreset_defaultname')->getValue());
    }

    /**
     * Validation requires a preset name and a description, and enforces the lengths.
     */
    public function test_validation(): void {
        $course = $this->setup_template_course();

        $this->set_current_course($course);

        [, , , $cm, $data] = prepare_new_moduleinfo_data($course, 'page', 1);
        $form = new \mod_page_mod_form($data, 1, $cm, $course);

        $errors = mod_edpreset_coursemodule_validation($form, [
            'edpreset_presetname' => '  ',
            'edpreset_description' => '',
            'edpreset_defaultname' => '',
        ]);
        $this->assertArrayHasKey('edpreset_presetname', $errors);
        $this->assertArrayHasKey('edpreset_description', $errors);

        $errors = mod_edpreset_coursemodule_validation($form, [
            'edpreset_presetname' => str_repeat('a', meta::PRESETNAME_MAXLENGTH + 1),
            'edpreset_description' => 'Fine.',
            'edpreset_defaultname' => str_repeat('b', meta::DEFAULTNAME_MAXLENGTH + 1),
        ]);
        $this->assertArrayHasKey('edpreset_presetname', $errors);
        $this->assertArrayHasKey('edpreset_defaultname', $errors);
        $this->assertArrayNotHasKey('edpreset_description', $errors);

        // Guidance is optional, so an otherwise complete form with none of it must still pass.
        $errors = mod_edpreset_coursemodule_validation($form, [
            'edpreset_presetname' => 'Weekly reading',
            'edpreset_description' => 'Use this for a weekly reading.',
            'edpreset_teacherguidance' => '',
            'edpreset_defaultname' => '',
        ]);
        $this->assertSame([], $errors);

        $errors = mod_edpreset_coursemodule_validation($form, [
            'edpreset_presetname' => 'Weekly reading',
            'edpreset_description' => 'Use this for a weekly reading.',
            'edpreset_defaultname' => '',
        ]);
        $this->assertSame([], $errors);
    }

    /**
     * Nothing is validated outside a template course, even if the fields somehow arrive.
     */
    public function test_validation_is_skipped_outside_template_courses(): void {
        $this->setup_template_course();
        $ordinary = $this->getDataGenerator()->create_course(['numsections' => 3]);

        $this->set_current_course($ordinary);

        [, , , $cm, $data] = prepare_new_moduleinfo_data($ordinary, 'page', 1);
        $form = new \mod_page_mod_form($data, 1, $cm, $ordinary);

        $this->assertSame([], mod_edpreset_coursemodule_validation($form, [
            'edpreset_presetname' => '',
            'edpreset_description' => '',
        ]));
    }

    /**
     * Saving the form records the details, and saving again updates them in place.
     */
    public function test_post_actions_upserts_the_metadata(): void {
        $course = $this->setup_template_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);

        $moduleinfo = (object)[
            'coursemodule' => $page->cmid,
            'modulename' => 'page',
            'edpreset_presetname' => 'Weekly reading',
            'edpreset_description' => 'Use this for a weekly reading.',
            'edpreset_teacherguidance' => '  Set the due date before releasing.  ',
            // Duplicates differing only in case collapse to the first spelling seen.
            'edpreset_tags' => ' Content ,, engage with content, CONTENT ',
            'edpreset_defaultname' => 'This week\'s reading',
        ];

        $returned = mod_edpreset_coursemodule_edit_post_actions($moduleinfo, $course);
        $this->assertSame($moduleinfo, $returned, 'The callback must return the module info it was given.');

        $stored = meta::get_for_cm((int)$page->cmid);
        $this->assertNotNull($stored);
        $this->assertSame('Weekly reading', $stored->get('presetname'));
        $this->assertSame('Set the due date before releasing.', $stored->get('teacherguidance'));
        $this->assertSame('Content, engage with content', $stored->get('tags'));

        $moduleinfo->edpreset_presetname = 'Weekly reading, revised';
        mod_edpreset_coursemodule_edit_post_actions($moduleinfo, $course);

        $this->assertSame(1, meta::count_records(['cmid' => (int)$page->cmid]));
        $this->assertSame('Weekly reading, revised', meta::get_for_cm((int)$page->cmid)->get('presetname'));
    }

    /**
     * Nothing is stored for a module created without the form fields.
     *
     * Restores, web service calls and quick edits all reach this callback without them.
     */
    public function test_post_actions_ignores_module_info_without_the_fields(): void {
        $course = $this->setup_template_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);

        mod_edpreset_coursemodule_edit_post_actions(
            (object)['coursemodule' => $page->cmid, 'modulename' => 'page'],
            $course
        );

        $this->assertFalse(meta::exists_for_cm((int)$page->cmid));
    }

    /**
     * Nothing is stored for a module in an ordinary course.
     */
    public function test_post_actions_ignores_ordinary_courses(): void {
        $this->setup_template_course();
        $ordinary = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $ordinary->id]);

        mod_edpreset_coursemodule_edit_post_actions((object)[
            'coursemodule' => $page->cmid,
            'modulename' => 'page',
            'edpreset_presetname' => 'Should not be stored',
            'edpreset_description' => 'Should not be stored',
        ], $ordinary);

        $this->assertFalse(meta::exists_for_cm((int)$page->cmid));
    }

    /**
     * The declared user preference must be one core will accept a write for.
     */
    public function test_user_preference_is_declared(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $definition = \core_user::get_preference_definition(output\chooser_page::PREF_COLLAPSED);

        $this->assertSame(PARAM_RAW, $definition['type']);
        $this->assertTrue(\core_user::can_edit_preference(output\chooser_page::PREF_COLLAPSED, $USER));
    }
}
