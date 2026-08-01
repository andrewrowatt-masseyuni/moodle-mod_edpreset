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

use context_user;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_favourites\service_factory;
use mod_edpreset\output\chooser_page;
use mod_edpreset\preset;
use moodle_exception;

/**
 * Star or unstar a preset for the current user.
 *
 * These stars are this plugin's own, not the standard activity chooser's: they are keyed on
 * component mod_edpreset in the user's context, so they cover the presets the standard chooser
 * never shows without disturbing anything core owns.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_favourite extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'presetid' => new external_value(PARAM_INT, 'The preset to star or unstar'),
            'favourite' => new external_value(PARAM_BOOL, 'Whether the preset should be starred'),
        ]);
    }

    /**
     * Set the star.
     *
     * @param int $presetid The preset.
     * @param bool $favourite Whether it should be starred.
     * @return array
     */
    public static function execute(int $presetid, bool $favourite): array {
        global $USER;

        ['presetid' => $presetid, 'favourite' => $favourite] = self::validate_parameters(
            self::execute_parameters(),
            ['presetid' => $presetid, 'favourite' => $favourite]
        );

        $usercontext = context_user::instance($USER->id);
        self::validate_context($usercontext);

        // Starring something that does not exist would leave a row nothing ever cleans up.
        if (!preset::record_exists($presetid)) {
            throw new moodle_exception('invalidpreset', 'mod_edpreset');
        }

        $service = service_factory::get_service_for_user_context($usercontext);
        $exists = $service->favourite_exists(
            preset::FAVOURITE_COMPONENT,
            preset::FAVOURITE_ITEMTYPE,
            $presetid,
            $usercontext
        );

        // Both calls are unguarded in core: create_favourite() inserts straight into a table with a
        // unique index, and delete_favourite() throws when there is nothing to delete. A
        // double-click, or two tabs, would otherwise produce a 500.
        if ($favourite && !$exists) {
            $service->create_favourite(
                preset::FAVOURITE_COMPONENT,
                preset::FAVOURITE_ITEMTYPE,
                $presetid,
                $usercontext
            );
        } else if (!$favourite && $exists) {
            $service->delete_favourite(
                preset::FAVOURITE_COMPONENT,
                preset::FAVOURITE_ITEMTYPE,
                $presetid,
                $usercontext
            );
        }

        return ['favourite' => $favourite];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'favourite' => new external_value(PARAM_BOOL, 'Whether the preset is now starred'),
        ]);
    }
}
