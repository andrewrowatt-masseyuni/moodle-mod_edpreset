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

namespace mod_edpreset\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_edpreset\local\access;
use mod_edpreset\local\activity_copier;
use mod_edpreset\local\section_template;
use moodle_exception;

/**
 * The two lists the section template reorder dialogue arranges.
 *
 * Read fresh on opening the dialogue rather than shipped with the page: the page may have been open
 * for a while, and what matters is the section as it is now.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_template_items extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course the template will be added to'),
            'sectionnum' => new external_value(PARAM_INT, 'The section the template will be added to'),
            'template' => new external_value(PARAM_INT, 'The template course section number identifying the template'),
        ]);
    }

    /**
     * Fetch the lists.
     *
     * @param int $courseid The target course.
     * @param int $sectionnum The target section number.
     * @param int $template The template's section number in the template course.
     * @return array
     */
    public static function execute(int $courseid, int $sectionnum, int $template): array {
        global $DB;

        [
            'courseid' => $courseid,
            'sectionnum' => $sectionnum,
            'template' => $template,
        ] = self::validate_parameters(
            self::execute_parameters(),
            ['courseid' => $courseid, 'sectionnum' => $sectionnum, 'template' => $template]
        );

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        self::validate_context(\context_course::instance($course->id));

        // The same gate the page and copy.php apply, so the dialogue cannot show a teacher a section
        // they would not have been allowed to add to anyway.
        access::require_can_copy_into($course, $sectionnum);

        $sectiontemplate = section_template::for_section($template);
        if (!$sectiontemplate) {
            throw new moodle_exception('invalidpreset', 'mod_edpreset');
        }

        return [
            'templateitems' => self::export_template_items($sectiontemplate),
            'courseitems' => self::export_course_items($course, $sectionnum),
        ];
    }

    /**
     * The activities the template will add.
     *
     * Named as they will be once copied - the preset's default name where the curator set one -
     * rather than as the exemplar happens to be called, so that the dialogue matches the result.
     *
     * @param section_template $template The template.
     * @return array[]
     */
    protected static function export_template_items(section_template $template): array {
        $items = [];
        foreach ($template->get_members() as $member) {
            $defaultname = trim((string)$member->get('defaultname'));

            $items[] = [
                'token' => 'p' . $member->get('id'),
                'name' => $defaultname !== '' ? $defaultname : $member->get('title'),
                'modfullname' => get_string('modulename', 'mod_' . $member->get('modname')),
                'icon' => $member->get_icon_html(),
                // The icon container needs all three or the icon renders without its colour.
                'modname' => $member->get('modname'),
                'purpose' => $member->get('purpose'),
                'branded' => (bool)$member->get('branded'),
                'istemplate' => true,
                // Unused - a template activity has no drag handle - but kept so both lists have the
                // same shape.
                'movetitle' => get_string('reorder:move', 'mod_edpreset', $member->get('title')),
            ];
        }

        return $items;
    }

    /**
     * The activities already in the target section.
     *
     * Teacher notes are left out on purpose: a note is chrome belonging to the activity below it,
     * and mod_edpreset re-attaches each one when it writes the final order, so offering them here
     * would only let a teacher strand one.
     *
     * @param \stdClass $course The target course.
     * @param int $sectionnum The target section number.
     * @return array[]
     */
    protected static function export_course_items(\stdClass $course, int $sectionnum): array {
        $modinfo = get_fast_modinfo($course);
        $context = \context_course::instance($course->id);

        $items = [];
        foreach ($modinfo->sections[$sectionnum] ?? [] as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            if ($cm->deletioninprogress || $cm->modname === activity_copier::NOTE_MODNAME) {
                continue;
            }

            $name = format_string($cm->name, true, ['context' => $context]);

            $items[] = [
                'token' => 'c' . $cm->id,
                'name' => $name,
                'modfullname' => get_string('modulename', 'mod_' . $cm->modname),
                'icon' => self::course_item_icon($cm),
                // Read the same way baker::upsert() reads them for a preset, so an activity already
                // in the course and one about to be copied in look identical in the dialogue.
                'modname' => $cm->modname,
                'purpose' => (string)plugin_supports('mod', $cm->modname, FEATURE_MOD_PURPOSE, MOD_PURPOSE_OTHER),
                'branded' => (bool)component_callback('mod_' . $cm->modname, 'is_branded', [], false),
                'istemplate' => false,
                'movetitle' => get_string('reorder:move', 'mod_edpreset', $name),
            ];
        }

        return $items;
    }

    /**
     * An existing activity's icon, rendered the same way a preset's is.
     *
     * @param \cm_info $cm The course module.
     * @return string
     */
    protected static function course_item_icon(\cm_info $cm): string {
        global $OUTPUT;

        // Modules without a monologo icon must not have the colour filter applied to them.
        $iconclass = \core_component::has_monologo_icon('mod', $cm->modname) ? '' : 'nofilter';

        return $OUTPUT->pix_icon(
            'monologo',
            '',
            $cm->modname,
            ['class' => "mod_edpreset-icon activityicon $iconclass"]
        );
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'templateitems' => new external_multiple_structure(self::item_structure(), 'What the template will add'),
            'courseitems' => new external_multiple_structure(self::item_structure(), 'What the section already holds'),
        ]);
    }

    /**
     * The shape of one draggable item.
     *
     * @return external_single_structure
     */
    protected static function item_structure(): external_single_structure {
        return new external_single_structure([
            'token' => new external_value(PARAM_ALPHANUM, 'p<presetid> or c<cmid>, the id used to post the order back'),
            'name' => new external_value(PARAM_TEXT, 'The name the activity will have'),
            'modfullname' => new external_value(PARAM_TEXT, 'The human-readable activity type'),
            'icon' => new external_value(PARAM_RAW, 'Pre-rendered icon HTML'),
            'modname' => new external_value(PARAM_PLUGIN, 'The activity module, for the icon container'),
            'purpose' => new external_value(PARAM_ALPHA, 'MOD_PURPOSE_* of the activity, which colours the icon'),
            'branded' => new external_value(PARAM_BOOL, 'Whether the icon is branded and must not be recoloured'),
            'istemplate' => new external_value(PARAM_BOOL, 'Whether this activity comes from the template'),
            'movetitle' => new external_value(PARAM_TEXT, 'Label for the drag handle; unused for a template activity'),
        ]);
    }
}
