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

namespace mod_edpreset\output;

use core\output\renderer_base;
use core\output\templatable;
use mod_edpreset\local\activity_copier;
use mod_edpreset\local\chooser;
use mod_edpreset\local\coursedefault;
use mod_edpreset\local\section_template;
use mod_edpreset\meta;
use mod_edpreset\preset;
use moodle_url;
use renderable;
use stdClass;

/**
 * The preset chooser page: everything the standard activity chooser does not show.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chooser_page implements renderable, templatable {
    /** @var string User preference holding the section groups this user has collapsed. */
    public const PREF_COLLAPSED = 'mod_edpreset_collapsed';

    /** @var int Section groups are keyed by a short hash of their name; see section_key(). */
    protected const SECTION_KEY_LENGTH = 8;

    /** @var int How many distinct activity types a section template's card names. */
    protected const MAX_MODULE_NAMES = 3;

    /** @var stdClass The target course. */
    protected stdClass $course;

    /** @var int The section the activity chooser was opened from. */
    protected int $sectionnum;

    /** @var int Course module id to insert before, or 0 to append. */
    protected int $beforemod;

    /** @var bool Whether to show only the section templates. */
    protected bool $templatesonly;

    /**
     * Constructor.
     *
     * @param stdClass $course The target course.
     * @param int $sectionnum The section the activity chooser was opened from.
     * @param int $beforemod Course module id to insert before, or 0 to append.
     * @param bool $templatesonly Show only the section templates, for the sectionid entry point.
     */
    public function __construct(
        stdClass $course,
        int $sectionnum,
        int $beforemod = 0,
        bool $templatesonly = false
    ) {
        $this->course = $course;
        $this->sectionnum = $sectionnum;
        $this->beforemod = $beforemod;
        $this->templatesonly = $templatesonly;
    }

    /**
     * The stable key a section group is collapsed against.
     *
     * A hash of the name rather than the name itself, because the whole set has to fit in
     * user_preferences.value, which is char(1333) and throws above that. Section names are
     * char(255), so eight of them could overflow it; eight-character hashes fit about 140.
     *
     * @param string $sectionname The section name.
     * @return string
     */
    public static function section_key(string $sectionname): string {
        return substr(sha1($sectionname), 0, self::SECTION_KEY_LENGTH);
    }

    /**
     * Build the page context.
     *
     * @param renderer_base $output The renderer.
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        $presets = chooser::get_page_presets();
        $collapsed = self::get_collapsed_keys();

        // A section template is offered as a set, so its members never become cards of their own.
        $templates = section_template::from_presets($presets);
        $individuals = array_values(array_filter($presets, fn(preset $preset) => !$preset->is_template_member()));

        // Once a course has used one template it keeps to it, so every other template is shown but
        // cannot be chosen. Read once here rather than per card.
        $usedtemplate = coursedefault::get((int)$this->course->id);

        $templatecards = [];
        foreach ($templates as $template) {
            $templatecards[] = $this->export_template_card($template, $usedtemplate);
        }

        // Starred first, then the individual categories, then the sets last.
        ['starred' => $starred, 'categories' => $categories] = $this->templatesonly
            ? ['starred' => null, 'categories' => []]
            : $this->export_preset_groups($individuals, $collapsed);

        $groups = [];
        if ($starred) {
            $groups[] = $starred;
        }

        $groups = array_merge($groups, $categories);

        if ($templatecards) {
            $groups[] = $this->export_group(
                get_string('chooser:sectiontemplates', 'mod_edpreset'),
                $templatecards,
                $collapsed,
                false,
                true
            );
        }

        // In templates-only mode the tag bar offers only the tags some template actually carries:
        // anything else would filter the page to nothing, which is worse than not offering the tag.
        $tagsource = $this->templatesonly ? $this->template_members($templates) : $presets;

        $data = new stdClass();
        $data->groups = $groups;
        $data->hasgroups = !empty($groups);
        $data->templatesonly = $this->templatesonly;
        $data->courseid = (int)$this->course->id;
        $data->sectionnum = $this->sectionnum;
        $data->beforemod = $this->beforemod;
        // What decides whether adding a template needs the reorder dialogue. Sent with the page so
        // the common case costs no round trip; the dialogue re-reads the section when it opens.
        $data->sectionactivitycount = $this->count_section_activities();
        $data->sesskey = sesskey();
        $data->alltags = $this->export_tags($tagsource);
        $data->hastags = !empty($data->alltags);
        // Where the page's form posts the selection. The ids themselves are filled in client-side.
        $data->copyurl = (new moodle_url('/mod/edpreset/copy.php'))->out(false);

        return $data;
    }

    /**
     * The "Starred" and per-category groups.
     *
     * Handed back separately rather than as one list because the section templates group belongs
     * between them.
     *
     * @param preset[] $presets The presets that are not section template members.
     * @param string[] $collapsed The section keys this user has collapsed.
     * @return array{starred: ?stdClass, categories: stdClass[]}
     */
    protected function export_preset_groups(array $presets, array $collapsed): array {
        $favourites = self::get_favourited_ids();

        $starred = [];
        $bycategory = [];
        foreach ($presets as $preset) {
            $card = $this->export_card($preset, $favourites);

            // A starred preset is shown in both places, exactly as the standard chooser's Starred
            // tab does. The two copies are kept in step client-side by their shared preset id.
            if ($card->favourited) {
                $starred[] = $card;
            }

            $category = (string)$preset->get('category');
            $bycategory[$category][] = $card;
        }

        $categories = [];
        foreach ($bycategory as $category => $cards) {
            $categories[] = $this->export_group($category, $cards, $collapsed, false);
        }

        return [
            'starred' => $starred
                ? $this->export_group(get_string('chooser:starred', 'mod_edpreset'), $starred, $collapsed, true)
                : null,
            'categories' => $categories,
        ];
    }

    /**
     * Every member preset of every template, flattened.
     *
     * @param section_template[] $templates The templates.
     * @return preset[]
     */
    protected function template_members(array $templates): array {
        $members = [];
        foreach ($templates as $template) {
            foreach ($template->get_members() as $member) {
                $members[] = $member;
            }
        }
        return $members;
    }

    /**
     * How many activities the target section already holds.
     *
     * Teacher notes do not count. They are chrome that travels with the activity they describe, so a
     * section holding one activity and its note is still a section holding one activity - and asking
     * the teacher to reorder that would be noise.
     *
     * @return int
     */
    protected function count_section_activities(): int {
        $modinfo = get_fast_modinfo($this->course);

        $count = 0;
        foreach ($modinfo->sections[$this->sectionnum] ?? [] as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            if ($cm->deletioninprogress || $cm->modname === activity_copier::NOTE_MODNAME) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    /**
     * One accordion group.
     *
     * @param string $name The group heading.
     * @param stdClass[] $cards The cards in the group.
     * @param string[] $collapsed The section keys this user has collapsed.
     * @param bool $isstarred Whether this is the Starred pseudo-group.
     * @param bool $istemplategroup Whether these are section template cards rather than preset cards.
     * @return stdClass
     */
    protected function export_group(
        string $name,
        array $cards,
        array $collapsed,
        bool $isstarred,
        bool $istemplategroup = false
    ): stdClass {
        $key = self::section_key($name);

        $group = new stdClass();
        $group->name = $name;
        $group->key = $key;
        $group->starred = $isstarred;
        // Mustache cannot choose a partial dynamically, so the template branches on this.
        $group->istemplategroup = $istemplategroup;
        $group->cards = $cards;
        $group->count = count($cards);
        $group->expanded = !in_array($key, $collapsed, true);

        return $group;
    }

    /**
     * One section template card.
     *
     * Deliberately does NOT carry data-presetid. chooser.js parses that attribute and then
     * dereferences the card's select button unguarded, so a card with a preset id but no select
     * button would throw the moment anything tried to select it.
     *
     * @param section_template $template The template.
     * @param string $usedtemplate The template this course has already used, or '' if none.
     * @return stdClass
     */
    protected function export_template_card(section_template $template, string $usedtemplate = ''): stdClass {
        $tags = $template->get_tags();
        $summary = $template->get_summary();
        $modulenames = $template->get_module_names();

        $card = new stdClass();
        $card->templatesection = $template->get_sectionnum();
        $card->title = $template->get_name();
        // Already cleaned at bake time, so the template renders it unescaped - same contract as a
        // preset description.
        $card->description = $summary;
        // The first member's icon, with the classes that colour it: the icon container needs the
        // module, purpose and branded flag or the icon comes out grey.
        $card->icon = $template->get_icon_html();
        $card->modname = $template->get_modname();
        $card->purpose = $template->get_purpose();
        $card->branded = $template->is_branded();
        $card->modfullname = $this->summarise_module_names($modulenames);
        // Drives the stacked-tile treatment behind the icon. Keyed on distinct activity types
        // rather than on activity count, because the icon stands for a type: the stack is there to
        // say the set holds kinds the icon is not showing.
        $card->hasmultipletypes = count($modulenames) > 1;
        // Shown, but not choosable: the course has already been built from a different template.
        // Asked of coursedefault rather than worked out here, so that the card and the check
        // copy.php makes cannot drift apart - and so the site settings that qualify the rule reach
        // both. The record is passed in because the page weighs several templates against the same
        // one.
        $card->locked = !coursedefault::allows_for_record($usedtemplate, $template->get_name());
        $card->recommended = !$card->locked
            && $usedtemplate !== ''
            && $usedtemplate === $template->get_name();
        $card->count = $template->count_members();
        $card->addurl = $this->template_add_url($template->get_sectionnum())->out(false);
        $card->tags = array_map(fn($tag) => (object)['name' => $tag], $tags);
        $card->hastags = !empty($tags);

        // The same flattening the preset cards get, so one filter pass covers both kinds of card.
        // Module names are included so that searching for "Quiz" finds the sets containing one.
        $card->searchtext = \core_text::strtolower(
            trim(
                $card->title
                . ' ' . html_to_text($summary, 0, false)
                . ' ' . implode(' ', $modulenames)
                . ' ' . implode(' ', $tags)
            )
        );
        $card->tagkeys = \core_text::strtolower(implode('|', $tags));

        return $card;
    }

    /**
     * Name the distinct activity types a template holds, without letting a long set take over the card.
     *
     * @param string[] $modulenames The distinct activity type names, in template order.
     * @return string
     */
    protected function summarise_module_names(array $modulenames): string {
        $shown = array_slice($modulenames, 0, self::MAX_MODULE_NAMES);
        $list = implode(', ', $shown);

        return count($modulenames) > count($shown)
            ? get_string('chooser:templatetypesmore', 'mod_edpreset', $list)
            : $list;
    }

    /**
     * The link on a template card that adds that whole set.
     *
     * An ordinary link to the same script the single-preset cards use, so adding a template still
     * works with JavaScript turned off - it just adds the set in template order, without offering
     * the chance to interleave it with what is already in the section.
     *
     * @param int $templatesection The template's section number in the template course.
     * @return moodle_url
     */
    protected function template_add_url(int $templatesection): moodle_url {
        return new moodle_url('/mod/edpreset/copy.php', [
            'template' => $templatesection,
            'course' => $this->course->id,
            'section' => $this->sectionnum,
            'beforemod' => $this->beforemod,
            'sesskey' => sesskey(),
        ]);
    }

    /**
     * One preset card.
     *
     * @param preset $preset The preset.
     * @param int[] $favourites The preset ids this user has starred.
     * @return stdClass
     */
    protected function export_card(preset $preset, array $favourites): stdClass {
        $presetid = (int)$preset->get('id');
        $description = (string)$preset->get('description');
        $guidance = (string)$preset->get('teacherguidance');
        $tags = meta::split_tags((string)$preset->get('tags'));

        $card = new stdClass();
        $card->presetid = $presetid;
        $card->title = $preset->get('title');
        $card->description = $description;
        // Already cleaned at bake time, so the template renders it unescaped - same contract as
        // description. Collapsed behind a disclosure so long guidance does not distort the grid.
        $card->teacherguidance = $guidance;
        $card->hasguidance = $guidance !== '';
        $card->icon = $preset->get_icon_html();
        $card->modname = $preset->get('modname');
        // The human-readable activity type, e.g. "Assignment" for mod_assign. Same source core
        // uses for the activity icon's tooltip on the course page.
        $card->modfullname = get_string('modulename', 'mod_' . $preset->get('modname'));
        $card->purpose = $preset->get('purpose');
        $card->branded = (bool)$preset->get('branded');
        $card->favourited = in_array($presetid, $favourites, true);
        $card->addurl = $this->add_url($presetid)->out(false);
        $card->tags = array_map(fn($tag) => (object)['name' => $tag], $tags);
        $card->hastags = !empty($tags);

        // Everything the in-page text filter matches against, flattened once here so the browser
        // does not have to walk the DOM for it on every keystroke.
        $card->searchtext = \core_text::strtolower(
            trim(
                $card->title
                . ' ' . html_to_text($description, 0, false)
                . ' ' . html_to_text($guidance, 0, false)
                . ' ' . implode(' ', $tags)
            )
        );
        $card->tagkeys = \core_text::strtolower(implode('|', $tags));

        return $card;
    }

    /**
     * Every tag in use, for the filter bar.
     *
     * @param preset[] $presets The presets on the page.
     * @return stdClass[]
     */
    protected function export_tags(array $presets): array {
        $seen = [];
        foreach ($presets as $preset) {
            foreach (meta::split_tags((string)$preset->get('tags')) as $tag) {
                $seen[\core_text::strtolower($tag)] = $tag;
            }
        }

        \core_collator::asort($seen);

        return array_map(fn($tag) => (object)['name' => $tag], array_values($seen));
    }

    /**
     * The link on a card that adds that one preset.
     *
     * An ordinary link to the same script the form posts to, so a single add still works with
     * JavaScript turned off - which is the whole of what this page offers without it.
     *
     * @param int $presetid The preset id.
     * @return moodle_url
     */
    protected function add_url(int $presetid): moodle_url {
        return new moodle_url('/mod/edpreset/copy.php', [
            'presets' => $presetid,
            'course' => $this->course->id,
            'section' => $this->sectionnum,
            'beforemod' => $this->beforemod,
            'sesskey' => sesskey(),
        ]);
    }

    /**
     * The preset ids the current user has starred.
     *
     * @return int[]
     */
    public static function get_favourited_ids(): array {
        global $USER;

        $service = \core_favourites\service_factory::get_service_for_user_context(
            \context_user::instance($USER->id)
        );

        $ids = [];
        foreach ($service->find_favourites_by_type(preset::FAVOURITE_COMPONENT, preset::FAVOURITE_ITEMTYPE) as $favourite) {
            $ids[] = (int)$favourite->itemid;
        }

        return $ids;
    }

    /**
     * The section keys the current user has collapsed.
     *
     * @return string[]
     */
    public static function get_collapsed_keys(): array {
        $stored = (string)get_user_preferences(self::PREF_COLLAPSED, '');

        return array_values(array_filter(explode(',', $stored), 'strlen'));
    }
}
