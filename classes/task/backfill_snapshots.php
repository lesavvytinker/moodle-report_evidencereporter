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

namespace report_evidencereporter\task;

/**
 * Background task: fill in attempt snapshots from the gradebook's history, for marking done before Evidence Reporter
 * recorded them (queued once, on upgrade).
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backfill_snapshots extends \core\task\adhoc_task {
    /**
     * Run it.
     */
    public function execute() {
        $made = \report_evidencereporter\local\attempt_snapshots::backfill();
        mtrace('Evidence Reporter: ' . $made . ' attempt snapshot(s) filled in from the gradebook history.');
    }
}
