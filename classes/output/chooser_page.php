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
use mod_edpreset\local\chooser;
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

    /** @var stdClass The target course. */
    protected stdClass $course;

    /** @var int The section the activity chooser was opened from. */
    protected int $sectionnum;

    /** @var int Course module id to insert before, or 0 to append. */
    protected int $beforemod;

    /**
     * Constructor.
     *
     * @param stdClass $course The target course.
     * @param int $sectionnum The section the activity chooser was opened from.
     * @param int $beforemod Course module id to insert before, or 0 to append.
     */
    public function __construct(stdClass $course, int $sectionnum, int $beforemod = 0) {
        $this->course = $course;
        $this->sectionnum = $sectionnum;
        $this->beforemod = $beforemod;
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
        $favourites = self::get_favourited_ids();
        $collapsed = self::get_collapsed_keys();

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

        $groups = [];
        if ($starred) {
            $groups[] = $this->export_group(get_string('chooser:starred', 'mod_edpreset'), $starred, $collapsed, true);
        }
        foreach ($bycategory as $category => $cards) {
            $groups[] = $this->export_group($category, $cards, $collapsed, false);
        }

        $data = new stdClass();
        $data->groups = $groups;
        $data->hasgroups = !empty($groups);
        $data->courseid = (int)$this->course->id;
        $data->sectionnum = $this->sectionnum;
        $data->beforemod = $this->beforemod;
        $data->sesskey = sesskey();
        $data->alltags = $this->export_tags($presets);
        $data->hastags = !empty($data->alltags);
        // Where the page's form posts the selection. The ids themselves are filled in client-side.
        $data->copyurl = (new moodle_url('/mod/edpreset/copy.php'))->out(false);

        return $data;
    }

    /**
     * One accordion group.
     *
     * @param string $name The group heading.
     * @param stdClass[] $cards The cards in the group.
     * @param string[] $collapsed The section keys this user has collapsed.
     * @param bool $isstarred Whether this is the Starred pseudo-group.
     * @return stdClass
     */
    protected function export_group(string $name, array $cards, array $collapsed, bool $isstarred): stdClass {
        $key = self::section_key($name);

        $group = new stdClass();
        $group->name = $name;
        $group->key = $key;
        $group->starred = $isstarred;
        $group->cards = $cards;
        $group->count = count($cards);
        $group->expanded = !in_array($key, $collapsed, true);

        return $group;
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
