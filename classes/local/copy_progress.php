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

/**
 * A restore progress bar that prints the page header only if it ever needs to draw itself.
 *
 * core\progress\display_if_slow already emits nothing until its delay expires, which is what keeps
 * a quick copy silent. Printing the page header up front throws that away: moodle_page moves to
 * STATE_IN_BODY the moment the header is echoed, and from then on redirect() can no longer send a
 * Location header. It falls back to a scripted redirect and reports
 * "You should really redirect before you start page output" - which on a site with developer
 * debugging turned into exceptions is a hard failure rather than a notice.
 *
 * So the header is deferred to the first start_html() call, which only happens once the copy has
 * been running long enough to be worth showing a bar for.
 *
 * @package    mod_edpreset
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class copy_progress extends \core\progress\display_if_slow {
    /** @var string The page heading, shown with the header if it is ever printed. */
    protected $pageheading;

    /** @var bool Whether the page header has been printed. */
    protected $headerprinted = false;

    /**
     * Constructor.
     *
     * @param string $pageheading The heading to print alongside the page header.
     * @param string $heading Text above the progress bar itself, or '' for none.
     * @param int $delay Seconds a copy must run before anything is drawn. Passed through so that
     *                   tests can make the display overdue without sleeping for the default.
     */
    public function __construct(
        string $pageheading,
        string $heading = '',
        int $delay = self::DEFAULT_DISPLAY_DELAY
    ) {
        $this->pageheading = $pageheading;

        parent::__construct($heading, $delay);
    }

    /**
     * Print the page header, then the bar.
     *
     * Guarded rather than relying on being called once: the base class stops and restarts the bar
     * around each progress section, so this runs again for every section after the first.
     */
    public function start_html() {
        global $OUTPUT;

        if (!$this->headerprinted) {
            $this->headerprinted = true;
            echo $OUTPUT->header();
            echo $OUTPUT->heading($this->pageheading);
        }

        parent::start_html();
    }

    /**
     * Whether anything has been sent to the browser yet.
     *
     * @return bool
     */
    public function has_printed_header(): bool {
        return $this->headerprinted;
    }
}
