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

use mod_edpreset\output\chooser_page;
use stdClass;

/**
 * Tests for what the preset chooser page offers: recommended section pseudo tags and restricted templates.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_edpreset\output\chooser_page
 * @covers     \mod_edpreset\local\section_template::get_recommended_sections
 * @covers     \mod_edpreset\local\access::can_use_template
 */
final class chooser_page_test extends \advanced_testcase {
    /**
     * Export the chooser page for a fresh course.
     *
     * @param bool $templatesonly Show only the section templates.
     * @param stdClass|null $course The course to export it for, or null for a fresh one.
     * @return stdClass
     */
    private function export(bool $templatesonly = false, ?stdClass $course = null): stdClass {
        global $PAGE;

        $course = $course ?? $this->getDataGenerator()->create_course(['numsections' => 2]);
        $page = new chooser_page($course, 1, 0, $templatesonly);

        return $page->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * Every card on the page, keyed by title.
     *
     * @param stdClass $data The exported page.
     * @return stdClass[]
     */
    private function cards_by_title(stdClass $data): array {
        $cards = [];
        foreach ($data->groups as $group) {
            foreach ($group->cards as $card) {
                $cards[$card->title] = $card;
            }
        }
        return $cards;
    }

    /**
     * The names in an exported list of tags or sections.
     *
     * @param stdClass[] $items The exported items.
     * @return string[]
     */
    private function names(array $items): array {
        return array_map(fn($item) => $item->name, $items);
    }

    /**
     * A recommended section is offered as a filterable pseudo tag, separate from the real tags.
     */
    public function test_recommended_section_is_a_pseudo_tag(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_edpreset');
        $templatecourse = $plugingenerator->create_template_course();

        $plugingenerator->create_preset([
            'templatecourseid' => $templatecourse->id,
            'title' => 'Welcome forum',
            'tags' => 'Welcome',
            'recommendedsection' => 'Nau mai | Welcome',
        ]);
        // The same section in a different case is still the one filter, in the first spelling met.
        $plugingenerator->create_preset([
            'templatecourseid' => $templatecourse->id,
            'title' => 'Course outline',
            'recommendedsection' => 'nau mai | welcome',
        ]);
        $plugingenerator->create_preset([
            'templatecourseid' => $templatecourse->id,
            'title' => 'Assessment guide',
            'recommendedsection' => 'He whakamārama | All about the course',
        ]);
        $plugingenerator->create_preset([
            'templatecourseid' => $templatecourse->id,
            'title' => 'Untagged page',
        ]);

        $data = $this->export();

        // A tag and a section sharing a word stay two separate filters.
        $this->assertSame(['Welcome'], $this->names($data->alltags));
        $this->assertSame(
            ['He whakamārama | All about the course', 'Nau mai | Welcome'],
            $this->names($data->allsections)
        );
        $this->assertTrue($data->hastags);

        $cards = $this->cards_by_title($data);

        $forum = $cards['Welcome forum'];
        $this->assertSame(['Nau mai | Welcome'], $this->names($forum->sections));
        $this->assertSame(['Welcome'], $this->names($forum->tags));
        $this->assertSame('welcome', $forum->tagkeys);
        // The pipe in the name is exactly why the keys are JSON rather than pipe separated.
        $this->assertSame(['nau mai | welcome'], json_decode($forum->sectionkeys));
        $this->assertStringContainsString('nau mai | welcome', $forum->searchtext);

        // A section alone is enough to give a card a tag row.
        $outline = $cards['Course outline'];
        $this->assertTrue($outline->hastags);
        $this->assertSame([], $this->names($outline->tags));

        $untagged = $cards['Untagged page'];
        $this->assertFalse($untagged->hastags);
        $this->assertSame([], $untagged->sections);
        $this->assertSame([], json_decode($untagged->sectionkeys));
    }

    /**
     * Sections alone are enough for a tag bar.
     */
    public function test_sections_alone_warrant_a_tag_bar(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_edpreset');
        $templatecourse = $plugingenerator->create_template_course();

        $plugingenerator->create_preset([
            'templatecourseid' => $templatecourse->id,
            'recommendedsection' => 'Nau mai | Welcome',
        ]);

        $data = $this->export();

        $this->assertSame([], $data->alltags);
        $this->assertTrue($data->hastags);
    }

    /**
     * A section template's card carries every recommended section among its members.
     *
     * The tag bar in templates-only mode is built from the members, so the card has to carry the
     * same sections or a section in the bar would filter its own template out.
     */
    public function test_template_card_carries_its_members_sections(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_edpreset');
        $templatecourse = $plugingenerator->create_template_course();

        $member = [
            'templatecourseid' => $templatecourse->id,
            'sectionnum' => 3,
            'templatename' => 'Induction',
        ];
        $plugingenerator->create_preset($member + ['recommendedsection' => 'Nau mai | Welcome']);
        $plugingenerator->create_preset($member + ['recommendedsection' => 'NAU MAI | WELCOME']);
        $plugingenerator->create_preset($member + ['recommendedsection' => 'He whakamārama | All about the course']);
        $plugingenerator->create_preset($member);

        $data = $this->export(true);
        $card = $this->cards_by_title($data)['Induction'];

        $expected = ['He whakamārama | All about the course', 'Nau mai | Welcome'];
        $this->assertSame($expected, $this->names($card->sections));
        $this->assertSame($expected, $this->names($data->allsections));
        $this->assertSame(
            ['he whakamārama | all about the course', 'nau mai | welcome'],
            json_decode($card->sectionkeys)
        );
    }

    /**
     * Create one restricted template and one ordinary one, each with a tagged member.
     *
     * @return stdClass The template course.
     */
    private function create_restricted_and_open_templates(): stdClass {
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_edpreset');
        $templatecourse = $plugingenerator->create_template_course();

        $plugingenerator->create_preset([
            'templatecourseid' => $templatecourse->id,
            'sectionnum' => 3,
            'templatename' => 'Learning model',
            'templaterestricted' => 1,
            'tags' => 'Restricted tag',
            'recommendedsection' => 'Restricted section',
        ]);
        $plugingenerator->create_preset([
            'templatecourseid' => $templatecourse->id,
            'sectionnum' => 4,
            'templatename' => 'Weekly cycle',
            'tags' => 'Open tag',
        ]);

        return $templatecourse;
    }

    /**
     * A restricted template is not shown to a teacher whose course has not used it - not even
     * locked - and nothing of it reaches the filter bar.
     */
    public function test_restricted_template_is_hidden_from_a_teacher(): void {
        $this->resetAfterTest();
        $this->create_restricted_and_open_templates();

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        foreach ([false, true] as $templatesonly) {
            $data = $this->export($templatesonly, $course);

            $this->assertSame(['Weekly cycle'], array_keys($this->cards_by_title($data)));
            $this->assertSame(['Open tag'], $this->names($data->alltags));
            $this->assertSame([], $data->allsections);
        }
    }

    /**
     * A course that has already used a restricted template still sees it.
     */
    public function test_restricted_template_is_shown_to_a_course_that_used_it(): void {
        $this->resetAfterTest();
        $this->create_restricted_and_open_templates();

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));
        \mod_edpreset\local\coursedefault::set((int)$course->id, 'Learning model');

