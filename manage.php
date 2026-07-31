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

/**
 * Manage the preset activities derived from the template course.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use mod_edpreset\local\baker;
use mod_edpreset\output\manage_page;
use mod_edpreset\preset;

admin_externalpage_setup('modsettingedpresetmanage');

$action = optional_param('action', '', PARAM_ALPHA);
$manageurl = new moodle_url('/mod/edpreset/manage.php');

if ($action !== '') {
    require_sesskey();

    // Every action only queues work. Backups and test restores can each take seconds, so none of
    // them is run inside the request.
    switch ($action) {
        case 'rebuild':
            baker::queue_rebuild();
            redirect($manageurl, get_string('manage:rebuildqueued', 'mod_edpreset'));
            break;

        case 'clearcache':
            $count = baker::clear_cache();
            redirect($manageurl, get_string('manage:cachecleared', 'mod_edpreset', $count));
            break;

        case 'rebake':
            $presetid = required_param('preset', PARAM_INT);
            $preset = preset::get_record(['id' => $presetid]);
            if (!$preset) {
                throw new moodle_exception('invalidpreset', 'mod_edpreset');
            }
            baker::mark_stale((int)$preset->get('templatecmid'));
            redirect($manageurl, get_string('manage:rebakequeued', 'mod_edpreset', $preset->get('title')));
            break;

        default:
            throw new moodle_exception('invalidaction');
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managepresets', 'mod_edpreset'));
echo $OUTPUT->render_from_template('mod_edpreset/manage_page', (new manage_page())->export_for_template($OUTPUT));
echo $OUTPUT->footer();
