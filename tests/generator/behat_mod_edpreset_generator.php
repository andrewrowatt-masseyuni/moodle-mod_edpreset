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
 * Behat data generator for the activity preset provider.
 *
 * Three things a feature file cannot express with core's own generators: naming a section (core has
 * no section generator at all in 4.5), recording the preset details that make an activity a preset,
 * and pointing the plugin at a template course by shortname.
 *
 * @package    mod_edpreset
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_edpreset_generator extends behat_generator_base {
    /**
     * The entities this generator can create.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'template courses' => [
                'singular' => 'template course',
                'datagenerator' => 'behat_template_course',
                'required' => ['course'],
                'switchids' => ['course' => 'courseid'],
            ],
            'sections' => [
                'singular' => 'section',
                'datagenerator' => 'behat_section',
                'required' => ['course', 'section'],
                'switchids' => ['course' => 'courseid'],
            ],
            'preset details' => [
                'singular' => 'preset detail',
                'datagenerator' => 'behat_preset_details',
                'required' => ['activity', 'presetname'],
                'switchids' => ['activity' => 'cmid'],
            ],
        ];
    }
}
