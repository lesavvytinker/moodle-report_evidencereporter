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
 * Event: a judgement was swapped out of a validation sample.
 *
 * @property-read array $other {
 *      - int sampleid
 *      - int replacementid: the judgement drawn in its place (0 if none was left)
 * }
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sample_item_swapped extends \core\event\base {
    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'report_evidencereporter_sitem';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventsampleitemswapped', 'report_evidencereporter');
    }

    /**
     * Description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' swapped the judgement for the user with id '{$this->relateduserid}' " .
            "out of validation sample '{$this->other['sampleid']}'" . ($this->other['replacementid']
                ? "; judgement '{$this->other['replacementid']}' was drawn in its place." : '; none was left to draw.');
    }

    /**
     * URL of the sample.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/report/evidencereporter/sampling.php', ['id' => $this->other['sampleid']]);
    }

    /**
     * Mapping for backup/restore of the object.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'report_evidencereporter_sitem', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Mapping for backup/restore of 'other'.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return ['sampleid' => \core\event\base::NOT_MAPPED, 'replacementid' => \core\event\base::NOT_MAPPED];
    }
}
