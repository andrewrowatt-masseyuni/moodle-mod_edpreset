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

namespace mod_edpreset;

use mod_edpreset\local\template;

/**
 * Tests for reading the section template marker off a raw section name.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_edpreset\local\template
 */
final class template_test extends \advanced_testcase {
    /**
     * Raw section names, and what each one should be read as.
     *
     * @return array[] Name, whether it is a template, whether restricted, the stripped name.
     */
    public static function marker_provider(): array {
        return [
            'plain section' => ['Week 1', false, false, 'Week 1'],
            'empty' => ['', false, false, ''],
            'template' => ['Weekly cycle [Template]', true, false, 'Weekly cycle'],
            'template, any case' => ['Weekly cycle [template]', true, false, 'Weekly cycle'],
            'template, no space before the marker' => ['Weekly cycle[Template]', true, false, 'Weekly cycle'],
            'template, trailing space' => ['Weekly cycle [Template]  ', true, false, 'Weekly cycle'],
            'restricted' => [
                'Whakapiri-Whakamārama-Whakamana learning model [Template,restricted]',
                true,
                true,
                'Whakapiri-Whakamārama-Whakamana learning model',
            ],
            'restricted, spaced and capitalised' => ['Induction [ Template , Restricted ]', true, true, 'Induction'],
            // A misspelt option must hide the template, not publish it.
            'unknown option fails closed' => ['Induction [Template,restriced]', true, true, 'Induction'],
            'an empty option list is not a restriction' => ['Induction [Template,]', true, false, 'Induction'],
            'marker not at the end' => ['Induction [Template] week 1', false, false, 'Induction [Template] week 1'],
            'not the marker word' => ['Induction [Templates]', false, false, 'Induction [Templates]'],
        ];
    }

    /**
     * The marker decides whether a section is a template, whether it is restricted, and its name.
     *
     * @dataProvider marker_provider
     * @param string $rawname The raw section name.
     * @param bool $istemplate Whether it should be read as a template.
     * @param bool $restricted Whether it should be read as restricted.
     * @param string $stripped The name with the marker removed.
     */
    public function test_marker(string $rawname, bool $istemplate, bool $restricted, string $stripped): void {
        $this->assertSame($istemplate, template::is_template_section_name($rawname));
        $this->assertSame($restricted, template::is_restricted_section_name($rawname));
        $this->assertSame($stripped, template::strip_template_marker($rawname));
    }

    /**
     * Restricting a template does not rename it.
     *
     * Courses record the stripped name, so a curator adding ",restricted" to an existing template
     * must leave every course that already uses it still matching it.
     */
    public function test_restricting_keeps_the_name(): void {
        $this->assertSame(
            template::strip_template_marker('Induction [Template]'),
            template::strip_template_marker('Induction [Template,restricted]')
        );
    }
}
