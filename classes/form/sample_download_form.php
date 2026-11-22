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

use report_evidencereporter\local\bulk;

/**
 * Form: build the download for a sample (summaries or packs, feedback, redaction), as for bulk downloads.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sample_download_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action', 'download');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement(
            'radio',
            'mode',
            get_string('bulkmode', 'report_evidencereporter'),
            get_string('bulksummariesdesc', 'report_evidencereporter'),
            bulk::MODE_SUMMARIES
        );
        $mform->addElement('radio', 'mode', '', get_string('bulkpacksdesc', 'report_evidencereporter'), bulk::MODE_PACKS);
        $mform->setDefault('mode', bulk::MODE_PACKS);
        $mform->addElement('advcheckbox', 'includefeedback', '', get_string('includefeedback', 'report_evidencereporter'));
        $mform->setDefault('includefeedback', 1);
        $redact = [];
        foreach (
            ['redactname' => 'redactlearnername', 'redactusername' => 'redactusername',
                'redactstaff' => 'redactstaff'] as $field => $string
        ) {
            $redact[] = $mform->createElement('advcheckbox', $field, '', get_string($string, 'report_evidencereporter'));
        }
        $mform->addGroup($redact, 'redact', get_string('redactoptions', 'report_evidencereporter'), '<br>', false);
        $this->add_action_buttons(false, get_string('bulkqueue', 'report_evidencereporter'));
    }
}
