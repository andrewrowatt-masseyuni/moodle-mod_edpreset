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
     * The presets offered in a given course.
     *
     * Access is already gated upstream: content_item_service requires moodle/course:manageactivities
     * and applies course_allowed_module() (i.e. mod/edpreset:addinstance) to everything we return.
     *
     * @param stdClass $course The course whose chooser is being built.
     * @param stdClass $user The user the chooser is being built for.
     * @return content_item[]
     */
    public static function get_content_items(stdClass $course, stdClass $user): array {
        if (!template::is_configured()) {
            return [];
        }

        // Never offer presets inside the template course itself: the curator is editing the
        // exemplars there, and copying one back into the same course only causes confusion.
        if ((int)$course->id === template::get_courseid()) {
            return [];
        }

        $items = [];
        foreach (self::get_live_presets() as $preset) {
            $items[] = self::make_content_item($preset, $course);
        }
        return $items;
    }

    /**
     * Every preset, without course context.
     *
     * This must stay consistent with get_content_items(): content_item_service::add_to_user_favourites()
     * looks the favourited id up with array_search() against this list, so a preset missing from
     * here would make favouriting return the wrong item entirely.
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
     * Rendered icon HTML is not stored on the preset because it embeds $CFG->wwwroot and the theme
     * revision, so it is regenerated per request.
     *
     * @param preset $preset The preset.
     * @return string
     */
    protected static function item_icon(preset $preset): string {
        global $OUTPUT;

        $modname = $preset->get('modname');
        // Modules without a monologo icon must not have the colour filter applied to them.
        $iconclass = \core_component::has_monologo_icon('mod', $modname) ? '' : 'nofilter';

        return $OUTPUT->pix_icon(
            $preset->get('icon'),
            '',
            $modname,
            ['class' => "mod_edpreset-icon activityicon $iconclass"]
        );
    }
}
