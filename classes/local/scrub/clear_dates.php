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

namespace mod_edpreset\local\scrub;

/**
 * Zeroes a copied activity's dates so it does not arrive with last year's due date.
 *
 * Which fields count as dates is decided by a curated map, NOT by matching column names. A name
 * heuristic was tried and rejected on evidence: over the modules installed here it would have
 * zeroed assign's sendnotifications, sendlatenotifications and sendallocatemarker (booleans) and
 * quiz's timelimit (a duration), while still missing wiki's editbegin and lesson's available.
 * Zeroing a boolean silently changes what the copy does, and nothing downstream would notice:
 * the activity works, it just works differently from the exemplar.
 *
 * So unknown modules get no date clearing at all. Their dates carry over and the teacher fixes them
 * on the activity's settings page. That is the explicit trade: a stale date is a visible nuisance,
 * a silently flipped setting is not.
 *
 * Only the activity's own instance row is touched. The module's other tables - user and group
 * overrides, chiefly - hold user data, which a copy never carries.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class clear_dates implements rule {
    /**
     * Date fields that any module may carry, from the shared rating settings in
     * course/moodleform_mod.php. Applied only where the element actually exists.
     */
    protected const COMMON_FIELDS = ['assesstimestart', 'assesstimefinish'];

    /**
     * Per-module date fields.
     *
     * Derived from each module's own mod_form.php date_time_selector/date_selector elements, then
     * checked against the instance table columns. Add to this rather than loosening the matching.
     */
    protected const MODULE_FIELDS = [
        'assign' => ['allowsubmissionsfromdate', 'cutoffdate', 'duedate', 'gradingduedate'],
        'bigbluebuttonbn' => ['closingtime', 'openingtime'],
        'board' => ['postby'],
        'chat' => ['chattime'],
        'choice' => ['timeclose', 'timeopen'],
        'choicegroup' => ['timeclose', 'timeopen'],
        'data' => ['timeavailablefrom', 'timeavailableto', 'timeviewfrom', 'timeviewto'],
        'feedback' => ['timeclose', 'timeopen'],
        'forum' => ['cutoffdate', 'duedate'],
        'lesson' => ['available', 'deadline'],
        'publication' => ['allowsubmissionsfromdate', 'approvalfromdate', 'approvaltodate', 'duedate'],
        'questionnaire' => ['closedate', 'opendate'],
        'quiz' => ['timeclose', 'timeopen'],
        'scorm' => ['timeclose', 'timeopen'],
        'turnitintooltwo' => ['dtdue', 'dtpost', 'dtstart'],
        'wiki' => ['editbegin', 'editend'],
        'wordcloud' => ['timeclose', 'timeopen'],
        'workshop' => ['assessmentend', 'assessmentstart', 'submissionend', 'submissionstart'],
    ];

    #[\Override]
    public function get_name(): string {
        return 'clear_dates';
    }

    #[\Override]
    public function applies_to(string $modname): bool {
        return $this->get_fields($modname) !== [];
    }

    /**
     * The date fields to clear for a module: the curated set, plus any admin additions.
     *
     * @param string $modname The module name.
     * @return string[]
     */
    public function get_fields(string $modname): array {
        $fields = array_merge(
            self::MODULE_FIELDS[$modname] ?? [],
            self::get_configured_fields($modname)
        );

        // The rating window dates are added by the shared elements in course/moodleform_mod.php,
        // so any rated module has them. Include them only where the column genuinely exists -
        // otherwise every module, including ones that do not exist, would look scrubbable.
        foreach (self::COMMON_FIELDS as $common) {
            if (self::module_has_column($modname, $common)) {
                $fields[] = $common;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Whether a module's instance table has the given column.
     *
     * @param string $modname The module name.
     * @param string $column The column name.
     * @return bool
     */
    protected static function module_has_column(string $modname, string $column): bool {
        global $DB;

        if (!$DB->get_manager()->table_exists($modname)) {
            return false;
        }

        return array_key_exists($column, $DB->get_columns($modname));
    }

    /**
     * Extra date fields an admin has declared, for modules the curated map does not cover.
     *
     * Configured as one "modname: field, field" pair per line.
     *
     * @param string $modname The module name.
     * @return string[]
     */
    protected static function get_configured_fields(string $modname): array {
        $config = (string)get_config('mod_edpreset', 'datefields');
        if (trim($config) === '') {
            return [];
        }

        $fields = [];
        foreach (preg_split('/\R/', $config) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$mod, $list] = explode(':', $line, 2);
            if (trim($mod) !== $modname) {
                continue;
            }
            foreach (explode(',', $list) as $field) {
                $field = trim($field);
                if ($field !== '' && preg_match('/^[a-z0-9_]+$/', $field)) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }

    #[\Override]
    public function apply(string $modname, int $instanceid): array {
        global $DB;

        $instance = $DB->get_record($modname, ['id' => $instanceid]);
        if (!$instance) {
            return [];
        }

        $update = ['id' => $instanceid];
        foreach ($this->get_fields($modname) as $field) {
            // An admin-configured field may be misspelt or belong to another version of the module,
            // and update_record() would throw on a column that is not there.
            if (!property_exists($instance, $field)) {
                continue;
            }
            // Zero rather than null: these are integer columns, mostly NOT NULL, and 0 is Moodle's
            // own convention for "no date".
            if ((int)$instance->$field !== 0) {
                $update[$field] = 0;
            }
        }

        if (count($update) === 1) {
            return [];
        }

        $DB->update_record($modname, (object)$update);

        return array_keys(array_diff_key($update, ['id' => true]));
    }

    /**
     * Date-looking columns this rule does not cover, for reporting to an admin.
     *
     * Deliberately advisory rather than automatic: it is a prompt to extend the map by hand, not
     * something to act on, because the same pattern matching also hits booleans and durations.
     *
     * @param string $modname The module name.
     * @return string[]
     */
    public static function suggest_uncovered_fields(string $modname): array {
        global $DB;

        if (!$DB->get_manager()->table_exists($modname)) {
            return [];
        }

        $known = array_merge(self::MODULE_FIELDS[$modname] ?? [], self::COMMON_FIELDS);
        $ignore = ['id', 'course', 'timecreated', 'timemodified', 'timelimit'];

        $suggestions = [];
        foreach ($DB->get_columns($modname) as $name => $column) {
            if (!in_array($column->meta_type, ['I', 'R'], true)) {
                continue;
            }
            if (in_array($name, $known, true) || in_array($name, $ignore, true)) {
                continue;
            }
            if (preg_match('/date$|^time(open|close|start|finish|available|due|from|to)/', $name)) {
                $suggestions[] = $name;
            }
        }

        return $suggestions;
    }
}
