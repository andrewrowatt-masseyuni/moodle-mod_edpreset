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
 * A progress reporter that breaks a restore part way through.
 *
 * @package    mod_edpreset
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * A progress reporter that breaks a restore part way through.
 *
 * Every restore task and structure step opens a progress section, so this is called at each step
 * boundary. It throws as soon as the watched course holds a module - right after the restore has
 * created the course module, and before it has built the activity behind it - which is the worst
 * point for a real failure to happen.
 *
 * A named class rather than an anonymous one: the backup and restore controllers serialise
 * themselves, reporter included, into the backup_controllers table.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class failing_restore_progress extends \core\progress\none {
    /** @var int The course to watch. */
    protected $courseid;

    /**
     * Constructor.
     *
     * @param int $courseid The course to watch.
     */
    public function __construct(int $courseid) {
        $this->courseid = $courseid;
    }

    /**
     * Fail once the restore has put a module into the watched course.
     */
    public function update_progress() {
        global $DB;

        if ($DB->record_exists('course_modules', ['course' => $this->courseid])) {
            throw new \moodle_exception('error');
        }
    }
}
