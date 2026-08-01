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

namespace mod_edpreset\privacy;

use context;
use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\provider as metadata_provider;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\plugin\provider as plugin_provider;
use core_privacy\local\request\user_preference_provider;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_edpreset\output\chooser_page;
use mod_edpreset\preset;

/**
 * Privacy provider for the activity preset provider.
 *
 * The presets themselves hold nothing personal: they describe exemplar activities in a template
 * course, and the stored backups are taken with user data excluded. Two things about how a teacher
 * uses the preset chooser page are personal, though - which presets they have starred, and which
 * groups they have collapsed - and both are covered here.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements core_userlist_provider, metadata_provider, plugin_provider, user_preference_provider {
    /**
     * Describe what this plugin stores.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_subsystem_link(
            'core_favourites',
            [],
            'privacy:metadata:favourites'
        );

        $collection->add_user_preference(
            chooser_page::PREF_COLLAPSED,
            'privacy:metadata:preference:collapsed'
        );

        return $collection;
    }

    /**
     * The contexts holding data for a user.
     *
     * Stars live in the user's own context, so that is the only context this plugin ever returns.
     *
     * @param int $userid The user id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        \core_favourites\privacy\provider::add_contexts_for_userid(
            $contextlist,
            $userid,
            preset::FAVOURITE_COMPONENT,
            preset::FAVOURITE_ITEMTYPE
        );

        return $contextlist;
    }

    /**
     * The users holding data in a context.
     *
     * @param userlist $userlist The userlist to add to.
     */
    public static function get_users_in_context(userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_user) {
            return;
        }

        \core_favourites\privacy\provider::add_userids_for_context(
            $userlist,
            preset::FAVOURITE_ITEMTYPE
        );
    }

    /**
     * Export the starred presets.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_user || $context->instanceid != $userid) {
                continue;
            }

            $starred = [];
            foreach (preset::get_records() as $preset) {
                $info = \core_favourites\privacy\provider::get_favourites_info_for_user(
                    $userid,
                    $context,
                    preset::FAVOURITE_COMPONENT,
                    preset::FAVOURITE_ITEMTYPE,
                    (int)$preset->get('id')
                );
                if ($info) {
                    $starred[] = ['preset' => $preset->get('title')] + $info;
                }
            }

            if ($starred) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:path:favourites', 'mod_edpreset')],
                    (object)['presets' => $starred]
                );
            }
        }
    }

    /**
     * Export the collapsed-groups preference.
     *
     * The stored value is a list of hashes of section names, which is not meaningful to a person,
     * so what is exported is the count rather than the raw value.
     *
     * @param int $userid The user id.
     */
    public static function export_user_preferences(int $userid): void {
        $value = get_user_preferences(chooser_page::PREF_COLLAPSED, null, $userid);
        if ($value === null) {
            return;
        }

        $count = count(array_filter(explode(',', $value), 'strlen'));

        writer::export_user_preference(
            'mod_edpreset',
            chooser_page::PREF_COLLAPSED,
            $count,
            get_string('privacy:metadata:preference:collapsed', 'mod_edpreset')
        );
    }

    /**
     * Delete every user's stars in a context.
     *
     * @param context $context The context.
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        if (!$context instanceof \context_user) {
            return;
        }

        \core_favourites\privacy\provider::delete_favourites_for_all_users(
            $context,
            preset::FAVOURITE_COMPONENT,
            preset::FAVOURITE_ITEMTYPE
        );
    }

    /**
     * Delete one user's stars.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        \core_favourites\privacy\provider::delete_favourites_for_user(
            $contextlist,
            preset::FAVOURITE_COMPONENT,
            preset::FAVOURITE_ITEMTYPE
        );
    }

    /**
     * Delete the stars of a set of users in a context.
     *
     * @param approved_userlist $userlist The approved userlist.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        if (!$userlist->get_context() instanceof \context_user) {
            return;
        }

        \core_favourites\privacy\provider::delete_favourites_for_userlist(
            $userlist,
            preset::FAVOURITE_ITEMTYPE
        );
    }
}
