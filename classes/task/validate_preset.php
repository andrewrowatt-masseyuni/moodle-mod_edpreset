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
use mod_edpreset\local\validator;
use mod_edpreset\preset;

/**
 * Test-restore a staged archive and publish it if it works.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class validate_preset extends adhoc_task {

    #[\Override]
    public function get_name(): string {
        return get_string('task:validatepreset', 'mod_edpreset');
    }

    #[\Override]
    public function execute(): void {
        $data = (array)$this->get_custom_data();
        $presetid = (int)($data['presetid'] ?? 0);

        $preset = $presetid ? preset::get_record(['id' => $presetid]) : false;
        if (!$preset) {
            mtrace('mod_edpreset: preset ' . $presetid . ' no longer exists, nothing to validate.');
            return;
        }

        $lock = \core\lock\lock_config::get_lock_factory('mod_edpreset')->get_lock('pipeline', 120);
        if (!$lock) {
            mtrace('mod_edpreset: could not obtain the pipeline lock, will retry.');
            throw new \moodle_exception('locktimeout', 'mod_edpreset');
        }

        try {
            $published = validator::process($preset);
            mtrace('mod_edpreset: preset ' . $presetid . ($published ? ' published.' : ' failed validation.'));
        } finally {
            $lock->release();
        }
    }
}
