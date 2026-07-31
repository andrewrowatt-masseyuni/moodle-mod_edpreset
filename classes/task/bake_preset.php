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

namespace mod_edpreset\task;

use core\task\adhoc_task;
use mod_edpreset\local\baker;

/**
 * Produce the archive for one exemplar activity.
 *
 * Split from validation so each cron run stays short and a validation failure is isolated from the
 * backup that produced it.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bake_preset extends adhoc_task {

    #[\Override]
    public function get_name(): string {
        return get_string('task:bakepreset', 'mod_edpreset');
    }

    #[\Override]
    public function execute(): void {
        $data = (array)$this->get_custom_data();
        $cmid = (int)($data['cmid'] ?? 0);

        if (!$cmid) {
            mtrace('mod_edpreset: bake_preset queued without a course module id, skipping.');
            return;
        }

        // Serialised against the other bake and validation runs: they share the sandbox course,
        // and two concurrent validations would delete each other's activity mid-restore.
        $lock = \core\lock\lock_config::get_lock_factory('mod_edpreset')->get_lock('pipeline', 120);
        if (!$lock) {
            mtrace('mod_edpreset: could not obtain the pipeline lock, will retry.');
            throw new \moodle_exception('locktimeout', 'mod_edpreset');
        }

        try {
            $baked = baker::bake_one($cmid);
            mtrace('mod_edpreset: bake of course module ' . $cmid . ($baked ? ' staged.' : ' failed.'));
        } finally {
            $lock->release();
        }
    }
}
