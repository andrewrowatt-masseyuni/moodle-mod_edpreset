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

/**
 * A copy progress display with a clock the test controls.
 *
 * @package    mod_edpreset
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * A copy progress display with a clock the test controls.
 *
 * core\progress\base throttles to one update per second and provides get_time() to be overridden
 * for exactly this reason, so a test does not have to spend real seconds to see two updates.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_copy_progress extends \mod_edpreset\local\copy_progress {
    /** @var int The fake current time, advanced on every read. */
    protected $now = 0;

    /**
     * A clock that moves one second per call.
     *
     * @return int
     */
    protected function get_time() {
        return $this->now++;
    }
}
