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

namespace mod_edpreset\admin;

use admin_setting_configtext;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Admin setting for the template course id.
 *
 * Core has no course-picker admin setting, and an autocomplete over every course is not viable on
 * a site of this size, so this takes a course id and echoes back the resolved course name so the
 * admin can see immediately whether they typed the right one.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_templatecourse extends admin_setting_configtext {
    /**
     * Reject anything that is not a usable template course.
     *
     * @param string $data The submitted value.
     * @return bool|string True if valid, or an error message.
     */
    public function validate($data) {
        global $DB;

        // An empty value is how the feature is left unconfigured.
        if (trim($data) === '' || (int)$data === 0) {
            return true;
        }

        $parent = parent::validate($data);
        if ($parent !== true) {
            return $parent;
        }

        $courseid = (int)$data;
        if ($courseid == SITEID) {
            return get_string('settings:templatecourseid_notsite', 'mod_edpreset');
        }
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            return get_string('settings:templatecourseid_notfound', 'mod_edpreset', $courseid);
        }

        return true;
    }

    /**
     * Render the setting, with the resolved course name appended so the id can be sanity-checked.
     *
     * @param mixed $data The current value.
     * @param string $query Search query.
     * @return string
     */
    public function output_html($data, $query = '') {
        global $DB, $OUTPUT;

        $html = parent::output_html($data, $query);

        $courseid = (int)$data;
        if (!$courseid) {
            return $html;
        }

        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname');
        if ($course) {
            $link = \html_writer::link(
                new \moodle_url('/course/view.php', ['id' => $course->id]),
                format_string($course->fullname) . ' (' . format_string($course->shortname) . ')'
            );
            $resolved = \html_writer::div($link, 'text-muted small mt-1');
        } else {
            $resolved = $OUTPUT->notification(
                get_string('settings:templatecourseid_notfound', 'mod_edpreset', $courseid),
                \core\output\notification::NOTIFY_WARNING
            );
        }

        // Slot the resolved name in just below the input, inside the setting's own markup.
        return str_replace('</div></div>', $resolved . '</div></div>', $html);
    }
}
