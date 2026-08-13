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
 * Admin settings for the activity preset provider.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Added before $settings, which core appends to 'modsettings' after this file is included, so the
// management page appears above the settings page rather than below it.
$ADMIN->add('modsettings', new admin_externalpage(
    'modsettingedpresetmanage',
    get_string('managepresets', 'mod_edpreset'),
    new moodle_url('/mod/edpreset/manage.php'),
    'moodle/site:config'
));

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configcheckbox(
        'mod_edpreset/enabled',
        get_string('settings:enabled', 'mod_edpreset'),
        get_string('settings:enabled_desc', 'mod_edpreset'),
        0
    ));

    $settings->add(new \mod_edpreset\admin\setting_templatecourse(
        'mod_edpreset/templatecourseid',
        get_string('settings:templatecourseid', 'mod_edpreset'),
        get_string('settings:templatecourseid_desc', 'mod_edpreset'),
        '',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_edpreset/maxpresets',
        get_string('settings:maxpresets', 'mod_edpreset'),
        get_string('settings:maxpresets_desc', 'mod_edpreset'),
        100,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_edpreset/maxbackupsize',
        get_string('settings:maxbackupsize', 'mod_edpreset'),
        get_string('settings:maxbackupsize_desc', 'mod_edpreset'),
        104857600,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtextarea(
        'mod_edpreset/datefields',
        get_string('settings:datefields', 'mod_edpreset'),
        get_string('settings:datefields_desc', 'mod_edpreset'),
        '',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_heading(
        'mod_edpreset/templateheading',
        get_string('settings:templateheading', 'mod_edpreset'),
        get_string('settings:templateheading_desc', 'mod_edpreset')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_edpreset/preventmixing',
        get_string('settings:preventmixing', 'mod_edpreset'),
        get_string('settings:preventmixing_desc', 'mod_edpreset'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_edpreset/ignoreinvalidtemplate',
        get_string('settings:ignoreinvalidtemplate', 'mod_edpreset'),
        get_string('settings:ignoreinvalidtemplate_desc', 'mod_edpreset'),
        1
    ));

    // It only qualifies the lock, so it means nothing with the lock switched off.
    $settings->hide_if('mod_edpreset/ignoreinvalidtemplate', 'mod_edpreset/preventmixing', 'notchecked');

    $settings->add(new admin_setting_heading(
        'mod_edpreset/sandboxheading',
        get_string('settings:sandboxheading', 'mod_edpreset'),
        get_string('settings:sandboxheading_desc', 'mod_edpreset')
    ));

    $settings->add(new admin_setting_configtext(
        'mod_edpreset/sandboxshortname',
        get_string('settings:sandboxshortname', 'mod_edpreset'),
        get_string('settings:sandboxshortname_desc', 'mod_edpreset'),
        \mod_edpreset\local\sandbox::DEFAULT_SHORTNAME,
        PARAM_TEXT
    ));

    $settings->add(new admin_settings_coursecat_select(
        'mod_edpreset/sandboxcategoryid',
        get_string('settings:sandboxcategoryid', 'mod_edpreset'),
        get_string('settings:sandboxcategoryid_desc', 'mod_edpreset'),
        0
    ));
}
