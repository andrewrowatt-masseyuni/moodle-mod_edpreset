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
 * Copy a preset activity into a course, then open its settings form.
 *
 * This is where the activity chooser sends the teacher. The chooser appends section, beforemod and
 * sr to the link it was given, so every parameter below except 'preset' and 'course' arrives from
 * the chooser JS rather than from the link this plugin minted.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_edpreset\preset;
use mod_edpreset\local\access;
use mod_edpreset\local\activity_copier;

$presetid = required_param('preset', PARAM_INT);
$courseid = required_param('course', PARAM_INT);
$sectionnum = required_param('section', PARAM_INT);
$beforemod = optional_param('beforemod', 0, PARAM_INT);
$sectionreturn = optional_param('sr', null, PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);

// The link is minted server-side per user, so requiring a sesskey costs nothing and closes CSRF
// on what is otherwise a state-changing GET.
require_sesskey();

$coursecontext = context_course::instance($course->id);
access::require_can_copy_into($course, $sectionnum);

$preset = preset::get_record(['id' => $presetid, 'enabled' => 1]);
if (!$preset || !$preset->is_live()) {
    throw new moodle_exception('invalidpreset', 'mod_edpreset');
}

$beforemod = access::clean_beforemod($course, $beforemod);

if ($sectionreturn !== null && $sectionreturn < 0) {
    $sectionreturn = null;
}

$PAGE->set_url(new moodle_url('/mod/edpreset/copy.php', [
    'preset' => $presetid,
    'course' => $courseid,
    'section' => $sectionnum,
]));
$PAGE->set_course($course);
$PAGE->set_context($coursecontext);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('creatingactivity', 'mod_edpreset', $preset->get('title')));
$PAGE->set_heading(format_string($course->fullname));

// Restoring is not cheap. A user who reloads or double-clicks must not start a second one.
$lockfactory = \core\lock\lock_config::get_lock_factory('mod_edpreset');
$lock = $lockfactory->get_lock('copy_' . $USER->id, 0);
if (!$lock) {
    throw new moodle_exception('copyinprogress', 'mod_edpreset');
}

try {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('creatingactivity', 'mod_edpreset', $preset->get('title')));

    // Emits nothing for the first few seconds, so a fast copy stays invisible and the redirect
    // below is a real 303. A slow one shows the standard restore progress bar instead.
    $progress = new \core\progress\display_if_slow(get_string('copyingactivity', 'mod_edpreset'));
    $progress->set_display_names();

    $cm = activity_copier::copy($preset, $course, $sectionnum, $beforemod, $progress);
} finally {
    $lock->release();
}

// The activity exists before the teacher has seen its settings form, so pressing Cancel there
// leaves it behind. That is not interceptable - is_cancelled() is evaluated before any plugin code
// runs - so say so plainly instead. The notification survives the redirect below.
\core\notification::info(get_string('activityaddednotice', 'mod_edpreset', $preset->get('title')));

$params = ['update' => $cm->id, 'return' => 0];
if ($sectionreturn !== null) {
    $params['sr'] = $sectionreturn;
}
redirect(new moodle_url('/course/modedit.php', $params));
