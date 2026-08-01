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

use core\persistent;

/**
 * The teacher-facing details a curator records against an exemplar activity.
 *
 * The presence of one of these rows is what makes an activity a preset. Nothing in the template
 * course is scanned, baked or offered without one, so this is the source of truth and the
 * edpreset_item row is a derived copy that every rebuild rewrites.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class meta extends persistent {
    /** @var string Table name. */
    public const TABLE = 'edpreset_meta';

    /** @var int Maximum length of the preset name, matching the column and the form's maxlength. */
    public const PRESETNAME_MAXLENGTH = 60;

    /**
     * Maximum length of the default activity name.
     *
     * One less than the column, and five less than the limit core enforces: cmactions::rename()
     * throws 'maximumchars' above 255 and runs the name through clean_param(PARAM_CLEANHTML)
     * first, which can lengthen it.
     *
     * @var int
     */
    public const DEFAULTNAME_MAXLENGTH = 250;

    /**
     * Define the properties of this persistent.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'cmid' => ['type' => PARAM_INT],
            'presetname' => ['type' => PARAM_TEXT],
            // Raw markdown as the curator typed it. PARAM_TEXT would mangle the syntax, so the
            // cleaning happens once, at bake time, via format_text(..., ['noclean' => false]).
            'description' => ['type' => PARAM_RAW],
            'tags' => ['type' => PARAM_TEXT, 'default' => ''],
            'defaultname' => ['type' => PARAM_TEXT, 'default' => ''],
        ];
    }

    /**
     * Whether an activity has preset details recorded against it.
     *
     * @param int $cmid The course module id.
     * @return bool
     */
    public static function exists_for_cm(int $cmid): bool {
        return self::record_exists_select('cmid = :cmid', ['cmid' => $cmid]);
    }

    /**
     * The details recorded against an activity, if any.
     *
     * @param int $cmid The course module id.
     * @return self|null
     */
    public static function get_for_cm(int $cmid): ?self {
        return self::get_record(['cmid' => $cmid]) ?: null;
    }

    /**
     * Remove the details recorded against an activity.
     *
     * XMLDB foreign keys are not enforced by the database, so nothing cascades these away when the
     * activity is deleted; the observers call this instead.
     *
     * @param int $cmid The course module id.
     */
    public static function delete_for_cm(int $cmid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['cmid' => $cmid]);
    }

    /**
     * Split a stored tag string into individual tags.
     *
     * @param string $tags The stored, comma-separated tags.
     * @return string[]
     */
    public static function split_tags(string $tags): array {
        return array_values(array_filter(array_map('trim', explode(',', $tags)), 'strlen'));
    }

    /**
     * Tidy a curator's comma-separated tag input into the stored form.
     *
     * Duplicates are matched case-insensitively but the first spelling wins, so the curator's
     * capitalisation is what teachers see.
     *
     * @param string $tags Raw input.
     * @return string Comma-separated, trimmed, de-duplicated, truncated to fit the column.
     */
    public static function normalise_tags(string $tags): string {
        $seen = [];
        foreach (self::split_tags($tags) as $tag) {
            $tag = preg_replace('/\s+/u', ' ', $tag);
            $key = \core_text::strtolower($tag);
            if (!isset($seen[$key])) {
                $seen[$key] = $tag;
            }
        }

        return \core_text::substr(implode(', ', $seen), 0, 255);
    }
}
