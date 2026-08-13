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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState

use Behat\Mink\Exception\ExpectationException;

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Steps and selectors for the activity preset provider.
 *
 * Both routes to the preset chooser carry a sesskey, and a step definition cannot mint one: it runs
 * in the test runner's process, which has no share of the browser's session. So the navigation steps
 * here load an ordinary page first and read the sesskey the browser was actually given.
 *
 * @package    mod_edpreset
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_edpreset extends behat_base {
    /**
     * Selectors for the preset chooser page.
     *
     * @return behat_component_named_selector[]
     */
    public static function get_partial_named_selectors(): array {
        // data-region="card" is what the page's filter keys on, and it is not always on the card
        // element itself: a section template wraps its card together with the note explaining why it
        // cannot be chosen, and the wrapper is what carries the attribute. So the kind of card is
        // decided by looking for the marker class among the descendants rather than on the matched
        // element, which holds however the two are nested.
        $istemplate = ".//*[contains(concat(' ', normalize-space(@class), ' '), ' edpreset-template-card ')]";

        $card = ".//*[@data-region='card']%extra%" .
            "[.//*[contains(concat(' ', normalize-space(@class), ' '), ' edpreset-card-title ')]" .
            "[contains(normalize-space(.), %locator%)]]";

        return [
            new behat_component_named_selector('Section template', [
                str_replace('%extra%', "[$istemplate]", $card),
            ]),
            new behat_component_named_selector('Preset', [
                str_replace('%extra%', "[not($istemplate)]", $card),
            ]),
        ];
    }

    /**
     * Open the full preset chooser page against a course section.
     *
     * @Given /^I open the preset chooser for course "([^"]*)" section "(\d+)"$/
     * @param string $shortname The course shortname.
     * @param int $sectionnum The section the activities would be added to.
     */
    public function i_open_the_preset_chooser_for_course_section(string $shortname, int $sectionnum) {
        $courseid = $this->get_course_id($shortname);

        $this->visit_with_sesskey('/mod/edpreset/chooser.php', $courseid, [
            'course' => $courseid,
            'section' => $sectionnum,
        ]);
    }

    /**
     * Open the section templates page - the sectionid entry point.
     *
     * @Given /^I open the section templates page for course "([^"]*)" section "(\d+)"$/
     * @param string $shortname The course shortname.
     * @param int $sectionnum The section the activities would be added to.
     */
    public function i_open_the_section_templates_page_for_course_section(string $shortname, int $sectionnum) {
        global $DB;

        $courseid = $this->get_course_id($shortname);
        $sectionid = $DB->get_field(
            'course_sections',
            'id',
            ['course' => $courseid, 'section' => $sectionnum],
            MUST_EXIST
        );

        $this->visit_with_sesskey('/mod/edpreset/chooser.php', $courseid, ['sectionid' => $sectionid]);
    }

    /**
     * Bake every preset, as cron would.
     *
     * Deliberately runs the real pipeline - a genuine backup of each exemplar, then a test restore
     * into the sandbox - rather than faking an archive. Anything that actually adds a preset to a
     * course needs an archive that restores, and only the real thing is one. It is slow for the
     * same reason.
     *
     * @Given /^the mod_edpreset presets have been baked$/
     */
    public function the_mod_edpreset_presets_have_been_baked() {
        \behat_util::get_data_generator()->get_plugin_generator('mod_edpreset')->run_pipeline();
    }

    /**
     * Check the default section template recorded against a course.
     *
     * The custom field is deliberately visible to nobody, so no page anywhere shows it.
     *
     * @Then /^the default section template for course "([^"]*)" should be "([^"]*)"$/
     * @param string $shortname The course shortname.
     * @param string $expected The expected template name.
     * @throws ExpectationException If the recorded value is something else.
     */
    public function the_default_section_template_for_course_should_be(string $shortname, string $expected) {
        $actual = \mod_edpreset\local\coursedefault::get($this->get_course_id($shortname));

        if ($actual !== $expected) {
            throw new ExpectationException(
                "Expected the default section template to be '{$expected}', but it is '{$actual}'.",
                $this->getSession()
            );
        }
    }

    /**
     * Visit a plugin page that requires a sesskey.
     *
     * The course page is loaded first purely to get a page whose sesskey belongs to the browser's
     * session; the runner cannot produce one itself.
     *
     * @param string $path The path to visit.
     * @param int $courseid The course to load first.
     * @param array $params Query parameters, to which the sesskey is added.
     */
    protected function visit_with_sesskey(string $path, int $courseid, array $params) {
        $this->execute('behat_general::i_visit', ['/course/view.php?id=' . $courseid]);

        $params['sesskey'] = $this->get_browser_sesskey();

        $this->execute('behat_general::i_visit', [$path . '?' . http_build_query($params)]);
    }

    /**
     * The sesskey of the session the browser is currently in.
     *
     * Read out of the M.cfg block every Moodle page emits, which works without JavaScript because it
     * is in the markup rather than computed from it.
     *
     * @return string
     * @throws ExpectationException If the current page has no sesskey in it.
     */
    protected function get_browser_sesskey(): string {
        $content = $this->getSession()->getPage()->getContent();

        if (!preg_match('/"sesskey":"([A-Za-z0-9]+)"/', $content, $matches)) {
            throw new ExpectationException(
                'Could not read a sesskey from the current page. Is anyone logged in?',
                $this->getSession()
            );
        }

        return $matches[1];
    }

    /**
     * The id of a course, by shortname.
     *
     * @param string $shortname The course shortname.
     * @return int
     */
    protected function get_course_id(string $shortname): int {
        global $DB;

        return (int)$DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
    }
}
