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

use moodle_url;
use section_info;
use stdClass;

/**
 * The "Apply template" item offered on a course section's action menu.
 *
 * theme_snap's per-section menu has no extension point, so the theme carries a two-line call to
 * this class and everything else - the label, the link, the icon and the access checks - lives
 * here. That keeps the edit to vendor code small enough to reapply after a theme upgrade, and it
 * means a site without the theme loses nothing but the menu item. See README.md, "Linking straight
 * to the section templates".
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class section_action {
    /**
     * @var bool[] Whether the menu item is offered at all, keyed by course id.
     *
     * The checks are course-level but the theme asks once per section, so a course page would
     * otherwise repeat them a dozen times over. Request-scoped: nothing here outlives the page.
     */
    protected static $offered = [];

    /**
     * The section action items to add to theme_snap's extra actions menu.
     *
     * The returned objects are shaped for theme_snap/course_action_section, which reads the public
     * properties of whatever it is given straight out of Mustache: title, url, class, ariapressed,
     * arialabel and isinmenu. ariapressed and arialabel are emitted as raw attribute strings, not
     * as values, exactly as theme_snap's own renderables build them.
     *
     * An array rather than a single item or null so the theme can merge it in unconditionally.
     *
     * @param stdClass $course The course being viewed.
     * @param section_info|stdClass $section The section the menu belongs to.
     * @return stdClass[] One item, or none if this user may not apply a template here.
     */
    public static function for_snap(stdClass $course, $section): array {
        if (!self::is_offered_in($course, (int)$section->section)) {
            return [];
        }

        $label = get_string('section:applytemplate', 'mod_edpreset');

        $item = new stdClass();
        $item->title = $label;
        // A section id resolves both the course and the section number on its own, and selects the
        // templates-only form of the chooser page. It is course_sections.id, not the section number.
        $item->url = new moodle_url('/mod/edpreset/chooser.php', [
            'sectionid' => (int)$section->id,
            'sesskey' => sesskey(),
        ]);
        $item->class = 'edpreset-applytemplate';
        // Not a toggle, so no pressed state - but the property must exist: the template prints it
        // on both the link and the icon div.
        $item->ariapressed = '';
        $item->arialabel = "aria-label='" . $label . "'";
        // The theme sets this itself on everything it moves into the menu. Set here too so the
        // object is complete on its own terms rather than only once the theme has been through it.
        $item->isinmenu = true;

        return [$item];
    }

    /**
     * Forget which courses offer the item.
     *
     * Only tests need this: they change the plugin's configuration and the current user between
     * cases within one request, which nothing does in production.
     */
    public static function reset_caches(): void {
        self::$offered = [];
    }

    /**
     * Whether to offer the item for a section of a course.
     *
     * The answer is cached against the course rather than the section because the only per-section
     * rule access::can_copy_into() applies is the format's maximum-sections bound, and a section
     * the theme is rendering a menu for is within it by definition.
     *
     * @param stdClass $course The course being viewed.
     * @param int $sectionnum The section number.
     * @return bool
     */
    protected static function is_offered_in(stdClass $course, int $sectionnum): bool {
        $courseid = (int)$course->id;

        if (!isset(self::$offered[$courseid])) {
            // Deliberately not conditional on a section template existing: that would cost a pass
            // over every preset and its archive on each course page, and the chooser page already
            // says when there are none to show.
            self::$offered[$courseid] = chooser::is_offered_in($course)
                && access::can_copy_into($course, $sectionnum);
        }

        return self::$offered[$courseid];
    }
}
