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
 * Data generator for Evidence Reporter tests.
 *
 * @package    report_evidencereporter
 * @category   test
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_evidencereporter_generator extends component_generator_base {
    /**
     * Log a piece of competency evidence, the way core does when an activity is completed.
     *
     * Required: userid, competencyid. Optional: cmid (activity; defaults to system context),
     * action (default log), grade, actionuserid, note, url.
     *
     * @param array|stdClass $record
     * @return \core_competency\evidence
     */
    public function create_evidence($record): \core_competency\evidence {
        $record = (object) $record;
        if (!empty($record->cmid)) {
            $context = \context_module::instance($record->cmid);
            $cm = get_coursemodule_from_id('', $record->cmid, 0, false, MUST_EXIST);
            $url = $record->url ?? new moodle_url("/mod/{$cm->modname}/view.php", ['id' => $cm->id]);
            $desca = $cm->name;
        } else {
            $context = \context_system::instance();
            $url = $record->url ?? null;
            $desca = null;
        }
        $action = (int) ($record->action ?? \core_competency\evidence::ACTION_LOG);
        $identifier = !empty($record->cmid) ? 'evidence_coursemodulecompleted' : 'evidence_manualoverride';
        return \core_competency\api::add_evidence(
            $record->userid,
            $record->competencyid,
            $context,
            $action,
            $identifier,
            'core_competency',
            $desca,
            false,
            $url,
            isset($record->grade) && $record->grade !== '' ? (int) $record->grade : null,
            $record->actionuserid ?? null,
            $record->note ?? null
        );
    }
}
