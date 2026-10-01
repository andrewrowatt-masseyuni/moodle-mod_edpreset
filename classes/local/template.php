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

use stdClass;

/**
 * Resolves the template course that exemplar activities are curated in.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template {
    /**
     * The marker that makes a section of the template course a section template.
     *
     * @var string
     */
    public const TEMPLATE_MARKER = '[Template]';

    /**
     * The marker option that restricts a section template to the courses already using it.
     *
     * Written inside the marker after a comma: "[Template,restricted]".
     *
     * @var string
     */
    public const OPTION_RESTRICTED = 'restricted';

    /**
     * Matches a template marker at the end of a raw section name, capturing any options after a comma.
     *
     * Case-insensitive and tolerant of spaces around the word and the comma, so "[Template]",
     * "[template]" and "[Template, Restricted]" are all markers.
     *
     * @var string
     */
    protected const MARKER_PATTERN = '/\[\s*template\s*(?:,([^\]]*))?\]$/iu';

    /**
     * Whether the plugin is switched on at all.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool)get_config('mod_edpreset', 'enabled');
    }

    /**
     * Whether a section name marks its section as a section template.
     *
     * Deliberately takes the RAW course_sections.name rather than get_section_name(): that runs the
     * name through format_string(), which a multilang or other filter may rewrite, and falls back to
     * "Topic 3" for an unnamed section - neither of which should decide whether a section is a
     * template.
     *
     * @param string|null $rawname The raw section name.
     * @return bool
     */
    public static function is_template_section_name(?string $rawname): bool {
        return self::parse_marker($rawname) !== null;
    }

    /**
     * Whether a section name marks its section as a restricted section template.
     *
     * Takes the raw name, for the reasons given on is_template_section_name().
     *
     * @param string|null $rawname The raw section name.
     * @return bool False for a section that is not a template at all.
     */
    public static function is_restricted_section_name(?string $rawname): bool {
        return self::parse_marker($rawname)['restricted'] ?? false;
    }

    /**
     * A section name with the template marker removed.
     *
     * Applied to the raw name, before format_string(), so that the marker cannot survive inside
     * whatever a filter produces. The options go with the marker, so "Induction [Template]" and
     * "Induction [Template,restricted]" strip to the same name - which is what lets a curator
     * restrict a template without releasing the courses that have already recorded it.
     *
     * @param string|null $rawname The raw section name.
     * @return string The name without the marker, trimmed. Unchanged if there was no marker.
     */
    public static function strip_template_marker(?string $rawname): string {
        return self::parse_marker($rawname)['name'] ?? trim((string)$rawname);
    }

    /**
     * Split a raw section name into the template name and the marker's options.
     *
     * An option this plugin does not recognise makes the template restricted rather than being
     * ignored. The only option there is narrows who may see a template, so the likely cause of an
     * unknown one is a mistyped "restricted" - and getting that wrong should hide a template, not
     * publish one the curator meant to keep back.
     *
     * @param string|null $rawname The raw section name.
     * @return array{name: string, restricted: bool}|null Null if the name carries no template marker.
     */
    protected static function parse_marker(?string $rawname): ?array {
        $name = trim((string)$rawname);
        if (!preg_match(self::MARKER_PATTERN, $name, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $options = [];
        foreach (explode(',', $matches[1][0] ?? '') as $option) {
            $option = \core_text::strtolower(trim($option));
            if ($option !== '') {
                $options[] = $option;
            }
        }

        return [
            // A byte offset from preg_match, hence substr() rather than core_text::substr().
            'name' => trim(substr($name, 0, $matches[0][1])),
            'restricted' => in_array(self::OPTION_RESTRICTED, $options, true)
                || array_diff($options, [self::OPTION_RESTRICTED]) !== [],
        ];
    }

    /**
     * The configured template course id, or 0 if none is set.
     *
     * @return int
     */
    public static function get_courseid(): int {
        return (int)get_config('mod_edpreset', 'templatecourseid');
    }

    /**
     * Every configured template course id.
     *
     * There is one today. Everything that asks "is this a template course?" goes through here or
     * through is_template_course(), so adding a second is a change to these two methods and the
     * admin setting, not to every caller.
     *
     * @return int[]
     */
    public static function get_courseids(): array {
        return array_values(array_filter([self::get_courseid()]));
    }

    /**
     * Whether a course is one of the template courses.
     *
     * @param int $courseid The course id.
     * @return bool
     */
    public static function is_template_course(int $courseid): bool {
        return $courseid > 0 && in_array($courseid, self::get_courseids(), true);
    }

    /**
     * The template course record, or null if unset or the course has since been deleted.
     *
     * Deliberately not get_course(): its second argument is $clone, not a strictness flag, so it
     * always uses MUST_EXIST and would throw. This runs on every activity chooser open in every
     * course, so an admin deleting the template course must degrade to "no presets", never to a
     * site-wide exception.
     *
     * @return stdClass|null
     */
    public static function get(): ?stdClass {
        global $DB;

        $courseid = self::get_courseid();
        if (!$courseid) {
            return null;
        }
        return $DB->get_record('course', ['id' => $courseid]) ?: null;
    }

    /**
     * Whether the plugin is enabled and pointed at a course that still exists.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return self::is_enabled() && self::get() !== null;
    }

    /**
     * The lowest section number scanned for exemplars.
     *
     * Section 0 is reserved for admin-facing instructions to whoever curates the template course,
     * so it is never turned into presets.
     *
     * @return int
     */
    public static function first_scanned_section(): int {
        return 1;
    }
}
