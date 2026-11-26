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

namespace report_evidencereporter\local;

use MoodleQuickForm;
use stdClass;

/**
 * Assessment tool details for an activity: what an assessor guide needs that Moodle doesn't hold (tool version, review,
 * conditions, resources, assessor instructions, reasonable adjustment guidance). Edited in the activity's settings.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_details {
    /** @var string The table. */
    public const TABLE = 'report_evidencereporter_tool';

    /** @var string[] Text fields (plain text, several lines). */
    public const TEXTFIELDS = ['conditions', 'resources', 'instructions', 'adjustments'];

    /** @var string Prefix of the fields on the activity settings form. */
    private const PREFIX = 'ertool_';

    /**
     * Activity types that can have tool details: every type Evidence Reporter reads evidence from.
     *
     * @return string[]
     */
    public static function get_modnames(): array {
        return array_map(fn($source) => $source->get_modname(), sources::all());
    }

    /**
     * An activity's tool details, or empty ones.
     *
     * @param int $cmid
     * @return stdClass toolversion, reviewdate, reviewedbyid (chosen from staff), reviewedby (typed, for someone without an
     *                  account), conditions, resources, instructions, adjustments, timemodified
     */
    public static function get(int $cmid): stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['cmid' => $cmid]);
        if (!$record) {
            $record = (object) ['cmid' => $cmid, 'toolversion' => '', 'reviewdate' => 0, 'reviewedby' => '',
                'reviewedbyid' => 0, 'timemodified' => 0];
            foreach (self::TEXTFIELDS as $field) {
                $record->$field = '';
            }
        }
        foreach (self::TEXTFIELDS as $field) {
            $record->$field = (string) ($record->$field ?? '');
        }
        $record->reviewdate = (int) $record->reviewdate;
        $record->reviewedbyid = (int) ($record->reviewedbyid ?? 0);
        return $record;
    }

    /**
     * Who reviewed the tool: the staff member chosen, else the name typed.
     *
     * @param stdClass $details From {@see get()}
     * @return string
     */
    public static function reviewer(stdClass $details): string {
        return staff::name((int) ($details->reviewedbyid ?? 0), (string) ($details->reviewedby ?? ''));
    }

    /**
     * Does the activity have tool details saved?
     *
     * @param int $cmid
     * @return bool
     */
    public static function exists(int $cmid): bool {
        global $DB;
        return $cmid && $DB->record_exists(self::TABLE, ['cmid' => $cmid]);
    }

    /**
     * Example text for a text field, marked as an example, to start from.
     *
     * @param string $field One of TEXTFIELDS
     * @return string
     */
    public static function example(string $field): string {
        return get_string('toolexample', 'report_evidencereporter') . ' '
            . get_string('toolexample_' . $field, 'report_evidencereporter');
    }

    /**
     * Is this text still (or still marked as) example text?
     *
     * @param string $text
     * @return bool
     */
    public static function is_example(string $text): bool {
        return str_starts_with(trim($text), get_string('toolexample', 'report_evidencereporter'));
    }

    /**
     * Save an activity's tool details. Empty details remove the record.
     *
     * @param int $cmid
     * @param stdClass $data toolversion, reviewdate, reviewedbyid, reviewedby and the text fields
     */
    public static function save(int $cmid, stdClass $data): void {
        global $DB;
        $record = (object) [
            'cmid' => $cmid,
            'toolversion' => \core_text::substr(trim((string) ($data->toolversion ?? '')), 0, 100),
            'reviewdate' => (int) ($data->reviewdate ?? 0),
            'reviewedby' => \core_text::substr(trim((string) ($data->reviewedby ?? '')), 0, 255),
            'reviewedbyid' => (int) ($data->reviewedbyid ?? 0),
            'timemodified' => time(),
        ];
        $empty = $record->toolversion === '' && !$record->reviewdate && $record->reviewedby === '' && !$record->reviewedbyid;
        foreach (self::TEXTFIELDS as $field) {
            $record->$field = trim((string) ($data->$field ?? ''));
            $empty = $empty && $record->$field === '';
        }
        $existing = $DB->get_record(self::TABLE, ['cmid' => $cmid], 'id');
        if ($empty) {
            if ($existing) {
                $DB->delete_records(self::TABLE, ['id' => $existing->id]);
            }
            return;
        }
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record(self::TABLE, $record);
        } else {
            $DB->insert_record(self::TABLE, $record);
        }
    }

    /**
     * Remove an activity's tool details (when the activity is deleted).
     *
     * @param int $cmid
     */
    public static function delete(int $cmid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['cmid' => $cmid]);
    }

    /**
     * Add the "Assessment tool details" section to an activity's settings form.
     *
     * @param MoodleQuickForm $mform
     * @param string $modname
     * @param int $cmid 0 for a new activity
     * @param \context|null $context Where reviewers are listed from (the course, for a new activity)
     */
    public static function add_form_elements(
        MoodleQuickForm $mform,
        string $modname,
        int $cmid,
        ?\context $context = null
    ): void {
        if (!in_array($modname, self::get_modnames())) {
            return;
        }
        $p = self::PREFIX;
        $mform->addElement('header', $p . 'header', get_string('tooldetails', 'report_evidencereporter'));
        $mform->addElement('static', $p . 'intro', '', get_string('tooldetails_intro', 'report_evidencereporter'));

        $mform->addElement('text', $p . 'toolversion', get_string('toolversion', 'report_evidencereporter'), ['size' => 20]);
        $mform->setType($p . 'toolversion', PARAM_TEXT);
        $mform->addRule($p . 'toolversion', get_string('maximumchars', '', 100), 'maxlength', 100, 'client');
        $mform->addElement(
            'date_selector',
            $p . 'reviewdate',
            get_string('toolreviewdate', 'report_evidencereporter'),
            ['optional' => true]
        );
        $saved = self::exists($cmid) ? self::get($cmid) : null;
        staff::add_form_elements(
            $mform,
            $p . 'reviewedby',
            'toolreviewedby',
            'toolreviewedbyother',
            $context ?? ($cmid ? \context_module::instance($cmid) : \context_system::instance()),
            $saved ? $saved->reviewedbyid : 0
        );
        foreach (self::TEXTFIELDS as $field) {
            $mform->addElement(
                'textarea',
                $p . $field,
                get_string('tool' . $field, 'report_evidencereporter'),
                ['rows' => 3, 'cols' => 60]
            );
            $mform->setType($p . $field, PARAM_TEXT);
            $mform->addHelpButton($p . $field, 'tool' . $field, 'report_evidencereporter');
        }

        if ($saved) {
            $details = $saved;
            $mform->setDefault($p . 'toolversion', $details->toolversion);
            $mform->setDefault($p . 'reviewdate', $details->reviewdate);
            $mform->setDefault($p . 'reviewedbyid', $details->reviewedbyid);
            $mform->setDefault($p . 'reviewedby', $details->reviewedby);
            foreach (self::TEXTFIELDS as $field) {
                $mform->setDefault($p . $field, $details->$field);
            }
        } else if (assessor_guide::supports($modname)) {
            // Nothing saved yet: start from example text, clearly marked, so there's something to edit.
            foreach (self::TEXTFIELDS as $field) {
                $mform->setDefault($p . $field, self::example($field));
            }
            $mform->insertElementBefore(
                $mform->createElement('static', $p . 'examples', '', \html_writer::div(
                    get_string('tooldetails_examples', 'report_evidencereporter'),
                    'alert alert-info'
                )),
                $p . 'toolversion'
            );
        }

        if ($cmid && assessor_guide::supports($modname)) {
            [, $cm] = get_course_and_cm_from_cmid($cmid);
            if (assessor_guide::can_view($cm)) {
                global $OUTPUT;
                $url = new \moodle_url('/report/evidencereporter/assessorguide.php', ['cmid' => $cmid]);
                $button = \html_writer::link(
                    $url,
                    $OUTPUT->pix_icon('t/print', '') . get_string('printassessorguide', 'report_evidencereporter'),
                    ['class' => 'btn btn-secondary', 'target' => '_blank']
                );
                $mform->insertElementBefore(
                    $mform->createElement('static', $p . 'guide', '', $button . \html_writer::div(
                        get_string('printassessorguide_help', 'report_evidencereporter'),
                        'form-text text-muted'
                    )),
                    $mform->elementExists($p . 'examples') ? $p . 'examples' : $p . 'toolversion'
                );
            }
        }
    }

    /**
     * Save the section's values after the activity's settings form is submitted.
     *
     * @param stdClass $data Form data (with coursemodule and modulename)
     */
    public static function save_from_form(stdClass $data): void {
        $p = self::PREFIX;
        if (
            empty($data->coursemodule) || !in_array($data->modulename ?? '', self::get_modnames())
                || !property_exists($data, $p . 'toolversion')
        ) {
            return;
        }
        $values = new stdClass();
        foreach (array_merge(['toolversion', 'reviewdate', 'reviewedby', 'reviewedbyid'], self::TEXTFIELDS) as $field) {
            $values->$field = $data->{$p . $field} ?? '';
        }
        // Only staff (or the reviewer already saved) can be chosen.
        $current = self::get((int) $data->coursemodule)->reviewedbyid;
        if (!staff::is_valid_choice((int) $values->reviewedbyid, \context_module::instance($data->coursemodule), $current)) {
            $values->reviewedbyid = $current;
        }
        self::save((int) $data->coursemodule, $values);
    }

    /**
     * Validate the section on the activity's settings form.
     *
     * @param array $data Submitted data
     * @return string[] errors
     */
    public static function validate_form(array $data): array {
        return staff::validate($data, self::PREFIX . 'reviewedby');
    }
}
