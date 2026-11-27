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
 * Scheduled task: re-check release holds in every course with graded activities, to catch changes no event announces
 * (gradebook weights, activity access, competency links).
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_holds extends \core\task\scheduled_task {
    /**
     * Name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('tasksyncholds', 'report_evidencereporter');
    }

    /**
     * Run it.
     */
    public function execute() {
        global $DB;
        if (
            !\report_evidencereporter\local\release_hold::enabled()
                && !$DB->record_exists(\report_evidencereporter\local\release_hold::TABLE, ['state' => 'held'])
        ) {
            return;
        }
        $courseids = $DB->get_fieldset_sql("SELECT DISTINCT courseid FROM {grade_items}
                                              WHERE itemtype = 'mod' AND courseid <> :site", ['site' => SITEID]);
        foreach ($courseids as $courseid) {
            try {
                \report_evidencereporter\local\release_hold::update_course((int) $courseid);
            } catch (\Throwable $e) {
                mtrace('Evidence Reporter: release holds in course ' . $courseid . ': ' . $e->getMessage());
            }
        }
    }
}
