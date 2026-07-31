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

use mod_edpreset\local\scrub\clear_dates;
use mod_edpreset\local\scrub\rule;
use stored_file;
use Throwable;

/**
 * Rewrites a freshly-taken backup before it becomes a candidate for publication.
 *
 * The archive is rewritten rather than the exemplar: nulling the exemplar's own columns around the
 * backup call would briefly corrupt a live course, and would leave it corrupted if cron died
 * mid-run. Rewriting the archive has neither problem, and the result can be inspected.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scrubber {
    /**
     * The rules to run, in order.
     *
     * @return rule[]
     */
    public static function get_rules(): array {
        return [
            new clear_dates(),
        ];
    }

    /**
     * Rewrite an archive, writing the result to the given file area.
     *
     * Fail-soft by design. If a rule throws, or the archive cannot be read or repacked, the caller
     * gets an error and falls back to publishing the untouched original: a preset with stale dates
     * is useful, a preset that does not exist is not. A rule that throws is caught individually, so
     * one bad rule does not discard the work of the others.
     *
     * @param stored_file $source The archive to rewrite.
     * @param string $modname The exemplar's module name.
     * @param int $cmid The exemplar's course module id.
     * @param array $fileinfo Destination file record for the rewritten archive.
     * @return array{file: ?stored_file, changes: string[], error: ?string}
     */
    public static function scrub(stored_file $source, string $modname, int $cmid, array $fileinfo): array {
        global $CFG;

        $changes = [];
        $basepath = null;

        try {
            $tempname = \restore_controller::get_tempdir_name(0, 0);
            $basepath = make_backup_temp_directory('edpreset_scrub_' . $tempname);

            $packer = get_file_packer('application/vnd.moodle.backup');
            if ($packer->extract_to_pathname($source, $basepath) === false) {
                throw new \moodle_exception('scrubextractfailed', 'mod_edpreset');
            }

            foreach (self::get_rules() as $rule) {
                if (!$rule->applies_to($modname)) {
                    continue;
                }
                try {
                    $applied = $rule->apply($basepath, $modname, $cmid);
                    if ($applied) {
                        $changes[$rule->get_name()] = $applied;
                    }
                } catch (Throwable $e) {
                    // One misbehaving rule must not cost us the others.
                    debugging(
                        'mod_edpreset scrub rule ' . $rule->get_name() . ' failed: ' . $e->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }
            }

            if (!$changes) {
                // Nothing to rewrite, so hand back the original rather than repacking it.
                return ['file' => null, 'changes' => [], 'error' => null];
            }

            $file = self::repack($basepath, $fileinfo);

            return ['file' => $file, 'changes' => $changes, 'error' => null];
        } catch (Throwable $e) {
            return [
                'file' => null,
                'changes' => [],
                'error' => get_class($e) . ': ' . $e->getMessage(),
            ];
        } finally {
            if ($basepath && empty($CFG->keeptempdirectoriesonbackup)) {
                fulldelete($basepath);
            }
        }
    }

    /**
     * Repack an extracted archive directory into stored file storage.
     *
     * @param string $basepath The extracted archive.
     * @param array $fileinfo Destination file record.
     * @return stored_file
     */
    protected static function repack(string $basepath, array $fileinfo): stored_file {
        // Same listing core's own backup_zip_contents step uses.
        $files = [];
        foreach (get_directory_list($basepath, '', false, true, true) as $relative) {
            $files[$relative] = $basepath . '/' . $relative;
        }

        get_file_storage()->delete_area_files(
            $fileinfo['contextid'],
            $fileinfo['component'],
            $fileinfo['filearea'],
            $fileinfo['itemid']
        );

        $packer = get_file_packer('application/vnd.moodle.backup');
        $file = $packer->archive_to_storage(
            $files,
            $fileinfo['contextid'],
            $fileinfo['component'],
            $fileinfo['filearea'],
            $fileinfo['itemid'],
            $fileinfo['filepath'],
            $fileinfo['filename']
        );

        if (!$file) {
            throw new \moodle_exception('scrubrepackfailed', 'mod_edpreset');
        }

        return $file;
    }

    /**
     * Flatten the per-rule change list into something readable on the manage page.
     *
     * @param array $changes As returned by scrub().
     * @return string
     */
    public static function describe_changes(array $changes): string {
        $parts = [];
        foreach ($changes as $rulename => $fields) {
            $parts[] = $rulename . ': ' . implode(', ', $fields);
        }
        return implode('; ', $parts);
    }
}
