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
 * Restores Evidence Reporter's settings for an activity (see backup_report_evidencereporter_plugin): its assessment
 * tool details, and the assessment a Feedback activity asks about (pointed at the restored copy of that assessment, or
 * dropped if it wasn't restored).
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_report_evidencereporter_plugin extends restore_plugin {
    /**
     * The paths to restore.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return [
            new restore_path_element('evidencereporter_tool', $this->get_pathfor('/tool')),
            new restore_path_element('evidencereporter_feedbackfor', $this->get_pathfor('/feedbackfor')),
        ];
    }

    /**
     * Assessment tool details: onto the restored activity (unless it already has some, when restoring into it).
     *
     * @param array|stdClass $data
     */
    public function process_evidencereporter_tool($data) {
        global $DB;
        $data = (object) $data;
        $cmid = (int) $this->task->get_moduleid();
        if ($DB->record_exists('report_evidencereporter_tool', ['cmid' => $cmid])) {
            return;
        }
        unset($data->id);
        $data->cmid = $cmid;
        $DB->insert_record('report_evidencereporter_tool', $data);
    }

    /**
     * The assessment a Feedback activity asks about: kept as the original's id until everything is restored.
     *
     * @param array|stdClass $data
     */
    public function process_evidencereporter_feedbackfor($data) {
        global $DB;
        $data = (object) $data;
        $cmid = (int) $this->task->get_moduleid();
        $DB->delete_records('report_evidencereporter_fbk', ['cmid' => $cmid]);
        $DB->insert_record('report_evidencereporter_fbk', ['cmid' => $cmid, 'assesscmid' => (int) $data->assesscmid]);
    }

    /**
     * Once the whole course is restored: point the survey at the restored copy of its assessment.
     */
    public function after_restore_module() {
        global $DB;
        $cmid = (int) $this->task->get_moduleid();
        $record = $DB->get_record('report_evidencereporter_fbk', ['cmid' => $cmid]);
        if (!$record) {
            return;
        }
        $restored = $this->get_mappingid('course_module', (int) $record->assesscmid);
        if ($restored) {
            $DB->set_field('report_evidencereporter_fbk', 'assesscmid', $restored, ['id' => $record->id]);
        } else if (
            (int) $this->task->get_courseid() !== (int) $DB->get_field(
                'course_modules',
                'course',
                ['id' => $record->assesscmid]
            )
        ) {
            // The assessment wasn't restored, and isn't in this course: forget it (Restrict access can still link it).
            $DB->delete_records('report_evidencereporter_fbk', ['id' => $record->id]);
        }
    }
}
