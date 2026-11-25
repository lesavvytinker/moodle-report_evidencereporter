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

namespace report_evidencereporter\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use report_evidencereporter\local\reasonable_adjustments;

/**
 * Form: one reasonable adjustment for a learner in an activity.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adjustment_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        foreach (['source' => PARAM_ALPHA, 'sourceid' => PARAM_INT, 'returnto' => PARAM_LOCALURL] as $name => $type) {
            $mform->addElement('hidden', $name);
            $mform->setType($name, $type);
        }
        $mform->addElement(
            'static',
            'learnername',
            \report_evidencereporter\local\terms::get('learner'),
            s($this->_customdata['learner'])
        );
        $mform->addElement(
            'static',
            'activityname',
            get_string('activity', 'report_evidencereporter'),
            s($this->_customdata['activity'])
        );
        $mform->addElement(
            'select',
            'adjtype',
            get_string('ratype', 'report_evidencereporter'),
            reasonable_adjustments::get_types()
        );
        $mform->addElement(
            'textarea',
            'details',
            get_string('radetails', 'report_evidencereporter'),
            ['rows' => 3, 'cols' => 60]
        );
        $mform->setType('details', PARAM_TEXT);
        $mform->addRule('details', null, 'required', null, 'client');
        $mform->addHelpButton('details', 'radetails', 'report_evidencereporter');
        $mform->addElement('textarea', 'reason', get_string('rareason', 'report_evidencereporter'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('reason', PARAM_TEXT);
        $mform->addHelpButton('reason', 'rareason', 'report_evidencereporter');
        $mform->addElement('text', 'approvedby', get_string('raapprovedby', 'report_evidencereporter'), ['size' => 40]);
        $mform->setType('approvedby', PARAM_TEXT);
        $mform->addRule('approvedby', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('approvedby', 'raapprovedby', 'report_evidencereporter');
        $this->add_action_buttons();
    }
}
