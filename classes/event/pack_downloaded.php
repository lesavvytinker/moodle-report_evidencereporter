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
 * Event: a learner's evidence pack was downloaded.
 *
 * @property-read array $other {
 *      - int frameworkid: the competency framework
 *      - int includefeedback: 1 if feedback was included
 * }
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pack_downloaded extends \core\event\base {
    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventpackdownloaded', 'report_evidencereporter');
    }

    /**
     * Description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' downloaded the evidence pack of the user with id " .
            "'{$this->relateduserid}' for the competency framework with id '{$this->other['frameworkid']}'.";
    }

    /**
     * URL of the summary.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url(
            '/report/evidencereporter/print.php',
            ['userid' => $this->relateduserid, 'frameworkid' => $this->other['frameworkid']]
        );
    }

    /**
     * Validate the event data.
     *
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
        if (!isset($this->other['frameworkid'])) {
            throw new \coding_exception('The \'frameworkid\' value must be set in other.');
        }
    }

    /**
     * Mapping for backup/restore of 'other'.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return ['frameworkid' => \core\event\base::NOT_MAPPED, 'includefeedback' => \core\event\base::NOT_MAPPED];
    }
}
