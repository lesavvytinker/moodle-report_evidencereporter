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
 * Event: a reasonable adjustment for a learner in an activity was recorded or changed.
 *
 * @property-read array $other {
 *      - string adjtype: the kind of adjustment
 * }
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adjustment_saved extends \core\event\base {
    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'report_evidencereporter_radj';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventadjustmentsaved', 'report_evidencereporter');
    }

    /**
     * Description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' recorded or changed a reasonable adjustment " .
            "('{$this->other['adjtype']}') for the user with id '{$this->relateduserid}' in the activity with course " .
            "module id '{$this->contextinstanceid}'.";
    }

    /**
     * The activity's reasonable adjustments page.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/report/evidencereporter/adjustments.php', ['cmid' => $this->contextinstanceid]);
    }

    /**
     * Validate the data.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
        if (!isset($this->other['adjtype'])) {
            throw new \coding_exception('The \'adjtype\' value must be set in other.');
        }
    }

    /**
     * No mapping needed for other.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }

    /**
     * Object id mapping.
     *
     * @return bool
     */
    public static function get_objectid_mapping() {
        return false;
    }
}
