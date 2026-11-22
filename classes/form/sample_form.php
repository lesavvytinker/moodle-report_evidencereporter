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

use report_evidencereporter\local\sampling;
use report_evidencereporter\local\scope;
use report_evidencereporter\local\terms;

/**
 * Form: draw a validation sample.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sample_form extends \moodleform {
    /** Mark presets: name => [min, max]. */
    public const MARK_PRESETS = ['any' => [null, null], 'high' => [80, 100], 'middle' => [65, 79.999],
        'borderline' => [50, 64.999], 'low' => [0, 49.999], 'custom' => [null, null]];

    /**
     * Form definition.
     */
    protected function definition() {
        global $DB;
        $mform = $this->_form;
        $str = fn($id, $a = null) => get_string($id, 'report_evidencereporter', $a);
        $format = fn($name) => format_string($name, true, ['context' => \context_system::instance()]);

        $mform->addElement('text', 'name', $str('samplename'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addHelpButton('name', 'samplename', 'report_evidencereporter');

        // Where the judgements come from.
        $mform->addElement('header', 'wherehdr', $str('samplewhere'));
        $mform->addElement('select', 'sourcetype', $str('samplefrom'), ['course' => get_string('course'),
            'category' => $str('bulkcategory')]);
        $mform->addElement('course', 'courseid', get_string('course'), ['multiple' => false, 'includefrontpage' => false]);
        $mform->hideIf('courseid', 'sourcetype', 'neq', 'course');
        $mform->addElement(
            'select',
            'categoryid',
            $str('bulkcategory'),
            ['' => get_string('choosedots')] + \core_course_category::make_categories_list()
        );
        $mform->hideIf('categoryid', 'sourcetype', 'neq', 'category');
        $mform->addElement('advcheckbox', 'subcategories', '', $str('bulksubcategories'));
        $mform->setDefault('subcategories', 1);
        $mform->hideIf('subcategories', 'sourcetype', 'neq', 'category');

        $units = ['' => $str('sampleallunits')];
        foreach ($DB->get_records_menu('competency_framework', null, 'shortname', 'id, shortname') as $id => $name) {
            $units['framework-' . $id] = $format($name);
            foreach (scope::framework_units((int) $id) as $unit) {
                $units['unit-' . $unit->id] = '— ' . terms::get('unit') . ': ' . scope::unit_label($unit);
            }
        }
        $mform->addElement('select', 'covering', terms::get('units'), $units);
        $mform->setType('covering', PARAM_ALPHANUMEXT);

        // Which judgements.
        $mform->addElement('header', 'whichhdr', $str('samplewhich'));
        $mform->setExpanded('whichhdr');
        $mform->addElement('date_selector', 'from', $str('samplejudgedfrom'), ['optional' => true]);
        $mform->addElement('date_selector', 'to', $str('samplejudgedto'), ['optional' => true]);
        $mform->addElement('select', 'result', $str('sampleresult'), [
            'any' => $str('sampleresult_any'),
            'proficient' => $str('sampleresult_proficient'),
            'notproficient' => $str('sampleresult_notproficient'),
        ]);
        $presets = [];
        foreach (array_keys(self::MARK_PRESETS) as $preset) {
            $presets[$preset] = $str('samplemarks_' . $preset);
        }
        $mform->addElement('select', 'marks', $str('samplemark'), $presets);
        $mform->addHelpButton('marks', 'samplemark', 'report_evidencereporter');
        $range = [
            $mform->createElement('text', 'markmin', '', ['size' => 4]),
            $mform->createElement('static', 'markto', '', '% – '),
            $mform->createElement('text', 'markmax', '', ['size' => 4]),
            $mform->createElement('static', 'markpc', '', '%'),
        ];
        $mform->addGroup($range, 'markrange', $str('samplemarkrange'), '', false);
        $mform->setType('markmin', PARAM_RAW_TRIMMED);
        $mform->setType('markmax', PARAM_RAW_TRIMMED);
        $mform->hideIf('markrange', 'marks', 'neq', 'custom');

        // How many, and how spread.
        $mform->addElement('header', 'howhdr', $str('samplehowmany'));
        $mform->setExpanded('howhdr');
        $spreads = [];
        foreach (sampling::SPREADS as $spread) {
            $spreads[$spread] = $str('samplespread_' . $spread);
        }
        $mform->addElement('select', 'spread', $str('samplespread'), $spreads);
        $mform->setDefault('spread', 'assessor');
        $mform->addHelpButton('spread', 'samplespread', 'report_evidencereporter');
        $mform->addElement('text', 'size', $str('samplesize'), ['size' => 4]);
        $mform->setType('size', PARAM_INT);
        $mform->setDefault('size', 10);
        $mform->addElement('advcheckbox', 'pergroup', '', $str('samplepergroup'));
        $mform->hideIf('pergroup', 'spread', 'eq', 'none');

        $mform->addElement('text', 'seed', $str('sampledrawcodefield'), ['size' => 12]);
        $mform->setType('seed', PARAM_INT);
        $mform->addHelpButton('seed', 'sampledrawcodefield', 'report_evidencereporter');
        $mform->setAdvanced('seed');

        $this->add_action_buttons(false, $str('sampledraw'));
    }

    /**
     * Validation: a course or category, a size within limits, and a sensible mark range.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if ($data['sourcetype'] === 'course' && empty($data['courseid'])) {
            $errors['courseid'] = get_string('required');
        }
        if ($data['sourcetype'] === 'category' && empty($data['categoryid'])) {
            $errors['categoryid'] = get_string('required');
        }
        if ((int) $data['size'] < 1 || (int) $data['size'] > sampling::MAX_SIZE) {
            $errors['size'] = get_string('samplesizerange', 'report_evidencereporter', sampling::MAX_SIZE);
        }
        if (($data['marks'] ?? '') === 'custom') {
            foreach (['markmin', 'markmax'] as $field) {
                if ($data[$field] !== '' && (!is_numeric($data[$field]) || $data[$field] < 0 || $data[$field] > 100)) {
                    $errors['markrange'] = get_string('samplemarkinvalid', 'report_evidencereporter');
                }
            }
            if (
                !isset($errors['markrange']) && $data['markmin'] !== '' && $data['markmax'] !== ''
                    && (float) $data['markmin'] > (float) $data['markmax']
            ) {
                $errors['markrange'] = get_string('samplemarkinvalid', 'report_evidencereporter');
            }
        }
        return $errors;
    }

    /**
     * The submitted form as sampling filters.
     *
     * @param \stdClass $data
     * @return array
     */
    public static function to_filters(\stdClass $data): array {
        [$min, $max] = self::MARK_PRESETS[$data->marks] ?? [null, null];
        if ($data->marks === 'custom') {
            $min = $data->markmin === '' ? null : (float) $data->markmin;
            $max = $data->markmax === '' ? null : (float) $data->markmax;
        }
        return [
            'courseid' => $data->sourcetype === 'course' ? (int) $data->courseid : 0,
            'categoryid' => $data->sourcetype === 'category' ? (int) $data->categoryid : 0,
            'subcategories' => (int) !empty($data->subcategories),
            'frameworkid' => str_starts_with((string) $data->covering, 'framework-') ? (int) substr($data->covering, 10) : 0,
            'unitid' => str_starts_with((string) $data->covering, 'unit-') ? (int) substr($data->covering, 5) : 0,
            'from' => (int) ($data->from ?: 0),
            'to' => (int) ($data->to ?: 0),
            'result' => $data->result,
            'markmin' => $min,
            'markmax' => $max,
            'spread' => $data->spread,
            'size' => (int) $data->size,
            'pergroup' => (int) !empty($data->pergroup),
        ];
    }
}
