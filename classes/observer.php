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

namespace report_evidencereporter;

/**
 * Event observers for Evidence Reporter.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * An activity was deleted: remove its assessment tool details.
     *
     * @param \core\event\course_module_deleted $event
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        local\tool_details::delete((int) $event->objectid);
        local\reasonable_adjustments::delete_for_activity((int) $event->objectid);
    }

    /**
     * Competency evidence was added: if it's a judgement, keep what it was made against as it is now.
     *
     * @param \core\event\competency_evidence_created $event
     */
    public static function competency_evidence_created(\core\event\competency_evidence_created $event): void {
        if (!in_array((int) ($event->other['action'] ?? -1), local\judgement_snapshot::ACTIONS)) {
            return;
        }
        local\judgement_snapshot::capture((int) $event->objectid);
    }
}
