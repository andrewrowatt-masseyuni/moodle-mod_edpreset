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

namespace mod_edpreset\output;

use core\output\renderer_base;
use core\output\templatable;
use mod_edpreset\local\scrub\clear_dates;
use mod_edpreset\local\sandbox;
use mod_edpreset\local\template;
use mod_edpreset\preset;
use moodle_url;
use renderable;
use stdClass;

/**
 * The preset management page.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_page implements renderable, templatable {

    /**
     * Build the page context.
     *
     * @param renderer_base $output The renderer.
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        $data = new stdClass();

        $templatecourse = template::get();
        $data->enabled = template::is_enabled();
        $data->configured = template::is_configured();
        $data->settingsurl = (new moodle_url('/admin/settings.php', ['section' => 'modsettingedpreset']))->out(false);
        $data->manageurl = (new moodle_url('/mod/edpreset/manage.php'))->out(false);
        $data->sesskey = sesskey();

        if ($templatecourse) {
            $data->templatecoursename = format_string($templatecourse->fullname);
            $data->templatecourseurl =
                (new moodle_url('/course/view.php', ['id' => $templatecourse->id]))->out(false);
        }

        $sandboxcourse = sandbox::find();
        $data->sandboxshortname = sandbox::get_shortname();
        $data->sandboxexists = $sandboxcourse !== null;
        if ($sandboxcourse) {
            $data->sandboxurl = (new moodle_url('/course/view.php', ['id' => $sandboxcourse->id]))->out(false);
        }

        $data->presets = $this->export_presets();
        $data->haspresets = !empty($data->presets);
        $data->livecount = count(array_filter($data->presets, fn($p) => $p->islive));
        $data->totalcount = count($data->presets);
        $data->suggestions = $this->export_suggestions($data->presets);
        $data->hassuggestions = !empty($data->suggestions);

        return $data;
    }

    /**
     * One row per preset, newest problems first so failures are not buried.
     *
     * @return stdClass[]
     */
    protected function export_presets(): array {
        $rows = [];

        foreach (preset::get_records([], 'sortorder', 'ASC') as $preset) {
            $row = new stdClass();
            $row->id = (int)$preset->get('id');
            $row->title = $preset->get('title');
            $row->modname = $preset->get('modname');
            $row->modulename = get_string('modulename', 'mod_' . $preset->get('modname'));
            $row->category = $preset->get('category');
            $row->sectionnum = (int)$preset->get('sectionnum');
            $row->status = $preset->get('status');
            $row->statusdetail = $preset->get('statusdetail');
            $row->datescleared = $preset->get('datescleared');
            $row->scrubbed = (bool)$preset->get('scrubbed');

            // What the chooser actually gates on, which is not the same as the status field: a
            // re-bake in flight leaves the previous archive live.
            $row->islive = $preset->is_live();

            $row->statuslabel = get_string('status:' . $preset->get('status'), 'mod_edpreset');
            $row->statusclass = match ($preset->get('status')) {
                preset::STATUS_READY => 'success',
                preset::STATUS_FAILED => 'danger',
                default => 'secondary',
            };

            $row->backupsize = $preset->get('backupfilesize')
                ? display_size((int)$preset->get('backupfilesize'))
                : '';
            $row->validated = $preset->get('timevalidated')
                ? userdate((int)$preset->get('timevalidated'), get_string('strftimedatetimeshort'))
                : '';

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Date-looking fields the scrubber does not know about, for the modules actually in use.
     *
     * Advisory only. The same pattern matching that finds a genuine date also finds booleans and
     * durations, so these are shown for a human to judge rather than acted on.
     *
     * @param stdClass[] $presets The exported presets.
     * @return stdClass[]
     */
    protected function export_suggestions(array $presets): array {
        $seen = [];
        $suggestions = [];

        foreach ($presets as $preset) {
            if (isset($seen[$preset->modname])) {
                continue;
            }
            $seen[$preset->modname] = true;

            $fields = clear_dates::suggest_uncovered_fields($preset->modname);
            if ($fields) {
                $suggestion = new stdClass();
                $suggestion->modname = $preset->modname;
                $suggestion->fields = implode(', ', $fields);
                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;
    }
}
