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

use mod_edpreset\local\scrub\clear_dates;
use mod_edpreset\local\scrub\rule;
use Throwable;

/**
 * Tidies a freshly copied activity of things that should not follow it out of the template course.
 *
 * It works on the copy, in the teacher's course, after the restore has finished - never on the
 * exemplar, and never on the backup on its way through. Rewriting the backup could break the
 * restore in ways only a test restore would catch; the copy is already in place, so nothing a rule
 * does to it can stop it arriving.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scrubber {
    /**
     * The rules to run, in order.
     *
     * @return rule[]
     */
    public static function get_rules(): array {
        return [
            new clear_dates(),
        ];
    }

    /**
     * Apply every rule to a freshly copied activity.
     *
     * Fail-soft by design, and each rule individually: a copy with stale dates is useful, and a copy
     * that is reported as failed when it is sitting in the teacher's course is worse than either.
     *
     * @param string $modname The copy's module name.
     * @param int $instanceid The copy's instance id.
     * @return array<string, string[]> What each rule changed, keyed by rule name. Rules that changed
     *     nothing are left out.
     */
    public static function scrub(string $modname, int $instanceid): array {
        $changes = [];

        foreach (self::get_rules() as $rule) {
            if (!$rule->applies_to($modname)) {
                continue;
            }
            try {
                $applied = $rule->apply($modname, $instanceid);
                if ($applied) {
                    $changes[$rule->get_name()] = $applied;
                }
            } catch (Throwable $e) {
                debugging(
                    'mod_edpreset scrub rule ' . $rule->get_name() . ' failed: ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }

        return $changes;
    }
}
