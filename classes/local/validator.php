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

use mod_edpreset\preset;
use stored_file;
use Throwable;

/**
 * Proves a candidate archive restores before it is published to the chooser.
 *
 * This is what makes best-effort scrubbing safe. A scrub rule cannot be verified by inspection
 * across every module and every future Moodle release, so instead of trusting it the archive is
 * test-restored: if a rule broke that module's restore, validation fails and the unscrubbed
 * archive is published in its place. The correct behaviour per module is discovered
 * automatically, and a curator never has to know.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class validator {
    /**
     * Validate the preset's staged archive and publish it if it restores.
     *
     * @param preset $preset The preset whose staged archive should be proven.
     * @return bool True if the preset is now live.
     */
    public static function process(preset $preset): bool {
        $staged = $preset->get_staging_file();
        if (!$staged) {
            $preset->set('status', preset::STATUS_FAILED);
            $preset->set('statusdetail', get_string('novalidationarchive', 'mod_edpreset'));
            $preset->update();
            return false;
        }

        $error = self::test_restore($staged);

        if ($error === null) {
            self::promote($preset, $staged);
            return true;
        }

        // The scrubbed archive would not restore. Before giving up, try the untouched backup: this
        // is what makes best-effort scrubbing safe. If a rule zeroed a field this module's restore
        // actually needs, the unscrubbed original is published instead and the preset still works,
        // with the reason recorded for the curator. No human has to know which modules tolerate
        // which rules.
        $unscrubbed = $preset->get('scrubbed') ? $preset->get_unscrubbed_file() : false;

        if ($unscrubbed && self::test_restore($unscrubbed) === null) {
            self::promote($preset, $unscrubbed);
            $preset->set('scrubbed', 0);
            $preset->set('datescleared', get_string('scrubbrokerestore', 'mod_edpreset'));
            $preset->set('statusdetail', $error);
            $preset->update();
            return true;
        }

        // The previously validated archive, if there is one, is deliberately left in place and
        // keeps serving. A failed re-bake must not remove a preset that already works.
        $preset->set('status', preset::STATUS_FAILED);
        $preset->set('statusdetail', $error);
        $preset->update();
        self::clear_candidates($preset);
        return false;
    }

    /**
     * Discard the candidate archives once they are no longer needed.
     *
     * @param preset $preset The preset.
     */
    protected static function clear_candidates(preset $preset): void {
        backup_baker::clear_area($preset, preset::FILEAREA_STAGING);
        backup_baker::clear_area($preset, preset::FILEAREA_UNSCRUBBED);
    }

    /**
     * Restore an archive into the sandbox, check the result, and remove it again.
     *
     * @param stored_file $archive The archive to prove.
     * @return string|null Null if it restored, otherwise the failure detail.
     */
    public static function test_restore(stored_file $archive): ?string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $sandbox = sandbox::get();

        // Always on the way in, so debris from a crashed run cannot contaminate this one.
        sandbox::wipe($sandbox);

        $cmid = null;
        try {
            $cmid = activity_copier::restore_into(
                $archive,
                $sandbox,
                sandbox::SECTION,
                0,
                get_admin()->id
            );

            $cm = get_coursemodule_from_id('', $cmid, $sandbox->id, false, MUST_EXIST);
            if (!$DB->record_exists($cm->modname, ['id' => $cm->instance])) {
                return get_string('validationnoinstance', 'mod_edpreset', $cm->modname);
            }

            return null;
        } catch (Throwable $e) {
            return get_class($e) . ': ' . $e->getMessage();
        } finally {
            // The wipe on the way in is the real guarantee, but leaving the sandbox empty between
            // runs means an admin who looks at it sees what they expect.
            if ($cmid) {
                try {
                    course_delete_module($cmid);
                } catch (Throwable $e) {
                    debugging(
                        'mod_edpreset could not clean up the validation sandbox: ' . $e->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }
            }
        }
    }

    /**
     * Move a proven archive into the live area and mark the preset ready.
     *
     * @param preset $preset The preset.
     * @param stored_file $staged The proven archive.
     */
    protected static function promote(preset $preset, stored_file $staged): void {
        backup_baker::clear_area($preset, preset::FILEAREA_BACKUP);

        $live = get_file_storage()->create_file_from_storedfile([
            'contextid' => \context_system::instance()->id,
            'component' => 'mod_edpreset',
            'filearea' => preset::FILEAREA_BACKUP,
            'itemid' => $preset->get('id'),
            'filepath' => '/',
            'filename' => 'preset_' . $preset->get('id') . '.mbz',
        ], $staged);

        $preset->set('backupcontenthash', $live->get_contenthash());
        $preset->set('backupfilesize', $live->get_filesize());
        $preset->set('timevalidated', time());
        $preset->set('status', preset::STATUS_READY);
        $preset->set('statusdetail', '');
        $preset->update();

        // Safe to do now the live copy exists: $staged may itself be the unscrubbed archive.
        self::clear_candidates($preset);
    }
}
