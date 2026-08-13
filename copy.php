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
 * Copy one or more preset activities into a course, then return to the course section.
 *
 * The only way presets get into a course. One preset, ten, or a whole section template take the same
 * route: the restores run sequentially in this request and the teacher is sent back to the section
 * once they are done.
 *
 * Three things reach here. The standard activity chooser follows a link carrying a single preset id,
 * appending section, beforemod and sr to it from its own JavaScript. The preset chooser page posts a
 * form carrying however many the teacher selected. A section template card carries a template rather
 * than a preset list, and - when the teacher was offered the reorder dialogue - the order they chose.
 *
 * Note that this deliberately does not end on the new activity's settings form the way every other
 * activity chooser item does. A preset arrives already configured - that is the point of it - and
 * one destination for one preset and for ten is worth more than matching core here. Do not
 * reintroduce the modedit redirect for the single case without revisiting that.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_edpreset\preset;
use mod_edpreset\local\access;
use mod_edpreset\local\activity_copier;
use mod_edpreset\local\chooser;
use mod_edpreset\local\coursedefault;
use mod_edpreset\local\section_template;

// Either a preset list or a template, never both. Both are optional so that one handler can read
// either; the check that exactly one arrived is below.
$presetids = optional_param('presets', '', PARAM_SEQUENCE);
$templatesection = optional_param('template', 0, PARAM_INT);
$order = optional_param('order', '', PARAM_TEXT);
$courseid = required_param('course', PARAM_INT);
$sectionnum = required_param('section', PARAM_INT);
$beforemod = optional_param('beforemod', 0, PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);

// The link is minted server-side per user, so requiring a sesskey costs nothing and closes CSRF
// on what is otherwise a state-changing GET.
require_sesskey();

$coursecontext = context_course::instance($course->id);
access::require_can_copy_into($course, $sectionnum);

// Everything is loaded and checked before any of it is restored, so a bad id fails with nothing yet
// committed rather than half way through a batch.
$template = null;
$presets = [];

if ($templatesection) {
    $template = section_template::for_section($templatesection);
    if (!$template) {
        throw new moodle_exception('invalidpreset', 'mod_edpreset');
    }
    // A course keeps to one template. The chooser already shows the others as unchoosable, but a
    // disabled button is a courtesy rather than a control - this is where it is actually enforced.
    if (!coursedefault::allows((int)$course->id, $template->get_name())) {
        throw new moodle_exception('templatelocked', 'mod_edpreset');
    }

    $presets = $template->get_members();
    // A template is authored rather than selected, so this is a curator having built something too
    // large rather than a teacher having over-selected - but the restores cost the same either way.
    if (count($presets) > access::MAX_PRESETS) {
        throw new moodle_exception('toomanypresets', 'mod_edpreset', '', access::MAX_PRESETS);
    }
} else {
    foreach (access::clean_presets($presetids) as $presetid) {
        $preset = preset::get_record(['id' => $presetid, 'enabled' => 1]);
        if (!$preset || !$preset->is_live()) {
            throw new moodle_exception('invalidpreset', 'mod_edpreset');
        }
        $presets[] = $preset;
    }
}

$beforemod = access::clean_beforemod($course, $beforemod);
$order = access::clean_order($order);

if ($template) {
    $heading = get_string('creatingtemplate', 'mod_edpreset', $template->get_name());
} else if (count($presets) === 1) {
    $heading = get_string('creatingactivity', 'mod_edpreset', $presets[0]->get('title'));
} else {
    $heading = get_string('creatingactivities', 'mod_edpreset', count($presets));
}

$urlparams = ['course' => $courseid, 'section' => $sectionnum];
if ($template) {
    $urlparams['template'] = $templatesection;
} else {
    $urlparams['presets'] = implode(',', array_map(fn($preset) => $preset->get('id'), $presets));
}
$PAGE->set_url(new moodle_url('/mod/edpreset/copy.php', $urlparams));
$PAGE->set_course($course);
$PAGE->set_context($coursecontext);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title($heading);
$PAGE->set_heading(format_string($course->fullname));

// A batch is several restores back to back, and the default limit is not generous enough for one
// preset carrying a question bank or a content package, let alone several.
\core_php_time_limit::raise();
raise_memory_limit(MEMORY_EXTRA);

// Restoring is not cheap. A user who reloads or double-clicks must not start a second one. Taken
// once around the whole batch rather than per preset: the restores are sequential anyway, and
// releasing between them would let a second tab interleave with this one.
$lockfactory = \core\lock\lock_config::get_lock_factory('mod_edpreset');
$lock = $lockfactory->get_lock('copy_' . $USER->id, 0);
if (!$lock) {
    throw new moodle_exception('copyinprogress', 'mod_edpreset');
}

// Prints nothing - not even the page header - for the first few seconds, so a fast copy stays
// invisible and the redirect below can still be a real 303. A slower one prints the header and the
// standard restore progress bar. See copy_progress for why the header cannot be printed up front.
//
// Deliberately not wrapped in start_progress(): each restore opens its own top-level sections, and
// core\progress\base throws "parent progress would exceed max" on the second one if a parent
// section is open around them.
$progress = new \mod_edpreset\local\copy_progress(
    $heading,
    get_string('copyingactivity', 'mod_edpreset')
);
$progress->set_display_names();

try {
    if ($template) {
        ['added' => $added, 'failed' => $failed] =
            activity_copier::copy_template($template, $course, $sectionnum, $order, $beforemod, $progress);
    } else {
        ['added' => $added, 'failed' => $failed] =
            activity_copier::copy_many($presets, $course, $sectionnum, $beforemod, $progress);
    }
} finally {
    $lock->release();
}

// Only once something actually landed: a template that failed outright did not become this course's
// default. Deliberately after the lock is released - it touches the course rather than the section,
// and it must not fail the add, so it swallows its own errors.
if ($template && $added) {
    coursedefault::set((int)$course->id, $template->get_name());
}

// Queued rather than printed. On the fast path they survive the redirect in the session; on the
// slow path the header has already rendered and drained the queue, so they surface on the course
// page instead - which is where the teacher is about to be looking either way.
if ($added) {
    $names = array_map(fn($cm) => format_string($cm->name, true, ['context' => $coursecontext]), $added);
    \core\notification::success(get_string('activitiesadded', 'mod_edpreset', implode(', ', $names)));
}
if ($failed) {
    \core\notification::error(get_string('activitiesnotadded', 'mod_edpreset', implode(', ', $failed)));
}

$target = chooser::return_url($course, $sectionnum);

// The common case: nothing has been sent, so this is a genuine 303 and the teacher never sees an
// intermediate page at all.
if (!$progress->has_printed_header()) {
    redirect($target);
}

// A slow copy has already committed a progress bar to the browser, and no Location header can
// follow that. Finish the page with a scripted redirect and a link for anyone without JavaScript,
// rather than calling redirect() and having it report the output we chose to emit.
// js_amd_inline() rather than js_function_call(), which core's own redirect_message() still uses
// but which is deprecated.
$PAGE->requires->js_amd_inline(
    'document.location.replace(' . json_encode($target->out(false)) . ');'
);
echo $OUTPUT->continue_button($target);
echo $OUTPUT->footer();