        $data = $this->export(true, $course);
        $cards = $this->cards_by_title($data);

        $this->assertArrayHasKey('Learning model', $cards);
        $this->assertFalse($cards['Learning model']->locked);
        $this->assertSame(['Restricted section'], $this->names($data->allsections));
    }

    /**
     * An ordinary section's summary is shown under its group heading, and only there.
     */
    public function test_section_summary_is_shown_under_its_group(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_edpreset');
        $templatecourse = $plugingenerator->create_template_course();

        $starred = $plugingenerator->create_preset([
            'templatecourseid' => $templatecourse->id,
            'category' => 'Formative assessment',
            'sectionsummary' => '<p>Ways to check understanding.</p>',
        ]);
        $plugingenerator->create_preset([
            'templatecourseid' => $templatecourse->id,
            'category' => 'Knowledge check',
            // What an editor that was typed into and emptied again leaves behind.
            'sectionsummary' => '<p></p>',
        ]);
        $plugingenerator->create_preset([
            'templatecourseid' => $templatecourse->id,
            'sectionnum' => 3,
            'templatename' => 'Weekly cycle',
            'sectionsummary' => '<p>A week of teaching.</p>',
        ]);
        \mod_edpreset\external\set_favourite::execute((int)$starred->get('id'), true);

        $groups = [];
        foreach ($this->export()->groups as $group) {
            $groups[$group->name] = $group;
        }

        $this->assertTrue($groups['Formative assessment']->hassummary);
        $this->assertSame('<p>Ways to check understanding.</p>', $groups['Formative assessment']->summary);
        $this->assertFalse($groups['Knowledge check']->hassummary);

        // A template's summary is its card's description, not its group's.
        $this->assertFalse($groups[get_string('chooser:sectiontemplates', 'mod_edpreset')]->hassummary);
        $this->assertSame(
            '<p>A week of teaching.</p>',
            $this->cards_by_title($this->export(true))['Weekly cycle']->description
        );

        // Starred is not a section, so it has no summary of its own.
        $this->assertFalse($groups[get_string('chooser:starred', 'mod_edpreset')]->hassummary);
    }
}
