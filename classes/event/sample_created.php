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
 * Event: a validation sample was drawn.
 *
 * @property-read array $other {
 *      - int population: eligible judgements
 *      - int size: judgements drawn
 * }
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sample_created extends \core\event\base {
    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'report_evidencereporter_samp';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventsamplecreated', 'report_evidencereporter');
    }

    /**
     * Description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' drew validation sample '{$this->objectid}': " .
            "{$this->other['size']} of {$this->other['population']} eligible judgements.";
    }

    /**
     * URL of the sample.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/report/evidencereporter/sampling.php', ['id' => $this->objectid]);
    }

    /**
     * Mapping for backup/restore of the object.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'report_evidencereporter_samp', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Mapping for backup/restore of 'other'.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return ['population' => \core\event\base::NOT_MAPPED, 'size' => \core\event\base::NOT_MAPPED];
    }
}
