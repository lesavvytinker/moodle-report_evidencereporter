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

use report_evidencereporter\local\bulk;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Bulk download request form.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition(): void {
        global $DB;
        $mform = $this->_form;
        $syscontext = \context_system::instance();
        $format = fn($name) => format_string($name, true, ['context' => $syscontext]);

        $mform->addElement('select', 'sourcetype', get_string('bulksource', 'report_evidencereporter'), [
            'template' => get_string('bulktemplate', 'report_evidencereporter'),
            'cohort' => get_string('bulkcohort', 'report_evidencereporter'),
        ]);

        $templates = array_map($format, $DB->get_records_menu('competency_template', null, 'shortname', 'id, shortname'));
        $mform->addElement(
            'select',
            'templateid',
            get_string('bulktemplate', 'report_evidencereporter'),
            ['' => get_string('choosedots')] + $templates
        );
        $mform->hideIf('templateid', 'sourcetype', 'neq', 'template');

        $cohorts = array_map($format, $DB->get_records_menu('cohort', null, 'name', 'id, name'));
        $mform->addElement(
            'select',
            'cohortid',
            get_string('bulkcohort', 'report_evidencereporter'),
            ['' => get_string('choosedots')] + $cohorts
        );
        $mform->hideIf('cohortid', 'sourcetype', 'neq', 'cohort');

        // Covering: each learner's plans, a whole framework, or one unit (listed under its framework).
        $covering = ['plans' => get_string('bulkplans', 'report_evidencereporter')];
        foreach ($DB->get_records_menu('competency_framework', null, 'shortname', 'id, shortname') as $id => $name) {
            $covering['framework-' . $id] = $format($name);
            foreach (\report_evidencereporter\local\scope::framework_units((int) $id) as $unit) {
                $covering['unit-' . $unit->id] = '— ' . \report_evidencereporter\local\terms::get('unit') . ': '
                    . \report_evidencereporter\local\scope::unit_label($unit);
            }
        }
        $mform->addElement('select', 'covering', get_string('bulkcovering', 'report_evidencereporter'), $covering);
        $mform->setType('covering', PARAM_ALPHANUMEXT);
        $mform->setDefault('covering', 'plans');
        $mform->addHelpButton('covering', 'bulkcovering', 'report_evidencereporter');

        $mform->addElement(
            'radio',
            'mode',
            get_string('bulkmode', 'report_evidencereporter'),
            get_string('bulksummariesdesc', 'report_evidencereporter'),
            bulk::MODE_SUMMARIES
        );
        $mform->addElement('radio', 'mode', '', get_string('bulkpacksdesc', 'report_evidencereporter'), bulk::MODE_PACKS);
        $mform->setDefault('mode', bulk::MODE_SUMMARIES);

        $mform->addElement('advcheckbox', 'includefeedback', '', get_string('includefeedback', 'report_evidencereporter'));
        $mform->setDefault('includefeedback', 1);

        $redact = [];
        $options = ['redactname' => 'redactlearnername', 'redactusername' => 'redactusername', 'redactstaff' => 'redactstaff'];
        foreach ($options as $field => $string) {
            $redact[] = $mform->createElement('advcheckbox', $field, '', get_string($string, 'report_evidencereporter'));
        }
        $mform->addGroup($redact, 'redact', get_string('redactoptions', 'report_evidencereporter'), '<br>', false);
        $mform->addHelpButton('redact', 'redactoptions', 'report_evidencereporter');

        $this->add_action_buttons(false, get_string('bulkqueue', 'report_evidencereporter'));
    }

    /**
     * Validation: a source is chosen, and it has between 1 and the maximum number of learners.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $field = $data['sourcetype'] === 'cohort' ? 'cohortid' : 'templateid';
        if (empty($data[$field])) {
            $errors[$field] = get_string('required');
            return $errors;
        }
        $count = count(bulk::get_learners($data['sourcetype'], (int) $data[$field]));
        if ($count === 0) {
            $errors[$field] = get_string('bulknolearners', 'report_evidencereporter');
        } else if ($count > bulk::MAX_LEARNERS) {
            $errors[$field] = get_string(
                'bulktoomany',
                'report_evidencereporter',
                ['count' => $count, 'max' => bulk::MAX_LEARNERS]
            );
        }
        return $errors;
    }
}
