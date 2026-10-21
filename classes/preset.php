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
use mod_edpreset\local\template;

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
            // The curator's preset name as typed. Formatted only when shown: see get_formatted_title().
            'title' => ['type' => PARAM_TEXT],
            // Cleaned HTML like description, which it is built from. Filtered when shown.
            'help' => ['type' => PARAM_RAW, 'default' => '', 'null' => NULL_ALLOWED],
            // Cleaned HTML, rendered from the curator's text when the preset is scanned. PARAM_RAW
            // because the cleaning has already happened; it must never be re-cleaned or escaped here.
            // Filters have not run on it: see get_formatted_description().
            'description' => ['type' => PARAM_RAW, 'default' => '', 'null' => NULL_ALLOWED],
            'tags' => ['type' => PARAM_TEXT, 'default' => ''],
            'defaultname' => ['type' => PARAM_TEXT, 'default' => ''],
            // Copied from the curator's details. Shown on the preset chooser page as a pseudo tag.
            'recommendedsection' => ['type' => PARAM_TEXT, 'default' => ''],
            'icon' => ['type' => PARAM_SAFEDIR, 'default' => 'monologo'],
            'archetype' => ['type' => PARAM_INT, 'default' => MOD_ARCHETYPE_OTHER],
            'purpose' => ['type' => PARAM_ALPHA, 'default' => MOD_PURPOSE_OTHER],
            'branded' => ['type' => PARAM_BOOL, 'default' => 0],
            // The exemplar's section name as typed, or the format's default name for an unnamed one.
            'category' => ['type' => PARAM_TEXT, 'default' => ''],
            // Non-empty means this preset is a member of a section template, and is therefore
            // offered only as part of that template - never as a card of its own. The section name
            // as typed, marker removed, and unformatted: it is also the template's identity, which a
            // course records once it has used the template (see local\coursedefault), so it must not
            // depend on filters or on who ran the scan.
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
     * A preset from the configured template course, by id.
     *
     * Anything acting on a preset id that arrived in a request looks it up here rather than by id
     * alone. Rows outlive a change of template course until the next rescan sweeps them (see
     * local\baker::remove_presets_except()), and a copy backs its exemplar up as the site
     * administrator - so a stale row looked up by id would let a teacher copy an activity out of a
     * course that is no longer the template course, and may by now be an ordinary one.
     *
     * @param int $id The preset id.
     * @return self|null Null if there is no such enabled preset in the template course.
     */
    public static function get_from_template_course(int $id): ?self {
        $courseid = template::get_courseid();
        if (!$courseid) {
            return null;
        }

        return self::get_record(['id' => $id, 'templatecourseid' => $courseid, 'enabled' => 1]) ?: null;
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
     * The preset's name, ready to show.
     *
     * Every piece of curator text on a preset is formatted when it is shown rather than when the
     * preset is scanned, so that filters run for the person looking at it - in their language, for
     * multilang - rather than once for whoever happened to run the scan.
     *
     * @param bool $escape True for text going straight into HTML; false for text that is escaped on
     *     its way out anyway, such as a {{variable}} in Mustache, which would otherwise escape it twice.
     * @return string
     */
    public function get_formatted_title(bool $escape = false): string {
        return format_string(
            (string)$this->get('title'),
            true,
            ['context' => \context_system::instance(), 'escape' => $escape]
        );
    }

    /**
     * The name of the exemplar's section, ready to show.
     *
     * @param bool $escape As for get_formatted_title().
     * @return string
     */
    public function get_formatted_category(bool $escape = false): string {
        return format_string(
            (string)$this->get('category'),
            true,
            ['context' => $this->get_template_context(), 'escape' => $escape]
        );
    }

    /**
     * The name of the section template this preset belongs to, ready to show.
     *
     * @param bool $escape As for get_formatted_title().
     * @return string '' if the preset is not a template member.
     */
    public function get_formatted_templatename(bool $escape = false): string {
        return format_string(
            (string)$this->get('templatename'),
            true,
            ['context' => $this->get_template_context(), 'escape' => $escape]
        );
    }

    /**
     * The preset's description, ready to show.
     *
     * @return string HTML.
     */
    public function get_formatted_description(): string {
        return self::filter_html((string)$this->get('description'), \context_system::instance());
    }

    /**
     * The description the standard activity chooser shows in its info panel, ready to show.
     *
     * @return string HTML.
     */
    public function get_formatted_help(): string {
        return self::filter_html((string)$this->get('help'), \context_system::instance());
    }

    /**
     * The exemplar's section summary, ready to show.
     *
     * @return string HTML, or '' when the section has no summary.
     */
    public function get_formatted_sectionsummary(): string {
        return self::filter_html((string)$this->get('sectionsummary'), $this->get_template_context());
    }

    /**
     * Run the filters over HTML that was cleaned when the preset was scanned.
     *
     * noclean is true because the cleaning has already happened, in local\baker, which formats with
     * the filters switched off. Between the two calls the text gets exactly what one format_text()
     * would give it - cleaned, then filtered - with the filtering done for the viewer.
     *
     * @param string $html Cleaned HTML.
     * @param \context $context The context to filter in.
     * @return string
     */
    protected static function filter_html(string $html, \context $context): string {
        if (trim($html) === '') {
            return '';
        }

        return format_text($html, FORMAT_HTML, ['context' => $context, 'noclean' => true]);
    }

    /**
     * The context the section's own text - its name and summary - is formatted in.
     *
     * The template course's, as for a section of any course. The system context stands in for a
     * template course that has since been deleted, whose rows can outlive it until the next rescan.
     *
     * @return \context
     */
    protected function get_template_context(): \context {
        return \context_course::instance((int)$this->get('templatecourseid'), IGNORE_MISSING)
            ?: \context_system::instance();
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
