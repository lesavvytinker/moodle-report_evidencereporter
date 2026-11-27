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

use report_evidencereporter\local\branding;
use report_evidencereporter\local\dates;
use report_evidencereporter\local\identity;
use report_evidencereporter\local\question_evidence;
use report_evidencereporter\local\reasonable_adjustments;
use report_evidencereporter\local\terms;
use report_evidencereporter\local\scope;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Printable evidence summary for one learner: a learning plan, their active plans, or a competency framework.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class summary_page implements renderable, templatable {
    /** @var scope What the summary covers. */
    protected scope $scope;

    /**
     * Constructor.
     *
     * @param stdClass $learner
     * @param scope|stdClass $scope What the summary covers (a framework record is short for that framework)
     * @param stdClass $summary From {@see \report_evidencereporter\local\summary::build()}
     * @param identity|null $identity Learner identity and redaction choices
     * @param array $frameworks From {@see \report_evidencereporter\local\summary::get_frameworks()}, for the scope choice
     */
    public function __construct(
        /** @var stdClass The learner. */
        protected stdClass $learner,
        scope|stdClass $scope,
        /** @var stdClass The summary data. */
        protected stdClass $summary,
        /** @var identity|null Learner identity and redaction choices. */
        protected ?identity $identity = null,
        /** @var array Frameworks the viewer may choose. */
        protected array $frameworks = []
    ) {
        $this->scope = $scope instanceof scope ? $scope : scope::for_framework((int) $learner->id, (int) $scope->id);
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

        // RA and LLN gaps are the assessors' to fix: flagged for staff, not for the learner.
        $showdeclflags = (int) $USER->id !== (int) $this->learner->id;
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
                'group' => $comp->group ?? '',
                'idnumber' => $comp->idnumber,
                'name' => $comp->name,
                'indent' => $comp->depth > 0,
                'currentrating' => $comp->currentrating,
                'proficient' => $comp->proficient,
                'judgementdate' => $comp->judgementdate ? dates::date((int) $comp->judgementdate) : '',
                'judgementby' => $comp->judgementby,
                'judgementrating' => $comp->judgementrating,
                'evidencecount' => $count,
                'noevidence' => $count === 0,
                'attemptcount' => $attemptcount,
                'declcomplete' => $showdeclflags && ($comp->declflag ?? '') === 'complete',
                'declmissing' => $showdeclflags && ($comp->declflag ?? '') === 'missing',
                'declmissingtext' => implode(', ', $comp->declmissing ?? []),
            ];
            $showattempts = branding::show('attempts');
            $records = $comp->records ?? [];
            $questions = $showattempts ? ($comp->questions ?? []) : [];
            if (branding::show('evidencedetail') && ($count || ($attemptcount && $showattempts) || $records || $questions)) {
                $assessmentlines = [];
                foreach ($comp->assessments ?? [] as $i => $assessment) {
                    $assessmentlines[] = ['activity' => $assessment->activity, 'text' => $assessment->text,
                        'none' => !$assessment->items, 'sep' => $i > 0, 'lln' => $assessment->lln ?? ''];
                }
                $details[] = [
                    'hasassessmentlines' => (bool) $assessmentlines,
                    'assessmentlines' => $assessmentlines,
                    'idnumber' => $comp->idnumber,
                    'name' => $comp->name,
                    'currentrating' => $comp->currentrating,
                    'hasevidence' => $count > 0,
                    'evidence' => array_map(fn($e) => (array) $e, $comp->evidence),
                    'hasattempts' => $attemptcount > 0 && $showattempts,
                    'attempts' => array_map(fn($a) => (array) $a, $comp->attempts),
                    'hasrecords' => (bool) $records,
                    'records' => array_map(fn($r) => (array) $r, $records),
                    'hasquestions' => (bool) $questions,
                    'questiongroups' => question_evidence::export($questions),
                ];
            }
        }

        // Reasonable adjustments by assessment ("N/A" when none), and where the viewer may record one for this learner.
        $assessments = [];
        $llnlines = [];
        foreach ($this->summary->assessments ?? [] as $assessment) {
            $llnlines[] = ['activity' => $assessment->activity, 'lln' => $assessment->lln ?? '',
                'notrecorded' => empty($assessment->llnrecorded), 'recordedtext' => $assessment->recordedtext ?? ''];
            if (!$assessment->items) {
                $assessments[] = ['activity' => $assessment->activity, 'none' => true];
                continue;
            }
            foreach ($assessment->items as $i => $item) {
                $assessments[] = (array) $item + ['activity' => $assessment->activity, 'first' => $i === 0,
                    'none' => false, 'reasontext' => (string) $item->reason];
            }
        }
        $addoptions = [];
        $competencyids = array_map(fn($comp) => (int) $comp->id, $this->summary->competencies);
        foreach (reasonable_adjustments::get_manageable_activities((int) $this->learner->id, $competencyids) as $cmid => $name) {
            $addoptions[] = ['cmid' => $cmid, 'name' => $name];
        }

        $identity = $this->identity;
        $flags = $identity->get_flags();
        $redactoptions = [];
        foreach ($flags as $flag => $value) {
            $redactoptions[] = ['name' => $flag, 'checked' => (bool) $value,
                'label' => get_string($flag === 'redactname' ? 'redactlearnername' : $flag, 'report_evidencereporter')];
        }
        return [
            'showadjustments' => $assessments || $addoptions,
            'hasadjustments' => (bool) $assessments,
            'hasdeclmissing' => $showdeclflags && !empty($this->summary->declmissing),
            'declmissingcount' => $showdeclflags ? count($this->summary->declmissing ?? []) : 0,
            'declmissing' => $showdeclflags ? array_map(fn($a) => ['activity' => $a->activity, 'url' => $a->declurl,
                'coursename' => $a->coursename], $this->summary->declmissing ?? []) : [],
            'hasdeclcomplete' => (bool) array_filter($rows, fn($r) => $r['declcomplete']),
            'hasllnlines' => (bool) $llnlines,
            'llnlines' => $llnlines,
            'adjustments' => $assessments,
            'adjustmentadd' => $addoptions ? [
                'url' => (new \moodle_url('/report/evidencereporter/adjustment.php'))->out(false),
                'options' => $addoptions,
                'returnto' => $this->get_return_url(),
            ] : null,
            'learnername' => $identity->name(),
            'learnerusername' => $identity->username(),
            'learneridnumber' => $identity->idnumber(),
            'learnerreference' => $identity->reference(),
            'redactionnote' => $identity->redaction_note(),
            'redactoptions' => $redactoptions,
            'formurl' => (new \moodle_url('/report/evidencereporter/print.php'))->out(false),
            'userid' => $this->learner->id,
            'scopeparams' => array_map(
                fn($name, $value) => ['name' => $name, 'value' => $value],
                array_keys($this->scope->get_params()),
                $this->scope->get_params()
            ),
            'scopeoptions' => array_map(fn($option) => [
                'value' => implode(':', array_values($option['params'])),
                'name' => $option['name'],
                'selected' => $option['selected'],
            ], $this->scope->get_options((int) $this->learner->id, $this->frameworks)),
            'scoperows' => array_map(
                fn($label, $value) => ['label' => $label, 'value' => $value],
                array_keys($this->scope->describe()),
                $this->scope->describe()
            ),
            'redactflags' => array_values(array_map(
                fn($flag) => ['name' => $flag],
                array_keys(array_filter($flags))
            )),
            'frozennote' => (bool) array_filter($this->summary->competencies, fn($comp) => !empty($comp->frozen)),
            'sitename' => format_string($SITE->fullname, true, ['context' => $syscontext]),
            'generatedon' => \report_evidencereporter\local\dates::datetime(time(), 'strftimedatetime'),
            'generatedby' => $identity->staff(fullname($USER)),
            'restricted' => $this->summary->restricted,
            'totalcompetencies' => count($this->summary->competencies),
            'withevidence' => $withevidence,
            'proficientcount' => $proficient,
            'overviewtext' => terms::get('overviewcounts', ['total' => count($this->summary->competencies),
                'evidence' => $withevidence, 'proficient' => $proficient]),
            'terms' => terms::all(),
            'downloadpacklabel' => get_string(
                'downloadpackterm',
                'report_evidencereporter',
                \core_text::strtolower(\core_text::substr(terms::get('evidencepack'), 0, 1))
                    . \core_text::substr(terms::get('evidencepack'), 1)
            ),
            'orgname' => branding::get_orgname(),
            'logourl' => branding::get_logo_url()?->out(false),
            'documentheader' => branding::get_header(),
            'documentfooter' => branding::get_footer(),
            'showsignoff' => branding::show('signoff'),
            'signoffheading' => branding::get_signoff_heading(),
            'signoffstatement' => branding::get_signoff_statement(),
            'rows' => $rows,
            'details' => $details,
            'hasdetails' => !empty($details),
            'packurl' => (new \moodle_url('/report/evidencereporter/pack.php', ['userid' => $this->learner->id,
                'includefeedback' => 1, 'sesskey' => sesskey()] + $this->scope->get_params() + $flags))->out(false),
        ];
    }

    /**
     * This page, to come back to after recording an adjustment.
     *
     * @return string Local URL
     */
    protected function get_return_url(): string {
        global $PAGE;
        if ($PAGE->has_set_url()) {
            return $PAGE->url->out_as_local_url(false);
        }
        return (new \moodle_url('/report/evidencereporter/print.php', ['userid' => $this->learner->id]
            + $this->scope->get_params()))->out_as_local_url(false);
    }
}
