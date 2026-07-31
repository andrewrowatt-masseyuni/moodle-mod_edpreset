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

use backup;
use backup_controller;
use mod_edpreset\preset;
use moodle_exception;
use stored_file;

/**
 * Produces the activity archive for one exemplar.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_baker {
    /**
     * Back up an exemplar activity and stage the archive against its preset.
     *
     * The archive goes to the staging area, not the live one: it is only promoted once a test
     * restore has proven it works (see validator).
     *
     * @param preset $preset The preset whose exemplar should be backed up.
     * @return stored_file The staged archive.
     * @throws moodle_exception If the backup produces no file, or the result exceeds maxbackupsize.
     */
    public static function bake(preset $preset): stored_file {
        global $CFG, $USER;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        $cmid = (int)$preset->get('templatecmid');

        // MODE_GENERAL, not MODE_IMPORT: backup_helper::store_backup_file() returns null for
        // MODE_IMPORT, so an import-mode backup leaves no file to keep. This runs as an admin from
        // cron, which is what satisfies moodle/backup:backuptargetimport in the template course.
        $bc = new backup_controller(
            backup::TYPE_1ACTIVITY,
            $cmid,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id
        );

        try {
            self::disable_user_data_settings($bc);
            $bc->execute_plan();
            $results = $bc->get_results();
        } finally {
            $bc->destroy();
        }

        if (empty($results['backup_destination'])) {
            throw new moodle_exception('backupproducednofile', 'mod_edpreset');
        }

        /** @var stored_file $produced */
        $produced = $results['backup_destination'];

        $maxsize = (int)get_config('mod_edpreset', 'maxbackupsize');
        if ($maxsize > 0 && $produced->get_filesize() > $maxsize) {
            $size = display_size($produced->get_filesize());
            $produced->delete();
            throw new moodle_exception('backuptoolarge', 'mod_edpreset', '', $size);
        }

        // Keep the untouched backup: if a scrub rule turns out to break this module's restore, the
        // validation pass falls back to publishing this instead.
        $unscrubbed = self::store($preset, $produced, preset::FILEAREA_UNSCRUBBED);

        // The backup lands in the exemplar's own module context, inside the template course.
        // Remove it so curators do not find stray archives attached to their exemplars.
        $produced->delete();

        $staged = self::scrub_into_staging($preset, $unscrubbed);

        return $staged;
    }

    /**
     * Rewrite the backup into the staging area, falling back to the untouched copy.
     *
     * Scrubbing is best effort: a preset with stale dates is useful, a preset that does not exist
     * is not. So a scrub failure is recorded against the preset and the original is staged instead
     * of failing the bake.
     *
     * @param preset $preset The preset.
     * @param stored_file $unscrubbed The untouched backup.
     * @return stored_file The staged archive.
     */
    protected static function scrub_into_staging(preset $preset, stored_file $unscrubbed): stored_file {
        $result = scrubber::scrub(
            $unscrubbed,
            $preset->get('modname'),
            (int)$preset->get('templatecmid'),
            [
                'contextid' => \context_system::instance()->id,
                'component' => 'mod_edpreset',
                'filearea' => preset::FILEAREA_STAGING,
                'itemid' => $preset->get('id'),
                'filepath' => '/',
                'filename' => 'preset_' . $preset->get('id') . '.mbz',
            ]
        );

        if ($result['file']) {
            $preset->set('datescleared', scrubber::describe_changes($result['changes']));
            $preset->set('scrubbed', 1);
            $preset->update();
            return $result['file'];
        }

        // Either nothing needed changing, or the scrub failed. Either way the original is staged.
        $preset->set('scrubbed', 0);
        $preset->set(
            'datescleared',
            $result['error']
                ? get_string('scrubfailed', 'mod_edpreset')
                : get_string('scrubnothingtodo', 'mod_edpreset')
        );
        if ($result['error']) {
            $preset->set('statusdetail', $result['error']);
        }
        $preset->update();

        return self::store($preset, $unscrubbed, preset::FILEAREA_STAGING);
    }

    /**
     * Copy an archive into one of the preset's file areas at system context.
     *
     * @param preset $preset The preset.
     * @param stored_file $source The archive to copy.
     * @param string $filearea One of the preset::FILEAREA_* constants.
     * @return stored_file The stored copy.
     */
    protected static function store(preset $preset, stored_file $source, string $filearea): stored_file {
        self::clear_area($preset, $filearea);

        return get_file_storage()->create_file_from_storedfile([
            'contextid' => \context_system::instance()->id,
            'component' => 'mod_edpreset',
            'filearea' => $filearea,
            'itemid' => $preset->get('id'),
            'filepath' => '/',
            'filename' => 'preset_' . $preset->get('id') . '.mbz',
        ], $source);
    }

    /**
     * Delete everything in one of a preset's file areas.
     *
     * @param preset $preset The preset.
     * @param string $filearea One of the preset::FILEAREA_* constants.
     */
    public static function clear_area(preset $preset, string $filearea): void {
        get_file_storage()->delete_area_files(
            \context_system::instance()->id,
            'mod_edpreset',
            $filearea,
            $preset->get('id')
        );
    }

    /**
     * Turn off everything that would put user data or site-specific extras in the archive.
     *
     * @param backup_controller $bc The controller.
     */
    protected static function disable_user_data_settings(backup_controller $bc): void {
        $unwanted = [
            'users', 'anonymize', 'role_assignments', 'userscompletion', 'logs',
            'grade_histories', 'groups', 'comments', 'badges', 'calendarevents',
            'contentbankcontent', 'legacyfiles',
        ];

        $plan = $bc->get_plan();
        foreach ($unwanted as $name) {
            if (!$plan->setting_exists($name)) {
                continue;
            }
            $setting = $plan->get_setting($name);
            if ($setting->get_status() === \base_setting::NOT_LOCKED) {
                $setting->set_value(false);
            }
        }
    }
}
