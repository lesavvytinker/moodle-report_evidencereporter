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
 * Event: a learner's printable evidence summary was viewed.
 *
 * @property-read array $other {
 *      - string scope: framework, plan, plans (active plans) or unit
 *      - int frameworkid: the competency framework (0 for plans)
 *      - int planid: the learning plan (for a single plan)
 *      - int competencyid: the unit (for a unit)
 * }
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class summary_viewed extends \core\event\base {
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
        return get_string('eventsummaryviewed', 'report_evidencereporter');
    }

    /**
     * Description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' viewed the competency evidence summary of the user with id " .
            "'{$this->relateduserid}' for " . $this->describe_scope() . ".";
    }

    /**
     * URL of the summary.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url(
            '/report/evidencereporter/print.php',
            ['userid' => $this->relateduserid] + array_filter([
                'scope' => $this->other['scope'] ?? '',
                'planid' => $this->other['planid'] ?? 0,
                'frameworkid' => $this->other['frameworkid'],
                'competencyid' => $this->other['competencyid'] ?? 0,
            ])
        );
    }

    /**
     * What the summary covered, for the description.
     *
     * @return string
     */
    protected function describe_scope(): string {
        if (!empty($this->other['planid'])) {
            return "the learning plan with id '{$this->other['planid']}'";
        }
        if (!empty($this->other['competencyid'])) {
            return "the unit (competency) with id '{$this->other['competencyid']}'";
        }
        if (($this->other['scope'] ?? '') === 'plans') {
            return 'their active learning plans';
        }
        return "the competency framework with id '{$this->other['frameworkid']}'";
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
        return ['planid' => \core\event\base::NOT_MAPPED, 'frameworkid' => \core\event\base::NOT_MAPPED];
    }
}
