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
 * Event: a bulk evidence download was created.
 *
 * @property-read array $other {
 *      - string sourcetype: 'template', 'cohort', 'course' or 'category'
 *      - int sourceid
 *      - int competencyid: one unit, if chosen
 *      - int frameworkid
 *      - string mode: 'summaries' or 'packs'
 *      - int learners: learners included
 * }
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_created extends \core\event\base {
    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventbulkcreated', 'report_evidencereporter');
    }

    /**
     * Description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' created a bulk evidence download ({$this->other['mode']}) of " .
            "{$this->other['learners']} learners from the {$this->other['sourcetype']} with id '{$this->other['sourceid']}' " .
            (!empty($this->other['competencyid'])
                ? "for the unit (competency) with id '{$this->other['competencyid']}'."
                : "for the competency framework with id '{$this->other['frameworkid']}'.");
    }

    /**
     * URL of the bulk downloads page.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/report/evidencereporter/bulk.php');
    }

    /**
     * Validate the event data.
     *
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['sourcetype', 'sourceid', 'frameworkid', 'mode', 'learners'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
    }

    /**
     * Mapping for backup/restore of 'other'.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return [
            'sourcetype' => \core\event\base::NOT_MAPPED,
            'sourceid' => \core\event\base::NOT_MAPPED,
            'frameworkid' => \core\event\base::NOT_MAPPED,
            'competencyid' => \core\event\base::NOT_MAPPED,
            'mode' => \core\event\base::NOT_MAPPED,
            'learners' => \core\event\base::NOT_MAPPED,
        ];
    }
}
