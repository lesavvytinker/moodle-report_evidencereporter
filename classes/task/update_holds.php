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
 * Background task: re-check a course's release holds after its learners change (custom data: courseid).
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_holds extends \core\task\adhoc_task {
    /**
     * Run it.
     */
    public function execute() {
        global $DB;
        $courseid = (int) ($this->get_custom_data()->courseid ?? 0);
        if ($courseid && $DB->record_exists('course', ['id' => $courseid])) {
            \report_evidencereporter\local\release_hold::update_course($courseid);
        }
    }

    /**
     * Queue it for a course (once).
     *
     * @param int $courseid
     */
    public static function queue(int $courseid): void {
        $task = new self();
        $task->set_custom_data(['courseid' => $courseid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }
}
