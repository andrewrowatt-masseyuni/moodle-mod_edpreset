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
 * Browse and add the preset activities that the standard activity chooser does not show.
 *
 * The standard chooser links here through a placeholder item, so section and beforemod arrive from
 * the chooser JS rather than from the link this plugin minted, exactly as they do for copy.php.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_edpreset\local\access;
use mod_edpreset\local\chooser;
use mod_edpreset\output\chooser_page;

$courseid = required_param('course', PARAM_INT);
$sectionnum = required_param('section', PARAM_INT);
$beforemod = optional_param('beforemod', 0, PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);

// The link is minted server-side per user, so requiring a sesskey costs nothing and closes CSRF
// on what leads directly to a state-changing action.
require_sesskey();

$coursecontext = context_course::instance($course->id);
access::require_can_copy_into($course, $sectionnum);

if (!chooser::is_offered_in($course)) {
    throw new moodle_exception('invalidpreset', 'mod_edpreset');
}

$beforemod = access::clean_beforemod($course, $beforemod);

$PAGE->set_url(new moodle_url('/mod/edpreset/chooser.php', [
    'course' => $courseid,
    'section' => $sectionnum,
]));
$PAGE->set_course($course);
$PAGE->set_context($coursecontext);
$PAGE->set_pagelayout('incourse');
$PAGE->add_body_class('limitedwidth');
$PAGE->set_title(get_string('chooser:pagetitle', 'mod_edpreset'));
$PAGE->set_heading(format_string($course->fullname));

$renderable = new chooser_page($course, $sectionnum, $beforemod);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('chooser:pagetitle', 'mod_edpreset'));
echo $OUTPUT->render_from_template('mod_edpreset/chooser_page', $renderable->export_for_template($OUTPUT));
echo $OUTPUT->footer();
