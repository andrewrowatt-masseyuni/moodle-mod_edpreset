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
 * Library functions for the activity preset provider.
 *
 * This module is not an activity in the usual sense. It is never added to a course; it exists
 * solely so that pseudo activities can be contributed to the activity chooser. Core requires
 * chooser content items to originate from a mod_* component (see
 * \core_course\local\service\content_item_service::get_content_items_for_user_in_course), and
 * implementing mod_edpreset_get_course_content_items() causes core to discard this module's own
 * default chooser item, so "Activity preset provider" never appears in the chooser itself.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Return the features this module supports.
 *
 * MOD_ARCHETYPE_SYSTEM keeps the provider out of the admin module pickers built by
 * admin_setting_configmultiselect_modules() and suppresses the missing-capability debugging
 * notice in course_allowed_module(). It does not affect which chooser tab a preset lands in:
 * that is driven by each content item's own archetype, which is copied from the exemplar.
 *
 * @param string $feature FEATURE_xx constant.
 * @return mixed True/false for supported features, null for unknown ones.
 */
function edpreset_supports($feature) {
    return match ($feature) {
        FEATURE_MOD_ARCHETYPE => MOD_ARCHETYPE_SYSTEM,
        FEATURE_MOD_PURPOSE => MOD_PURPOSE_ADMINISTRATION,
        FEATURE_NO_VIEW_LINK => true,
        FEATURE_BACKUP_MOODLE2 => false,
        FEATURE_MOD_INTRO => false,
        FEATURE_COMPLETION_TRACKS_VIEWS => false,
        FEATURE_COMPLETION_HAS_RULES => false,
        FEATURE_GRADE_HAS_GRADE => false,
        FEATURE_GRADE_OUTCOMES => false,
        FEATURE_GROUPS => false,
        FEATURE_GROUPINGS => false,
        FEATURE_SHOW_DESCRIPTION => false,
        FEATURE_IDNUMBER => false,
        default => null,
    };
}

/**
 * Refuse to create an instance.
 *
 * Teachers hold mod/edpreset:addinstance (that capability is the per-course gate on whether
 * presets are offered), which means /course/modedit.php?add=edpreset is reachable by hand. This
 * is the guard that stops it producing anything.
 *
 * @param stdClass $data Form data.
 * @param mod_edpreset_mod_form|null $mform The form.
 * @return int Never returns.
 * @throws moodle_exception Always.
 */
function edpreset_add_instance($data, $mform = null) {
    throw new moodle_exception('cannotcreateinstance', 'mod_edpreset');
}

/**
 * Refuse to update an instance. See edpreset_add_instance().
 *
 * @param stdClass $data Form data.
 * @param mod_edpreset_mod_form|null $mform The form.
 * @return bool Never returns.
 * @throws moodle_exception Always.
 */
function edpreset_update_instance($data, $mform = null) {
    throw new moodle_exception('cannotcreateinstance', 'mod_edpreset');
}

/**
 * Delete an instance.
 *
 * No instance can exist, but course_delete_module() calls this unconditionally, so it must be
 * present and must succeed.
 *
 * @param int $id Instance id.
 * @return bool Always true.
 */
function edpreset_delete_instance($id) {
    return true;
}

/**
 * Reset user data.
 *
 * Unreachable in practice, but its absence would list this module under "reset not implemented"
 * on the course reset page.
 *
 * @param stdClass $data Reset form data.
 * @return array Always empty.
 */
function edpreset_reset_userdata($data) {
    return [];
}

/**
 * Contribute the pseudo activities to a course's activity chooser.
 *
 * Implementing this callback makes core discard the default chooser item it built for this module
 * (see \core_course\local\repository\content_item_readonly_repository::find_all_for_course), which
 * is why "Activity preset provider" never appears in the chooser itself.
 *
 * @param \core_course\local\entity\content_item $defaultmodulecontentitem The discarded default item.
 * @param stdClass $user The user the chooser is being built for.
 * @param stdClass $course The course whose chooser is being built.
 * @return \core_course\local\entity\content_item[]
 */
function mod_edpreset_get_course_content_items($defaultmodulecontentitem, $user, $course) {
    return \mod_edpreset\local\chooser::get_content_items($course, $user);
}

/**
 * Contribute the pseudo activities where no course context is available.
 *
 * This must be implemented alongside mod_edpreset_get_course_content_items(). Core's
 * content_item_service::add_to_user_favourites() resolves the favourited item by running
 * array_search() over the ids returned here, so a preset missing from this list would make
 * favouriting silently return a different item.
 *
 * It also makes presets available on the "Recommend activities" admin page.
 *
 * @param \core_course\local\entity\content_item $defaultmodulecontentitem The discarded default item.
 * @return \core_course\local\entity\content_item[]
 */
function mod_edpreset_get_all_content_items($defaultmodulecontentitem) {
    return \mod_edpreset\local\chooser::get_all_content_items();
}

/**
 * Serve images embedded in a preset's description.
 *
 * This is a deliberate publication decision, and the only one this plugin makes. A preset's
 * description is shown to every user who can add an activity in any course, none of whom
 * necessarily have access to the template course the description came from, so its images are
 * copied to system context at bake time and served from here.
 *
 * Only that area is served. The stored activity backups deliberately have no branch here, so no
 * URL reaches them.
 *
 * @param stdClass $course Unused; these files live at system context.
 * @param stdClass $cm Unused.
 * @param context $context The context the file was requested against.
 * @param string $filearea The file area.
 * @param array $args Item id followed by the file path.
 * @param bool $forcedownload Whether to force download.
 * @param array $options Additional serving options.
 * @return bool False if the file could not be served.
 */
function edpreset_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($filearea !== \mod_edpreset\preset::FILEAREA_HELP) {
        return false;
    }
    if ($context->contextlevel !== CONTEXT_SYSTEM) {
        return false;
    }
    if (!get_config('mod_edpreset', 'enabled')) {
        return false;
    }

    require_login();
    if (isguestuser()) {
        return false;
    }

    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $file = get_file_storage()->get_file(
        $context->id,
        'mod_edpreset',
        \mod_edpreset\preset::FILEAREA_HELP,
        $itemid,
        $filepath,
        $filename
    );

    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, HOURSECS, 0, $forcedownload, $options);
}
