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
 * Rescan the whole template course.
 *
 * Only queues per-activity bakes; it does not run them, so this task stays quick however large the
 * template course is.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rebuild_presets extends adhoc_task {
    #[\Override]
    public function get_name(): string {
        return get_string('task:rebuildpresets', 'mod_edpreset');
    }

    #[\Override]
    public function execute(): void {
        $result = baker::rebuild();

        mtrace(sprintf(
            'mod_edpreset: %d exemplars scanned, %d bakes queued, %d presets removed.',
            $result['scanned'],
            $result['queued'],
            $result['removed']
        ));
    }
}
