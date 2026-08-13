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

namespace mod_edpreset\local;

use core_course\customfield\course_handler;
use core_customfield\category_controller;
use core_customfield\field_controller;
use Throwable;

/**
 * The course custom field recording which section template a course was built from.
 *
 * Created by db/install.php rather than by an upgrade step, which is a deliberate choice and has a
 * consequence: a site that installed this plugin before the field existed will never get it. So
 * every method here treats a missing field as normal and does nothing, rather than as an error.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coursedefault {
    /** @var string Shortname of the field, and therefore the customfield_ form element suffix. */
    public const FIELD_SHORTNAME = 'defaultsectiontemplate';

    /**
     * Record the template a course was most recently built from.
     *
     * Best effort. A course is not worth failing an add over: by the time this runs the activities
     * are already in the section, so throwing here would report a failure that did not happen.
     *
     * @param int $courseid The course.
     * @param string $templatename The template's name, as teachers see it.
     */
    public static function set(int $courseid, string $templatename): void {
        if (!self::get_field()) {
            return;
        }

        try {
            course_handler::create()->instance_form_save((object)[
                'id' => $courseid,
                'customfield_' . self::FIELD_SHORTNAME => $templatename,
            ]);
        } catch (Throwable $e) {
            debugging(
                'mod_edpreset: could not record the default section template for course '
                    . $courseid . ': ' . $e->getMessage(),
                DEBUG_NORMAL
            );
        }
    }

    /**
     * The template a course was most recently built from.
     *
     * @param int $courseid The course.
     * @return string The stored name, or '' if none is recorded or the field does not exist.
     */
    public static function get(int $courseid): string {
        if (!self::get_field()) {
            return '';
        }

        // The $returnall flag is not optional here: the field is deliberately visible to nobody, and
        // without it get_instance_data() filters the field out through get_visible_fields().
        foreach (course_handler::create()->get_instance_data($courseid, true) as $data) {
            if ($data->get_field()->get('shortname') === self::FIELD_SHORTNAME) {
                return (string)$data->get_value();
            }
        }

        return '';
    }

    /**
     * Whether a course may still be given a particular section template.
     *
     * By default a course settles on one template: once one has been used the others are shown but
     * cannot be chosen, because a section built from two different templates is neither. Clearing
     * the custom field is what releases the course, which is why the teacher is pointed at an
     * administrator rather than offered a way out.
     *
     * Two settings qualify that. preventmixing turns the whole rule off. ignoreinvalidtemplate
     * covers the case the rule reads worst in: a course whose recorded template has since been
     * renamed or deleted would otherwise be locked out of every template, including the one it is
     * effectively already using, with nothing on screen to explain why.
     *
     * @param int $courseid The course.
     * @param string $templatename The template being considered.
     * @return bool
     */
    public static function allows(int $courseid, string $templatename): bool {
        return self::allows_for_record(self::get($courseid), $templatename);
    }

    /**
     * The rule itself, applied to a record that has already been read.
     *
     * Exists so that a page weighing several templates at once reads the course's record a single
     * time instead of once per card, without restating the rule to do it. Everything that decides
     * whether a template may be used goes through here.
     *
     * @param string $recorded The template already used by the course, or '' if none.
     * @param string $templatename The template being considered.
     * @return bool
     */
    public static function allows_for_record(string $recorded, string $templatename): bool {
        if (self::mixing_allowed()) {
            return true;
        }

        if ($recorded === '' || $recorded === $templatename) {
            return true;
        }

        // Something else is recorded. Whether that still counts is the second setting's business.
        return self::setting_enabled('ignoreinvalidtemplate') && !section_template::name_exists($recorded);
    }

    /**
     * Whether the site is allowing a course to use more than one section template.
     *
     * @return bool
     */
    public static function mixing_allowed(): bool {
        return !self::setting_enabled('preventmixing');
    }

    /**
     * Read one of the two checkbox settings, honouring its declared default.
     *
     * get_config() returns false for a setting that has never been written, which is not the same as
     * one that has been switched off - and both of these default to on. Treating "never saved" as
     * off would quietly drop the restriction on any site whose admin page has not been visited.
     *
     * @param string $name The setting name.
     * @return bool
     */
    protected static function setting_enabled(string $name): bool {
        $value = get_config('mod_edpreset', $name);

        return $value === false ? true : (bool)$value;
    }

    /**
     * The field, if this site has it.
     *
     * @return field_controller|null
     */
    public static function get_field(): ?field_controller {
        foreach (course_handler::create()->get_fields() as $field) {
            if ($field->get('shortname') === self::FIELD_SHORTNAME) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Create the field, unless this site already has it.
     *
     * Called from db/install.php. Idempotent, because shortnames are unique per handler and a second
     * attempt would fail rather than no-op.
     *
     * @return field_controller|null The field, or null if it could not be created.
     */
    public static function install(): ?field_controller {
        $existing = self::get_field();
        if ($existing) {
            return $existing;
        }

        $handler = course_handler::create();
        $category = category_controller::create(self::resolve_categoryid($handler));

        $field = field_controller::create(0, (object)['type' => 'text'], $category);
        $handler->save_field_configuration($field, (object)[
            'name' => get_string('customfield:defaultsectiontemplate', 'mod_edpreset'),
            'shortname' => self::FIELD_SHORTNAME,
            'type' => 'text',
            'description' => get_string('customfield:defaultsectiontemplate_desc', 'mod_edpreset'),
            'descriptionformat' => FORMAT_HTML,
            'configdata' => [
                'required' => 0,
                'uniquevalues' => 0,
                // Not locked: locking would make instance_form_save() silently no-op for anyone
                // without moodle/course:changelockedcustomfields, which is most teachers.
                'locked' => 0,
                // Nobody sees this on a course listing or a course page. It is a record for
                // administrators, read through the API rather than the interface.
                'visibility' => course_handler::NOTVISIBLE,
                'defaultvalue' => '',
                'displaysize' => 50,
                // The column's own limit. save_field_configuration() does not run the field type's
                // validation, so out-of-range values here would only surface when an administrator
                // opened the field for editing.
                'maxlength' => 1333,
                'ispassword' => 0,
                'link' => '',
                'linktarget' => '',
            ],
        ]);

        // Re-read rather than trusting the controller we just built: save_field_configuration()
        // clears the configuration cache, and the stored row is what every later call will see.
        return self::get_field();
    }

    /**
     * The custom field category the field belongs in, created if this site has not got it.
     *
     * @param course_handler $handler The course custom field handler.
     * @return int The category id.
     */
    protected static function resolve_categoryid(course_handler $handler): int {
        $name = get_string('customfield:category', 'mod_edpreset');

        foreach ($handler->get_categories_with_fields() as $category) {
            if ($category->get('name') === $name) {
                return (int)$category->get('id');
            }
        }

        return $handler->create_category($name);
    }
}
