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

use core_course\local\entity\content_item;
use core_course\local\entity\string_title;
use mod_edpreset\preset;
use moodle_url;
use stdClass;

/**
 * Turns preset records into activity chooser content items.
 *
 * Core requires every chooser item to originate from a mod_* component
 * (\core_course\local\service\content_item_service::get_content_items_for_user_in_course throws
 * otherwise), which is the whole reason this plugin is an activity module rather than a local one.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chooser {
    /** @var int Fallback cap on how many presets may be offered at once. */
    protected const DEFAULT_MAX_PRESETS = 100;

    /**
     * The content item id of the placeholder that opens the preset chooser page.
     *
     * -1 is not an arbitrary sentinel. course_content_item_exporter sets
     * 'legacyitem' => (id == -1), and the chooser's item template wraps its favourite star in
     * {{^legacyitem}}, so this is the one value that renders an item with no star. That matters:
     * content_item_service::add_to_user_favourites() resolves a starred id with array_search()
     * over get_all_content_items() and, on failure, silently indexes $items[0] - an unrelated
     * module. Any other id would put a star on something that is not in that list.
     *
     * @var int
     */
    public const PLACEHOLDER_ID = -1;

    /**
     * The presets offered in a given course.
     *
     * Only the priority section's presets go here, plus one placeholder leading to the rest. The
     * standard chooser renders every item it is given at once, so it stops being usable as soon as
     * the template course grows.
     *
     * Access is already gated upstream: content_item_service requires moodle/course:manageactivities
     * and applies course_allowed_module() (i.e. mod/edpreset:addinstance) to everything we return.
     *
     * @param stdClass $course The course whose chooser is being built.
     * @param stdClass $user The user the chooser is being built for.
     * @return content_item[]
     */
    public static function get_content_items(stdClass $course, stdClass $user): array {
        if (!self::is_offered_in($course)) {
            return [];
        }

        $items = [];
        foreach (self::get_live_presets() as $preset) {
            if ((int)$preset->get('sectionnum') !== template::priority_section()) {
                continue;
            }
            $items[] = self::make_content_item($preset, $course);
        }

        $items[] = self::make_placeholder_item($course);

        return $items;
    }

    /**
     * Whether presets are offered in a course at all.
     *
     * @param stdClass $course The course.
     * @return bool
     */
    public static function is_offered_in(stdClass $course): bool {
        if (!template::is_configured()) {
            return false;
        }

        // Never offer presets inside a template course itself: the curator is editing the
        // exemplars there, and copying one back into the same course only causes confusion.
        return !template::is_template_course((int)$course->id);
    }

    /**
     * The presets reached through the preset chooser page rather than the standard chooser.
     *
     * @return preset[]
     */
    public static function get_page_presets(): array {
        $presets = [];
        foreach (self::get_live_presets() as $preset) {
            /*
            if ((int)$preset->get('sectionnum') === template::priority_section()) {
                continue;
            }
                */
            $presets[] = $preset;
        }
        return $presets;
    }

    /**
     * The chooser item that opens the preset chooser page.
     *
     * @param stdClass $course The target course.
     * @return content_item
     */
    protected static function make_placeholder_item(stdClass $course): content_item {
        global $OUTPUT;

        return new content_item(
            self::PLACEHOLDER_ID,
            'edpreset_template',
            new string_title(get_string('chooser:placeholdertitle', 'mod_edpreset')),
            // Must already contain a "?": activitychooser.js string-appends "&section=..." to it.
            new moodle_url('/mod/edpreset/chooser.php', [
                'course' => $course->id,
                'sesskey' => sesskey(),
            ]),
            $OUTPUT->pix_icon('monologo', '', 'mod_edpreset', ['class' => 'activityicon']),
            get_string('chooser:placeholderhelp', 'mod_edpreset'),
            // Under the "Activities and resources" tab modes there is no "All" tab, and the
            // Activities tab filters on archetype === MOD_ARCHETYPE_OTHER, so anything else here
            // would make the placeholder invisible on sites configured that way.
            MOD_ARCHETYPE_OTHER,
            'mod_edpreset',
            MOD_PURPOSE_CONTENT,
            false
        );
    }

    /**
     * Every preset, without course context.
     *
     * Deliberately a superset of get_content_items(), which offers only the priority section.
     * content_item_service::add_to_user_favourites() looks a starred id up with array_search()
     * against this list and, when that fails, indexes $items[0] instead - so anything that can
     * carry a star in the standard chooser must appear here. Narrowing this to match
     * get_content_items() would break that, and would also hide the other presets from the
     * "Recommend activities" admin page.
     *
     * The placeholder is not included: it renders without a star (see PLACEHOLDER_ID), so nothing
     * can ever ask to favourite it.
     *
     * @return content_item[]
     */
    public static function get_all_content_items(): array {
        if (!template::is_configured()) {
            return [];
        }

        $items = [];
        foreach (self::get_live_presets() as $preset) {
            $items[] = self::make_content_item($preset, null);
        }
        return $items;
    }

    /**
     * The presets that have a validated backup behind them.
     *
     * Chooser visibility is decided by the live archive rather than by the status field, so a
     * preset can never be offered without a proven backup, and a re-bake in flight does not pull
     * a working preset out of the chooser.
     *
     * @return preset[]
     */
    protected static function get_live_presets(): array {
        $max = (int)get_config('mod_edpreset', 'maxpresets') ?: self::DEFAULT_MAX_PRESETS;

        $candidates = preset::get_records(
            ['templatecourseid' => template::get_courseid(), 'enabled' => 1],
            'sortorder',
            'ASC'
        );

        $live = [];
        foreach ($candidates as $preset) {
            if (!$preset->is_live()) {
                continue;
            }
            $live[] = $preset;
            if (count($live) >= $max) {
                break;
            }
        }
        return $live;
    }

    /**
     * Build one chooser item from a preset.
     *
     * @param preset $preset The preset.
     * @param stdClass|null $course The target course, or null when no course context is available.
     * @return content_item
     */
    protected static function make_content_item(preset $preset, ?stdClass $course): content_item {
        return new content_item(
            (int)$preset->get('id'),
            self::item_name($preset),
            new string_title($preset->get('title')),
            self::item_link($preset, $course),
            self::item_icon($preset),
            (string)$preset->get('help'),
            (int)$preset->get('archetype'),
            'mod_edpreset',
            $preset->get('purpose'),
            (bool)$preset->get('branded')
        );
    }

    /**
     * The item's internal name.
     *
     * The chooser uses this as a de-duplication key when toggling favourites
     * (activitychooser.js finds the item with `content_items.find(({name}) => name === internal)`
     * and selects the DOM node by `[data-internal="..."]`), so it must be unique per preset and
     * must not be the exemplar's module name.
     *
     * @param preset $preset The preset.
     * @return string
     */
    protected static function item_name(preset $preset): string {
        return 'edpreset_' . $preset->get('id');
    }

    /**
     * The item's link.
     *
     * Two constraints from the chooser JS: the link must already contain a "?", because
     * activitychooser.js string-appends "&section=...&beforemod=..." to it; and it must be unique
     * per preset, because the chooser maps items by componentname + link.
     *
     * It deliberately does not point at /course/mod.php, which whitelists the params it forwards
     * and would drop the preset id.
     *
     * @param preset $preset The preset.
     * @param stdClass|null $course The target course, or null when no course context is available.
     * @return moodle_url
     */
    protected static function item_link(preset $preset, ?stdClass $course): moodle_url {
        $params = ['preset' => $preset->get('id')];
        if ($course) {
            $params['course'] = $course->id;
            // The chooser link is minted server-side per user, so carrying a sesskey costs nothing
            // and closes CSRF on what is otherwise a state-changing GET.
            $params['sesskey'] = sesskey();
        }
        return new moodle_url('/mod/edpreset/copy.php', $params);
    }

    /**
     * The item's icon, rendered from the exemplar's module.
     *
     * @param preset $preset The preset.
     * @return string
     */
    protected static function item_icon(preset $preset): string {
        return $preset->get_icon_html();
    }
}
