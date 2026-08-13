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

namespace mod_edpreset\local;

use mod_edpreset\meta;
use mod_edpreset\preset;

/**
 * A whole section of the template course, offered as one addable set.
 *
 * Deliberately not a persistent, and there is no edpreset_template table. A section template is a
 * view over the preset rows that share a section: every member is an ordinary baked preset, so the
 * bake, scrub and validate pipeline needed no changes at all to support this. The two section-level
 * values a card needs - the stripped name and the summary - are denormalised onto every member row
 * by the baker, exactly as the section name already was in the category column.
 *
 * The identity of a template is its section number in the template course, not its name: two
 * sections could strip to the same name, and sectionnum is already stored and indexed.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class section_template {
    /** @var int Section number in the template course; the template's identity. */
    protected int $sectionnum;

    /** @var string Section name with the marker stripped. */
    protected string $name;

    /** @var string Cleaned HTML of the section summary. */
    protected string $summary;

    /** @var preset[] The member presets, in the order they appear in the template course. */
    protected array $members;

    /**
     * Constructor.
     *
     * @param int $sectionnum Section number in the template course.
     * @param string $name Section name with the marker stripped.
     * @param string $summary Cleaned HTML of the section summary.
     * @param preset[] $members The member presets, in template order.
     */
    public function __construct(int $sectionnum, string $name, string $summary, array $members) {
        $this->sectionnum = $sectionnum;
        $this->name = $name;
        $this->summary = $summary;
        $this->members = $members;
    }

    /**
     * The template's identity: its section number in the template course.
     *
     * @return int
     */
    public function get_sectionnum(): int {
        return $this->sectionnum;
    }

    /**
     * The name teachers see, with the marker already stripped.
     *
     * @return string
     */
    public function get_name(): string {
        return $this->name;
    }

    /**
     * The card's description: cleaned HTML of the section summary.
     *
     * @return string
     */
    public function get_summary(): string {
        return $this->summary;
    }

    /**
     * The member presets, in the order they appear in the template course.
     *
     * @return preset[]
     */
    public function get_members(): array {
        return $this->members;
    }

    /**
     * The member preset ids, in template order.
     *
     * @return int[]
     */
    public function get_member_ids(): array {
        return array_map(fn(preset $member) => (int)$member->get('id'), $this->members);
    }

    /**
     * How many activities this template adds.
     *
     * @return int
     */
    public function count_members(): int {
        return count($this->members);
    }

    /**
     * Every tag any member carries.
     *
     * De-duplicated case-insensitively with the first spelling winning, and collated, exactly as the
     * chooser page's own tag bar is built - the two lists have to agree or a tag would filter a
     * template out of a list it appears in.
     *
     * @return string[]
     */
    public function get_tags(): array {
        $seen = [];
        foreach ($this->members as $member) {
            foreach (meta::split_tags((string)$member->get('tags')) as $tag) {
                $key = \core_text::strtolower($tag);
                if (!isset($seen[$key])) {
                    $seen[$key] = $tag;
                }
            }
        }

        \core_collator::asort($seen);

        return array_values($seen);
    }

    /**
     * The human-readable activity type of each distinct member module, in template order.
     *
     * @return string[] E.g. ['Book', 'Page', 'Quiz'].
     */
    public function get_module_names(): array {
        $names = [];
        foreach ($this->members as $member) {
            $modname = $member->get('modname');
            if (!isset($names[$modname])) {
                $names[$modname] = get_string('modulename', 'mod_' . $modname);
            }
        }

        return array_values($names);
    }

    /**
     * The first member, which is what the card takes its appearance from.
     *
     * A template always has at least one member - a section with none is not offered at all - so
     * this never returns null in practice.
     *
     * @return preset
     */
    public function get_first_member(): preset {
        return reset($this->members);
    }

    /**
     * The card's icon: the icon of the first activity in the section.
     *
     * Deliberately not a generic "stack" glyph. What a teacher recognises a template by is what it
     * starts with, and the set-ness is already carried by the layered card styling, the activity
     * count and the list of activity types. Note that the card must also carry that member's purpose
     * and branded classes, or the icon renders without its colour - see get_purpose().
     *
     * Not stored on any row: the markup embeds $CFG->wwwroot and the theme revision, so it is
     * regenerated per request, exactly as preset does it.
     *
     * @return string
     */
    public function get_icon_html(): string {
        return $this->get_first_member()->get_icon_html();
    }

    /**
     * The module the card's icon belongs to, for the icon container's modicon_ class.
     *
     * @return string
     */
    public function get_modname(): string {
        return (string)$this->get_first_member()->get('modname');
    }

    /**
     * The MOD_PURPOSE_* of the card's icon, which is what colours it.
     *
     * @return string
     */
    public function get_purpose(): string {
        return (string)$this->get_first_member()->get('purpose');
    }

    /**
     * Whether the card's icon is a branded one, which must not be recoloured.
     *
     * @return bool
     */
    public function is_branded(): bool {
        return (bool)$this->get_first_member()->get('branded');
    }

    /**
     * Group a list of presets into the templates they belong to.
     *
     * Presets that are not template members are ignored, so this can be handed the whole live list.
     *
     * @param preset[] $presets Presets in sortorder, as the chooser fetches them.
     * @return self[] Keyed by section number, in the order the sections were met.
     */
    public static function from_presets(array $presets): array {
        $bysection = [];
        foreach ($presets as $preset) {
            if (!$preset->is_template_member()) {
                continue;
            }
            $bysection[(int)$preset->get('sectionnum')][] = $preset;
        }

        $templates = [];
        foreach ($bysection as $sectionnum => $members) {
            $templates[$sectionnum] = self::from_members($sectionnum, $members);
        }

        return $templates;
    }

    /**
     * The template a section holds, if it is one and has anything to offer.
     *
     * Deliberately not routed through the chooser's live list: that is capped by the maxpresets
     * setting, which is a limit on what one page may render rather than on what a template contains.
     *
     * @param int $sectionnum Section number in the template course.
     * @return self|null Null if that section is not a template, or has no live members.
     */
    public static function for_section(int $sectionnum): ?self {
        $candidates = preset::get_records(
            [
                'templatecourseid' => template::get_courseid(),
                'sectionnum' => $sectionnum,
                'enabled' => 1,
            ],
            'sortorder',
            'ASC'
        );

        $members = [];
        foreach ($candidates as $candidate) {
            if ($candidate->is_template_member() && $candidate->is_live()) {
                $members[] = $candidate;
            }
        }

        return $members ? self::from_members($sectionnum, $members) : null;
    }

    /**
     * Whether the template course still holds a template of this name.
     *
     * Deliberately does NOT require the template's members to be live. "Still exists" has to mean
     * the curator has not deleted or renamed the section, not "is offerable this minute": a template
     * mid-rebake momentarily has no live members, and treating that as gone would let a course that
     * had settled on it slip its lock for good over a few minutes of cron.
     *
     * @param string $name The template name, as recorded against a course.
     * @return bool
     */
    public static function name_exists(string $name): bool {
        if (trim($name) === '') {
            return false;
        }

        return preset::record_exists_select(
            'templatecourseid = :courseid AND templatename = :name AND enabled = 1',
            ['courseid' => template::get_courseid(), 'name' => $name]
        );
    }

    /**
     * Build a template from its members.
     *
     * The name and summary are taken from the first member: every member of a section carries the
     * same denormalised copy, so any of them would do.
     *
     * @param int $sectionnum Section number in the template course.
     * @param preset[] $members The member presets, in template order.
     * @return self
     */
    protected static function from_members(int $sectionnum, array $members): self {
        $first = reset($members);

        return new self(
            $sectionnum,
            (string)$first->get('templatename'),
            (string)$first->get('templatesummary'),
            array_values($members)
        );
    }
}
