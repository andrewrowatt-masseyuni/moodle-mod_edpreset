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

use core\persistent;
use stored_file;

/**
 * One pseudo activity, derived from an exemplar activity in the template course.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preset extends persistent {
    /** @var string Table name. */
    public const TABLE = 'edpreset_item';

    /** @var string Queued for baking; no archive is being produced yet. */
    public const STATUS_PENDING = 'pending';

    /** @var string A backup is being produced. */
    public const STATUS_BAKING = 'baking';

    /** @var string An archive is staged and awaiting its test restore. */
    public const STATUS_VALIDATING = 'validating';

    /** @var string A validated archive is live. */
    public const STATUS_READY = 'ready';

    /** @var string The bake or the validation failed; see statusdetail. */
    public const STATUS_FAILED = 'failed';

    /** @var string File area holding the archive currently being validated. */
    public const FILEAREA_STAGING = 'presetstaging';

    /** @var string File area holding the untouched backup, used if a scrub breaks the restore. */
    public const FILEAREA_UNSCRUBBED = 'presetunscrubbed';

    /** @var string File area holding the validated, live archive the chooser offers. */
    public const FILEAREA_BACKUP = 'presetbackup';

    /**
     * Component the preset chooser page's stars are recorded against.
     *
     * Deliberately this plugin's own rather than core_course's: the standard chooser's stars are
     * keyed on content item ids and only cover the presets it offers, whereas these have to cover
     * the ones it does not.
     *
     * @var string
     */
    public const FAVOURITE_COMPONENT = 'mod_edpreset';

    /** @var string Item type the preset chooser page's stars are recorded against. */
    public const FAVOURITE_ITEMTYPE = 'preset';

    /**
     * Define the properties of this persistent.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'templatecourseid' => ['type' => PARAM_INT],
            'templatecmid' => ['type' => PARAM_INT],
            'modname' => ['type' => PARAM_PLUGIN],
            'instanceid' => ['type' => PARAM_INT],
            'contextid' => ['type' => PARAM_INT],
            'title' => ['type' => PARAM_TEXT],
            'help' => ['type' => PARAM_RAW, 'default' => '', 'null' => NULL_ALLOWED],
            // Cleaned HTML, rendered from the curator's markdown at bake time. PARAM_RAW because
            // the cleaning has already happened; it must never be re-cleaned or escaped here.
            'description' => ['type' => PARAM_RAW, 'default' => '', 'null' => NULL_ALLOWED],
            // Cleaned HTML like description, but read live by mod_ednote rather than copied into
            // the note, so that editing the exemplar's guidance reaches notes already in courses.
            'teacherguidance' => ['type' => PARAM_RAW, 'default' => '', 'null' => NULL_ALLOWED],
            'tags' => ['type' => PARAM_TEXT, 'default' => ''],
            'defaultname' => ['type' => PARAM_TEXT, 'default' => ''],
            'icon' => ['type' => PARAM_SAFEDIR, 'default' => 'monologo'],
            'archetype' => ['type' => PARAM_INT, 'default' => MOD_ARCHETYPE_OTHER],
            'purpose' => ['type' => PARAM_ALPHA, 'default' => MOD_PURPOSE_OTHER],
            'branded' => ['type' => PARAM_BOOL, 'default' => 0],
            'category' => ['type' => PARAM_TEXT, 'default' => ''],
            'sectionnum' => ['type' => PARAM_INT, 'default' => 0],
            'sortorder' => ['type' => PARAM_INT, 'default' => 0],
            'status' => [
                'type' => PARAM_ALPHA,
                'default' => self::STATUS_PENDING,
                'choices' => [
                    self::STATUS_PENDING,
                    self::STATUS_BAKING,
                    self::STATUS_VALIDATING,
                    self::STATUS_READY,
                    self::STATUS_FAILED,
                ],
            ],
            'statusdetail' => ['type' => PARAM_TEXT, 'default' => '', 'null' => NULL_ALLOWED],
            'datescleared' => ['type' => PARAM_TEXT, 'default' => '', 'null' => NULL_ALLOWED],
            'scrubbed' => ['type' => PARAM_BOOL, 'default' => 0],
            'backupcontenthash' => ['type' => PARAM_ALPHANUM, 'default' => null, 'null' => NULL_ALLOWED],
            'backupfilesize' => ['type' => PARAM_INT, 'default' => 0],
            'backuptimebaked' => ['type' => PARAM_INT, 'default' => 0],
            'timevalidated' => ['type' => PARAM_INT, 'default' => 0],
            'exemplartimemodified' => ['type' => PARAM_INT, 'default' => 0],
            'enabled' => ['type' => PARAM_BOOL, 'default' => 1],
        ];
    }

    /**
     * Whether this preset has a validated archive and may therefore be offered in the chooser.
     *
     * This, not the status field, is the gate on chooser visibility. Two things follow: a preset
     * can never appear without a proven backup behind it, and a re-bake in flight does not pull a
     * working preset out of the chooser.
     *
     * @return bool
     */
    public function is_live(): bool {
        if (!$this->get('enabled')) {
            return false;
        }
        $file = $this->get_live_file();
        return $file && $file->get_contenthash() === $this->get('backupcontenthash');
    }

    /**
     * Get the validated archive that the chooser offers.
     *
     * @return stored_file|false
     */
    public function get_live_file() {
        return $this->get_file(self::FILEAREA_BACKUP);
    }

    /**
     * Get the archive awaiting validation.
     *
     * @return stored_file|false
     */
    public function get_staging_file() {
        return $this->get_file(self::FILEAREA_STAGING);
    }

    /**
     * Get the untouched backup kept as a fallback in case a scrub rule broke the restore.
     *
     * @return stored_file|false
     */
    public function get_unscrubbed_file() {
        return $this->get_file(self::FILEAREA_UNSCRUBBED);
    }

    /**
     * Get this preset's archive from one of the plugin's system-context file areas.
     *
     * This plugin serves no files at all - it has no pluginfile callback - so there is no URL that
     * reaches any of these archives.
     *
     * @param string $filearea One of the FILEAREA_* constants.
     * @return stored_file|false
     */
    protected function get_file(string $filearea) {
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'mod_edpreset',
            $filearea,
            $this->get('id'),
            'itemid',
            false
        );
        return $files ? reset($files) : false;
    }

    /**
     * The exemplar module's icon, as rendered HTML.
     *
     * Not stored on the record: the markup embeds $CFG->wwwroot and the theme revision, so it is
     * regenerated per request.
     *
     * @return string
     */
    public function get_icon_html(): string {
        global $OUTPUT;

        $modname = $this->get('modname');
        // Modules without a monologo icon must not have the colour filter applied to them.
        $iconclass = \core_component::has_monologo_icon('mod', $modname) ? '' : 'nofilter';

        return $OUTPUT->pix_icon(
            $this->get('icon'),
            '',
            $modname,
            ['class' => "mod_edpreset-icon activityicon $iconclass"]
        );
    }

    /**
     * The module context of the exemplar this preset was made from.
     *
     * @return \context_module|null Null if the exemplar has since been deleted.
     */
    public function get_exemplar_context(): ?\context_module {
        $context = \context::instance_by_id($this->get('contextid'), IGNORE_MISSING);
        return ($context instanceof \context_module) ? $context : null;
    }
}
