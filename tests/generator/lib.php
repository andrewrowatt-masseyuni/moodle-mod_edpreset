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

use mod_edpreset\meta;
use mod_edpreset\preset;

/**
 * Test generator for the activity preset provider.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_edpreset_generator extends testing_module_generator {
    /**
     * This module cannot be instantiated.
     *
     * @param array|stdClass|null $record Ignored.
     * @param array|null $options Ignored.
     * @return stdClass Never returns.
     * @throws coding_exception Always.
     */
    public function create_instance($record = null, ?array $options = null) {
        throw new coding_exception(
            'mod_edpreset is a content-item provider and is never added to a course. '
            . 'Use create_preset() or create_template_course() instead.'
        );
    }

    /**
     * Create a preset record.
     *
     * The record alone, with no exemplar behind it: enough for anything that only lists presets.
     * A test that copies one needs a real exemplar, which create_template_course() and scan()
     * provide.
     *
     * Released and shown in the activity chooser by default, so that a bare preset is offered
     * everywhere a preset can be. Pass 'status' for one the curator has not released, and
     * 'showinchooser' => 0 for one offered only on the preset chooser page.
     *
     * @param array $record Overrides for the preset fields.
     * @return preset
     */
    public function create_preset(array $record = []): preset {
        static $counter = 0;
        $counter++;

        $record += [
            'templatecourseid' => 0,
            'templatecmid' => -$counter,
            'modname' => 'assign',
            'instanceid' => $counter,
            'contextid' => \context_system::instance()->id,
            'title' => 'Test preset ' . $counter,
            'help' => 'Description of test preset ' . $counter,
            'category' => 'Test category',
            'sectionnum' => 1,
            'sortorder' => 1000 + $counter,
            'archetype' => MOD_ARCHETYPE_ASSIGNMENT,
            'purpose' => MOD_PURPOSE_ASSESSMENT,
            'status' => meta::STATUS_RELEASED,
            'showinchooser' => 1,
        ];

        $preset = new preset(0, (object)$record);
        $preset->create();

        return $preset;
    }

    /**
     * Record preset details against an activity.
     *
     * The presence of this row is what makes an activity a preset, and the settings form is the
     * only thing that writes one in production. create_module() does not run the form's post
     * actions, so tests that expect an exemplar to be scanned must call this.
     *
     * @param int $cmid The course module id.
     * @param array $fields Overrides for the metadata fields.
     * @return meta
     */
    public function create_metadata(int $cmid, array $fields = []): meta {
        static $counter = 0;
        $counter++;

        $fields += [
            'cmid' => $cmid,
            'presetname' => 'Test preset ' . $counter,
            // The shape the rich text editor writes, so what the scan renders here is what it
            // renders in production. Both formats are stated rather than left to the persistent's
            // default, because the format is what decides how the text is rendered and a test
            // overriding the text should be able to see which format it is overriding.
            'description' => '<p>Description of test preset ' . $counter . '</p>',
            'descriptionformat' => FORMAT_HTML,
            'tags' => '',
            // Deliberately empty by default: a non-empty value renames every copied activity, and
            // that should only happen in the tests that are about it.
            'defaultname' => '',
            'recommendedsection' => '',
            // Released rather than the draft a curator starts with: a fixture stands for a preset
            // that is being offered, and the tests about drafts say so.
            'status' => meta::STATUS_RELEASED,
            // Off, as on the form: only the tests about the activity chooser put a preset there.
            'showinchooser' => 0,
        ];

        $meta = new meta(0, (object)$fields);
        $meta->create();

        return $meta;
    }

    /**
     * Create a course laid out like a template course, and point the plugin at it.
     *
     * @param array $spec Section number => either a list of activity specs, or an array with
     *                    'activities' plus an optional 'name' and 'summary' for the section itself.
     *                    Each activity spec takes 'modname', an optional 'name', and an optional
     *                    'meta': metadata field overrides, or false for an activity with no preset
     *                    details at all. Section 0 is reserved for curator instructions and is
     *                    never scanned.
     * @return stdClass The course.
     */
    public function create_template_course(array $spec = []): stdClass {
        $generator = $this->datagenerator;

        $numsections = $spec ? max(array_keys($spec)) : 1;
        $course = $generator->create_course(['numsections' => $numsections, 'format' => 'topics']);

        foreach ($spec as $sectionnum => $section) {
            // A section is either a plain list of activities, or that list plus its own name and
            // summary - which is what a section template needs.
            $activities = $section['activities'] ?? $section;
            if (isset($section['name']) || isset($section['summary'])) {
                $this->set_section(
                    $course,
                    (int)$sectionnum,
                    (string)($section['name'] ?? ''),
                    (string)($section['summary'] ?? '')
                );
            }

            foreach ($activities as $activity) {
                $name = $activity['name'] ?? ucfirst($activity['modname']);
                $module = $generator->create_module($activity['modname'], [
                    'course' => $course->id,
                    'section' => $sectionnum,
                    'name' => $name,
                ]);

                $metafields = $activity['meta'] ?? [];
                if ($metafields === false) {
                    continue;
                }
                $this->create_metadata((int)$module->cmid, $metafields + ['presetname' => $name]);
            }
        }

        set_config('templatecourseid', $course->id, 'mod_edpreset');
        set_config('enabled', 1, 'mod_edpreset');

        return $course;
    }

    /**
     * Name a section and give it a summary.
     *
     * A section name ending in the template marker is what makes a section a section template, and
     * its summary becomes that template's description.
     *
     * @param stdClass $course The course.
     * @param int $sectionnum The section number.
     * @param string $name The section name, or '' to leave it unnamed.
     * @param string $summary The section summary, or '' for none.
     */
    public function set_section(stdClass $course, int $sectionnum, string $name, string $summary = ''): void {
        global $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        $sectioninfo = get_fast_modinfo($course)->get_section_info($sectionnum, MUST_EXIST);

        \core_courseformat\formatactions::section($course)->update($sectioninfo, [
            'name' => $name !== '' ? $name : null,
            'summary' => $summary,
            'summaryformat' => FORMAT_HTML,
        ]);
    }

    /**
     * Point the plugin at a course and switch it on.
     *
     * Behat entity: the following "mod_edpreset > template courses" exist, with a course column.
     * It exists because the config value is a course id, which a feature file cannot know.
     *
     * @param array $data Must contain courseid.
     */
    public function create_behat_template_course(array $data): void {
        set_config('templatecourseid', (int)$data['courseid'], 'mod_edpreset');
        set_config('enabled', 1, 'mod_edpreset');
    }

    /**
     * Name a section and give it a summary.
     *
     * Behat entity: the following "mod_edpreset > sections" exist, with course, section, name and
     * summary columns. Moodle 4.5 has no core generator for sections at all.
     *
     * @param array $data Must contain courseid and section; name and summary are optional.
     */
    public function create_behat_section(array $data): void {
        $course = get_course((int)$data['courseid']);

        $this->set_section(
            $course,
            (int)$data['section'],
            (string)($data['name'] ?? ''),
            (string)($data['summary'] ?? '')
        );
    }

    /**
     * Record preset details against an activity.
     *
     * Behat entity: the following "mod_edpreset > preset details" exist, with an activity column
     * holding the activity's idnumber.
     *
     * A description column is HTML, as the rich text editor writes it - a feature file giving a
     * plain sentence is giving valid HTML and gets it back unchanged. The format column is accepted
     * so a feature can pin a different one, but it is rarely worth setting.
     *
     * @param array $data Must contain cmid and presetname.
     */
    public function create_behat_preset_details(array $data): void {
        $cmid = (int)$data['cmid'];
        unset($data['cmid']);

        $allowed = [
            'presetname',
            'description',
            'descriptionformat',
            'tags',
            'defaultname',
            'recommendedsection',
            'status',
            'showinchooser',
        ];

        $this->create_metadata($cmid, array_filter(
            $data,
            fn($key) => in_array($key, $allowed, true),
            ARRAY_FILTER_USE_KEY
        ));
    }

    /**
     * Scan the template course, as the queued rebuild would.
     *
     * Nothing else is needed before a preset can be copied: the copy takes its own backup.
     */
    public function scan(): void {
        \mod_edpreset\local\baker::rebuild();
    }
}
