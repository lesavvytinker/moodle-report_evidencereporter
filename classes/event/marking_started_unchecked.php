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
 * Event: an assessor started marking a learner's assessment before its checks were done (assessment tool details, RA
 * and LLN).
 *
 * @property-read array $other {
 *      - string missing: what wasn't done
 * }
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class marking_started_unchecked extends \core\event\base {
    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventmarkingstartedunchecked', 'report_evidencereporter');
    }

    /**
     * Description of what happened.
     *
     * @return string
     */
    public function get_description() {
        $missing = (string) ($this->other['missing'] ?? '');
        return "The user with id '{$this->userid}' started marking the user with id '{$this->relateduserid}' in the " .
            "activity with course module id '{$this->contextinstanceid}' before these were done: {$missing}.";
    }

    /**
     * The learner's checklist.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/report/evidencereporter/beforemarking.php', ['cmid' => $this->contextinstanceid,
            'userid' => $this->relateduserid]);
    }

    /**
     * Validate the data.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
    }
}
