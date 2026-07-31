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

use core\task\scheduled_task;
use mod_edpreset\local\baker;
use mod_edpreset\local\template;

/**
 * Nightly safety net: rescan the template course and re-bake everything.
 *
 * Not redundant with the event observers. Editing quiz questions, book chapters or lesson pages
 * fires module-specific events, not course_module_updated, so those changes would otherwise never
 * reach a preset. A blanket nightly re-bake is simpler and more reliably correct than trying to
 * enumerate every content-changing event across every module, and it is cheap: Moodle's file
 * storage deduplicates by content hash, so an unchanged exemplar produces no new stored file.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_presets extends scheduled_task {

    #[\Override]
    public function get_name(): string {
        return get_string('task:reconcilepresets', 'mod_edpreset');
    }

    #[\Override]
    public function execute(): void {
        if (!template::is_configured()) {
            mtrace('mod_edpreset: no template course configured, nothing to reconcile.');
            return;
        }

        $result = baker::rebuild();

        mtrace(sprintf(
            'mod_edpreset: %d exemplars scanned, %d bakes queued, %d presets removed.',
            $result['scanned'],
            $result['queued'],
            $result['removed']
        ));
    }
}
