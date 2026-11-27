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

namespace report_evidencereporter\event;

/**
 * Event: an assessor let the editing teachers know an activity's assessment tool details are missing.
 *
 * @property-read array $other {
 *      - int recipients: how many were told
 * }
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_gap_reported extends \core\event\base {
    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'report_evidencereporter_ntf';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventtoolgapreported', 'report_evidencereporter');
    }

    /**
     * Description of what happened.
     *
     * @return string
     */
    public function get_description() {
        $count = (int) ($this->other['recipients'] ?? 0);
        return "The user with id '{$this->userid}' told {$count} editing teacher(s) that the assessment tool details of " .
            "the activity with course module id '{$this->contextinstanceid}' are missing.";
    }

    /**
     * The activity's settings.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/course/modedit.php', ['update' => $this->contextinstanceid]);
    }
}
