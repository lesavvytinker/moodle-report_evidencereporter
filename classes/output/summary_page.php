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

namespace report_evidencereporter\output;

use report_evidencereporter\local\identity;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Printable evidence summary for one learner and one competency framework.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class summary_page implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param stdClass $learner
     * @param stdClass $framework
     * @param stdClass $summary From {@see \report_evidencereporter\local\summary::build()}
     * @param identity|null $identity Learner identity and redaction choices
     */
    public function __construct(
        /** @var stdClass The learner. */
        protected stdClass $learner,
        /** @var stdClass The competency framework. */
        protected stdClass $framework,
        /** @var stdClass The summary data. */
        protected stdClass $summary,
        /** @var identity|null Learner identity and redaction choices. */
        protected ?identity $identity = null
    ) {
        $this->identity = $identity ?? new identity($learner);
    }

    /**
     * Data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        global $USER, $SITE;
        $syscontext = \context_system::instance();
        $datefmt = get_string('strftimedate', 'core_langconfig');

        $rows = [];
        $details = [];
        $withevidence = 0;
        $proficient = 0;
        foreach ($this->summary->competencies as $comp) {
            $count = count($comp->evidence);
            $attemptcount = count($comp->attempts);
            $withevidence += $count ? 1 : 0;
            $proficient += $comp->proficient ? 1 : 0;
            $rows[] = [
                'idnumber' => $comp->idnumber,
                'name' => $comp->name,
                'indent' => $comp->depth > 0,
                'currentrating' => $comp->currentrating,
                'proficient' => $comp->proficient,
                'judgementdate' => $comp->judgementdate ? userdate($comp->judgementdate, $datefmt) : '',
                'judgementby' => $comp->judgementby,
                'judgementrating' => $comp->judgementrating,
                'evidencecount' => $count,
                'noevidence' => $count === 0,
                'attemptcount' => $attemptcount,
            ];
            if ($count || $attemptcount) {
                $details[] = [
                    'idnumber' => $comp->idnumber,
                    'name' => $comp->name,
                    'currentrating' => $comp->currentrating,
                    'hasevidence' => $count > 0,
                    'evidence' => array_map(fn($e) => (array) $e, $comp->evidence),
                    'hasattempts' => $attemptcount > 0,
                    'attempts' => array_map(fn($a) => (array) $a, $comp->attempts),
                ];
            }
        }

        $identity = $this->identity;
        $flags = $identity->get_flags();
        $redactoptions = [];
        foreach ($flags as $flag => $value) {
            $redactoptions[] = ['name' => $flag, 'checked' => (bool) $value,
                'label' => get_string($flag === 'redactname' ? 'redactlearnername' : $flag, 'report_evidencereporter')];
        }
        return [
            'learnername' => $identity->name(),
            'learnerusername' => $identity->username(),
            'learneridnumber' => $identity->idnumber(),
            'learnerreference' => $identity->reference(),
            'redactionnote' => $identity->redaction_note(),
            'redactoptions' => $redactoptions,
            'formurl' => (new \moodle_url('/report/evidencereporter/print.php'))->out(false),
            'userid' => $this->learner->id,
            'frameworkid' => $this->framework->id,
            'frameworkname' => format_string($this->framework->shortname, true, ['context' => $syscontext]),
            'frameworkidnumber' => s($this->framework->idnumber),
            'sitename' => format_string($SITE->fullname, true, ['context' => $syscontext]),
            'generatedon' => userdate(time(), get_string('strftimedatetime', 'core_langconfig')),
            'generatedby' => $identity->staff(fullname($USER)),
            'restricted' => $this->summary->restricted,
            'totalcompetencies' => count($this->summary->competencies),
            'withevidence' => $withevidence,
            'proficientcount' => $proficient,
            'rows' => $rows,
            'details' => $details,
            'hasdetails' => !empty($details),
            'packurl' => (new \moodle_url('/report/evidencereporter/pack.php', ['userid' => $this->learner->id,
                'frameworkid' => $this->framework->id, 'includefeedback' => 1, 'sesskey' => sesskey()] + $flags))->out(false),
        ];
    }
}
