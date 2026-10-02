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

use mod_edpreset\meta;
use mod_edpreset\preset;
use moodle_exception;

/**
 * The outcome of reviewing a preset: released to every teacher, or returned to the curator as a draft.
 *
 * Reviewers decide this on the preset chooser page, having added the preset to a course to see it
 * working. The decision is written to the curator's preset details, which are the source of truth,
 * and copied onto the preset at once, exactly as saving the settings form does, so it takes effect
 * without waiting for a rescan.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class review {
    /** @var string[] The statuses a review can end in. */
    public const OUTCOMES = [meta::STATUS_RELEASED, meta::STATUS_DRAFT];

    /**
     * Release a preset that is ready for review, or return it to its curator as a draft.
     *
     * Only a preset still in review can be decided on. The page that offered the buttons may have
     * been open a while, and a reviewer must not be able to withdraw a preset that someone has
     * released in the meantime by clicking a stale "Return as draft".
     *
     * @param preset $preset The preset.
     * @param string $status One of OUTCOMES.
     * @throws moodle_exception If the status is not an outcome, or the preset is no longer in review.
     */
    public static function decide(preset $preset, string $status): void {
        if (!in_array($status, self::OUTCOMES, true)) {
            throw new moodle_exception('invaliddata', 'error');
        }
        if (!$preset->is_in_review()) {
            throw new moodle_exception('notinreview', 'mod_edpreset');
        }

        $details = meta::get_for_cm((int)$preset->get('templatecmid'));
        if (!$details) {
            throw new moodle_exception('invalidpreset', 'mod_edpreset');
        }

        $details->set('status', $status);
        $details->update();

        $preset->set('status', $status);
        $preset->update();
    }
}
