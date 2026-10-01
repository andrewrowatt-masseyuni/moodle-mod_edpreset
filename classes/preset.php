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

use cm_info;
use core\persistent;

/**
 * One pseudo activity, derived from an exemplar activity in the template course.
 *
 * Nothing is stored for a preset but its description. A copy backs the exemplar up and restores it
 * there and then (see local\activity_copier), so there is no archive to keep in step with the
 * exemplar, and what a teacher gets is always the exemplar as it stands.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preset extends persistent {
    /** @var string Table name. */
    public const TABLE = 'edpreset_item';

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

    /** @var int How much of a copy failure's message is kept for the manage page. */
    protected const LASTERROR_MAXLENGTH = 1000;

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
            // Cleaned HTML, rendered from the curator's text when the preset is scanned. PARAM_RAW
            // because the cleaning has already happened; it must never be re-cleaned or escaped here.
            'description' => ['type' => PARAM_RAW, 'default' => '', 'null' => NULL_ALLOWED],
            'tags' => ['type' => PARAM_TEXT, 'default' => ''],
            'defaultname' => ['type' => PARAM_TEXT, 'default' => ''],
            // Copied from the curator's details. Shown on the preset chooser page as a pseudo tag.
            'recommendedsection' => ['type' => PARAM_TEXT, 'default' => ''],
            'icon' => ['type' => PARAM_SAFEDIR, 'default' => 'monologo'],
            'archetype' => ['type' => PARAM_INT, 'default' => MOD_ARCHETYPE_OTHER],
            'purpose' => ['type' => PARAM_ALPHA, 'default' => MOD_PURPOSE_OTHER],
            'branded' => ['type' => PARAM_BOOL, 'default' => 0],
            'category' => ['type' => PARAM_TEXT, 'default' => ''],
            // Non-empty means this preset is a member of a section template, and is therefore
            // offered only as part of that template - never as a card of its own.
            'templatename' => ['type' => PARAM_TEXT, 'default' => ''],
            // Whether the template this preset belongs to is marked [Template,restricted]. Carried on
            // every member, like templatename. Meaningless on a preset that is not a member.
            'templaterestricted' => ['type' => PARAM_BOOL, 'default' => 0],
            // The exemplar's section summary: a template card's description, or the text under an
            // ordinary section's heading. Cleaned HTML like description, and PARAM_RAW for the same
            // reason: the cleaning has already happened and must not be repeated or escaped here.
            'sectionsummary' => ['type' => PARAM_RAW, 'default' => '', 'null' => NULL_ALLOWED],
            'sectionnum' => ['type' => PARAM_INT, 'default' => 0],
            'sortorder' => ['type' => PARAM_INT, 'default' => 0],
            // The curator's release status, copied from the preset details. See is_offered().
            'status' => ['type' => PARAM_ALPHA, 'default' => meta::STATUS_DRAFT, 'choices' => meta::STATUSES],
            // Copied from the preset details: whether the standard activity chooser offers it too.
            'showinchooser' => ['type' => PARAM_BOOL, 'default' => 0],
            // Written by record_copy_error() and clear_copy_error() only - see there for why.
            'lasterror' => ['type' => PARAM_TEXT, 'default' => '', 'null' => NULL_ALLOWED],
            'timelasterror' => ['type' => PARAM_INT, 'default' => 0],
            'enabled' => ['type' => PARAM_BOOL, 'default' => 1],
        ];
    }

    /**
     * Whether this preset belongs to a section template.
     *
     * A member is offered only as part of its template: it is kept out of the standard activity
     * chooser and out of the preset chooser page's category groups.
     *
     * @return bool
     */
    public function is_template_member(): bool {
        return trim((string)$this->get('templatename')) !== '';
    }

    /**
     * Whether this preset may be offered to someone.
     *
     * The release status alone decides it. A released preset is offered to everyone who may add
     * presets at all; one ready for review only to those who can review presets; a draft or an
     * archived one to nobody.
     *
     * @param bool $canreview Whether the user holds mod/edpreset:reviewpresets where it would be added.
     * @return bool
     */
    public function is_offered(bool $canreview): bool {
        if (!$this->get('enabled')) {
            return false;
        }

        return match ($this->get('status')) {
            meta::STATUS_RELEASED => true,
            meta::STATUS_REVIEW => $canreview,
            default => false,
        };
    }

    /**
     * Whether this preset is offered only because the user can review presets.
     *
     * @return bool
     */
    public function is_in_review(): bool {
        return $this->get('status') === meta::STATUS_REVIEW;
    }

    /**
     * The exemplar this preset copies, as it is now.
     *
     * @return cm_info|null Null if the exemplar has gone, or is on its way out.
     */
    public function get_exemplar(): ?cm_info {
        $courseid = (int)$this->get('templatecourseid');
        if (!$courseid) {
            return null;
        }

        try {
            $cm = get_fast_modinfo($courseid)->get_cm((int)$this->get('templatecmid'));
        } catch (\moodle_exception $e) {
            return null;
        }

        return $cm->deletioninprogress ? null : $cm;
    }

    /**
     * Record why a copy of this preset failed, for the manage page.
     *
     * Written straight to the table rather than through update(): this runs in a teacher's request,
     * and the persistent would stamp that teacher into usermodified, which the privacy provider
     * declares as the curator who last saved the preset.
     *
     * @param string $error What went wrong.
     */
    public function record_copy_error(string $error): void {
        global $DB;

        $error = \core_text::substr($error, 0, self::LASTERROR_MAXLENGTH);
        $now = time();

        $DB->update_record(self::TABLE, (object)[
            'id' => $this->get('id'),
            'lasterror' => $error,
            'timelasterror' => $now,
        ]);

        // Keep this instance in step without going through set(), which would mark it changed.
        $this->raw_set('lasterror', $error);
        $this->raw_set('timelasterror', $now);
    }

    /**
     * Forget the last copy failure, once a copy has worked again.
     *
     * Only writes when there is something to forget, since this runs on every successful copy.
     */
    public function clear_copy_error(): void {
        global $DB;

        if ((string)$this->get('lasterror') === '' && !$this->get('timelasterror')) {
            return;
        }

        $DB->update_record(self::TABLE, (object)[
            'id' => $this->get('id'),
            'lasterror' => null,
            'timelasterror' => 0,
        ]);

        $this->raw_set('lasterror', null);
        $this->raw_set('timelasterror', 0);
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
}
