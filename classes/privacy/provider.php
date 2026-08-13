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
use core_privacy\local\request\transform;
use core_privacy\local\request\user_preference_provider;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_edpreset\meta;
use mod_edpreset\output\chooser_page;
use mod_edpreset\preset;

/**
 * Privacy provider for the activity preset provider.
 *
 * The presets themselves hold nothing personal: they describe exemplar activities in a template
 * course, and the stored backups are taken with user data excluded. Three things are personal.
 * Two are about how a teacher uses the preset chooser page - which presets they have starred, and
 * which groups they have collapsed. The third is the authorship stamp core\persistent writes onto
 * both preset tables: usermodified records the curator who last saved the preset's details, and
 * that stamp is site configuration rather than course content, so it is held against the system
 * context and anonymised - not deleted - on request.
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

        $collection->add_database_table(
            meta::TABLE,
            [
                'usermodified' => 'privacy:metadata:edpreset_meta:usermodified',
                'timecreated' => 'privacy:metadata:edpreset_meta:timecreated',
                'timemodified' => 'privacy:metadata:edpreset_meta:timemodified',
            ],
            'privacy:metadata:edpreset_meta'
        );

        $collection->add_database_table(
            preset::TABLE,
            [
                'usermodified' => 'privacy:metadata:edpreset_item:usermodified',
                'timecreated' => 'privacy:metadata:edpreset_item:timecreated',
                'timemodified' => 'privacy:metadata:edpreset_item:timemodified',
            ],
            'privacy:metadata:edpreset_item'
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
     * Stars live in the user's own context. Curator authorship is held against the system context:
     * presets are site configuration, administered from an admin page and stored in system-context
     * file areas, and the exemplar's own module context can be gone while the row it produced is
     * not - stale rows outlive their activity until the next reconcile.
     *
     * @param int $userid The user id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();

        \core_favourites\privacy\provider::add_contexts_for_userid(
            $contextlist,
            $userid,
            preset::FAVOURITE_COMPONENT,
            preset::FAVOURITE_ITEMTYPE
        );

        $stamped = $DB->record_exists(meta::TABLE, ['usermodified' => $userid])
            || $DB->record_exists(preset::TABLE, ['usermodified' => $userid]);
        if ($stamped) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    /**
     * The users holding data in a context.
     *
     * @param userlist $userlist The userlist to add to.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context instanceof \context_user) {
            \core_favourites\privacy\provider::add_userids_for_context(
                $userlist,
                preset::FAVOURITE_ITEMTYPE
            );

            return;
        }

        if ($context instanceof \context_system) {
            // Zero is the unstamped and the anonymised value alike, so it is never a user here.
            $userlist->add_from_sql(
                'usermodified',
                'SELECT usermodified FROM {' . meta::TABLE . '} WHERE usermodified <> 0',
                []
            );
            $userlist->add_from_sql(
                'usermodified',
                'SELECT usermodified FROM {' . preset::TABLE . '} WHERE usermodified <> 0',
                []
            );
        }
    }

    /**
     * Export the starred presets and the presets the user last edited.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && $context->instanceid == $userid) {
                self::export_favourites($userid, $context);
            } else if ($context instanceof \context_system) {
                self::export_authorship($userid, $context);
            }
        }
    }

    /**
     * Export the presets a user has starred.
     *
     * @param int $userid The user id.
     * @param context $context The user's own context.
     */
    protected static function export_favourites(int $userid, context $context): void {
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

    /**
     * Export the presets a user is recorded as having last edited.
     *
     * Only the name and the timestamps are exported: everything else in the two rows describes the
     * preset, not the person who saved it.
     *
     * @param int $userid The user id.
     * @param context $context The system context.
     */
    protected static function export_authorship(int $userid, context $context): void {
        global $DB;

        $details = [];
        $rows = $DB->get_records(meta::TABLE, ['usermodified' => $userid], 'id', 'id, presetname, timecreated, timemodified');
        foreach ($rows as $row) {
            $details[] = [
                'presetname' => $row->presetname,
                'timecreated' => transform::datetime($row->timecreated),
                'timemodified' => transform::datetime($row->timemodified),
            ];
        }

        $items = [];
        $rows = $DB->get_records(preset::TABLE, ['usermodified' => $userid], 'id', 'id, title, timecreated, timemodified');
        foreach ($rows as $row) {
            $items[] = [
                'title' => $row->title,
                'timecreated' => transform::datetime($row->timecreated),
                'timemodified' => transform::datetime($row->timemodified),
            ];
        }

        if (!$details && !$items) {
            return;
        }

        writer::with_context($context)->export_data(
            [get_string('privacy:path:authored', 'mod_edpreset')],
            (object)[
                'presetdetails' => $details,
                'presets' => $items,
            ]
        );
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
     * Delete every user's stars in a context, and every authorship stamp in the system context.
     *
     * @param context $context The context.
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        if ($context instanceof \context_user) {
            \core_favourites\privacy\provider::delete_favourites_for_all_users(
                $context,
                preset::FAVOURITE_COMPONENT,
                preset::FAVOURITE_ITEMTYPE
            );

            return;
        }

        if ($context instanceof \context_system) {
            self::anonymise_authorship('usermodified <> 0', []);
        }
    }

    /**
     * Delete one user's stars, and anonymise their authorship stamps.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        \core_favourites\privacy\provider::delete_favourites_for_user(
            $contextlist,
            preset::FAVOURITE_COMPONENT,
            preset::FAVOURITE_ITEMTYPE
        );

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                self::anonymise_authorship('usermodified = :userid', ['userid' => $contextlist->get_user()->id]);
                break;
            }
        }
    }

    /**
     * Delete the stars of a set of users in a context, or anonymise their authorship stamps.
     *
     * @param approved_userlist $userlist The approved userlist.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        if ($context instanceof \context_user) {
            \core_favourites\privacy\provider::delete_favourites_for_userlist(
                $userlist,
                preset::FAVOURITE_ITEMTYPE
            );

            return;
        }

        if ($context instanceof \context_system && $userlist->get_userids()) {
            [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
            self::anonymise_authorship("usermodified $insql", $params);
        }
    }

    /**
     * Unstamp the curator from the preset rows matching a condition.
     *
     * The rows are site configuration - what a preset is, and what teachers are offered - so they
     * are not the requesting user's to delete. Only the stamp goes, set back to the zero it holds
     * before anyone has saved the row.
     *
     * @param string $select The WHERE clause, on the usermodified column.
     * @param array $params The clause's parameters.
     */
    protected static function anonymise_authorship(string $select, array $params): void {
        global $DB;

        $DB->set_field_select(meta::TABLE, 'usermodified', 0, $select, $params);
        $DB->set_field_select(preset::TABLE, 'usermodified', 0, $select, $params);
    }
}
