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

use cm_info;
use stdClass;

/**
 * The blank assessor guide for a quiz: the assessment tool as an assessor uses it, with no learner in it.
 *
 * Built from the quiz itself (settings, questions, model answers, competency links) and its assessment tool details
 * ({@see tool_details}). For each question: the question, its model answer, Satisfactory / Not satisfactory boxes, a
 * specific feedback box and, in small print, the competencies it's mapped to. Then a mapping matrix, an outcome
 * summary across attempts, general feedback, sign-off, and review notes listing anything missing.
 *
 * Used for review before use and validation, and included in evidence packs as the tool the learner was assessed with.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assessor_guide {
    /** @var int Most questions listed from one random pool. */
    public const MAXPOOL = 50;

    /** @var string|null The HTML of the last guide built. */
    private ?string $html = null;

    /** @var string[] Notes for the reviewer: things missing from the tool. */
    private array $reviewnotes = [];

    /** @var array competencyid => string[] question numbers mapped to it */
    private array $matrix = [];

    /**
     * Constructor.
     *
     * @param cm_info $cm A quiz
     */
    public function __construct(
        /** @var cm_info The activity. */
        private cm_info $cm
    ) {
        if (!self::supports($cm->modname)) {
            throw new \coding_exception('Assessor guides are only available for quizzes.');
        }
    }

    /**
     * Can an assessor guide be built for this kind of activity?
     *
     * @param string $modname
     * @return bool
     */
    public static function supports(string $modname): bool {
        return $modname === 'quiz';
    }

    /**
     * May the current user download the guide?
     *
     * @param cm_info $cm
     * @return bool
     */
    public static function can_view(cm_info $cm): bool {
        return self::supports($cm->modname)
            && has_capability('report/evidencereporter:assessorguide', \context_module::instance($cm->id));
    }

    /**
     * File name of the PDF.
     *
     * @return string
     */
    public function get_filename(): string {
        $name = get_string('assessorguidefile', 'report_evidencereporter', [
            'activity' => $this->cm->get_formatted_name(),
            'version' => tool_details::get($this->cm->id)->toolversion,
        ]);
        return clean_filename(trim(preg_replace('/\s+/', ' ', $name))) . '.pdf';
    }

    /**
     * The guide as a PDF.
     *
     * @return string PDF bytes
     */
    public function build_pdf(): string {
        $html = $this->build_html();
        return evidence_pack::render_pdf(
            get_string('assessorguide', 'report_evidencereporter'),
            evidence_pack::branded_html($html)
        );
    }

    /**
     * Notes for the reviewer from the last build: what's missing from the tool.
     *
     * @return string[]
     */
    public function get_review_notes(): array {
        return $this->reviewnotes;
    }

    /**
     * The guide as HTML for the PDF renderer.
     *
     * @return string
     */
    public function build_html(): string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/questionlib.php');
        \core_php_time_limit::raise(120);

        $this->reviewnotes = [];
        $this->matrix = [];
        $cm = $this->cm;
        $context = \context_module::instance($cm->id);
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $course = get_course($cm->course);
        $details = tool_details::get($cm->id);
        $sat = terms::get('satisfactory');
        $nys = terms::get('notsatisfactory');

        // Competencies: those the quiz is linked to, and those its questions are mapped to.
        $quizcompetencies = array_map('intval', $DB->get_fieldset_select(
            'competency_modulecomp',
            'competencyid',
            'cmid = ?',
            [$cm->id]
        ));

        $html = '<h1>' . s(get_string('assessorguide', 'report_evidencereporter')) . ': '
            . s($cm->get_formatted_name()) . '</h1>'
            . '<p><small>' . s(get_string(
                'assessorguide_blank',
                'report_evidencereporter',
                userdate(time(), get_string('strftimedate', 'langconfig'))
            )) . '</small></p>';

        // Tool details.
        $html .= '<h2>' . s(get_string('tooldetails', 'report_evidencereporter')) . '</h2>'
            . '<table border="1" cellpadding="4" width="100%">'
            . $this->row(get_string('course'), s(format_string(
                $course->fullname,
                true,
                ['context' => \context_course::instance($course->id)]
            )))
            . $this->row(get_string('activity'), s($cm->get_formatted_name()))
            . $this->row(get_string('toolversion', 'report_evidencereporter'), $this->value($details->toolversion))
            . $this->row(get_string('toolreviewdate', 'report_evidencereporter'), $details->reviewdate
                ? s(userdate($details->reviewdate, get_string('strftimedate', 'langconfig'))) : $this->notset())
            . $this->row(get_string('toolreviewedby', 'report_evidencereporter'), $this->value($details->reviewedby))
            . '</table>';
        if ($details->toolversion === '') {
            $this->reviewnotes[] = get_string('reviewnote_noversion', 'report_evidencereporter');
        }
        if (!$details->reviewdate) {
            $this->reviewnotes[] = get_string('reviewnote_noreview', 'report_evidencereporter');
        }

        // What's assessed: the units the competencies belong to.
        $units = $this->units(array_merge($quizcompetencies, $this->question_competencyids($quiz, $context)));
        $html .= '<h2>' . s(terms::get('units')) . '</h2>';
        if ($units) {
            $html .= '<ul>' . implode('', array_map(fn($unit) => '<li>' . s($unit) . '</li>', $units)) . '</ul>';
        } else {
            $html .= '<p>' . $this->notset() . '</p>';
            $this->reviewnotes[] = get_string('reviewnote_nocompetencies', 'report_evidencereporter');
        }

        // Blank learner and assessment details.
        $html .= '<h2>' . s(get_string('assessmentdetails', 'report_evidencereporter')) . '</h2>'
            . '<table border="1" cellpadding="6" width="100%">';
        foreach (['blank_learnername', 'blank_learnerid', 'blank_assessorname', 'blank_dates', 'blank_location'] as $key) {
            $html .= $this->row(get_string($key, 'report_evidencereporter'), '');
        }
        $html .= '</table>';

        // Instructions and conditions.
        $html .= '<h2>' . s(get_string('assessorinstructions', 'report_evidencereporter')) . '</h2>'
            . '<table border="1" cellpadding="4" width="100%">'
            . $this->row(get_string('toolinstructions', 'report_evidencereporter'), $this->text($details->instructions))
            . $this->row(
                get_string('learnerinstructions', 'report_evidencereporter'),
                trim(strip_tags((string) $quiz->intro)) !== '' ? $this->clean(format_module_intro('quiz', $quiz, $cm->id, false))
                : $this->notset()
            )
            . $this->row(get_string('toolconditions', 'report_evidencereporter'), $this->text($details->conditions))
            . $this->row(get_string('toolresources', 'report_evidencereporter'), $this->text($details->resources))
            . $this->row(get_string('timelimit', 'quiz'), $quiz->timelimit ? s(format_time($quiz->timelimit))
                : s(get_string('none')))
            . $this->row(get_string('attemptsallowed', 'quiz'), $quiz->attempts ? (int) $quiz->attempts
                : s(get_string('unlimited')))
            . $this->row(get_string('quizopen', 'quiz'), $quiz->timeopen
                ? s(userdate($quiz->timeopen)) : s(get_string('none')))
            . $this->row(get_string('quizclose', 'quiz'), $quiz->timeclose
                ? s(userdate($quiz->timeclose)) : s(get_string('none')))
            . $this->row(get_string('gradepass', 'grades'), $this->pass_mark($quiz))
            . '</table>';
        if ($details->instructions === '') {
            $this->reviewnotes[] = get_string('reviewnote_noinstructions', 'report_evidencereporter');
        }
        if ($details->conditions === '') {
            $this->reviewnotes[] = get_string('reviewnote_noconditions', 'report_evidencereporter');
        }

        // Reasonable adjustment.
        $html .= '<h2>' . s(get_string('tooladjustments', 'report_evidencereporter')) . '</h2>'
            . '<p>' . $this->text($details->adjustments) . '</p>'
            . '<table border="1" cellpadding="6" width="100%"><tr><td><strong>'
            . s(get_string('adjustmentsmade', 'report_evidencereporter')) . '</strong><br><br><br><br></td></tr></table>';
        if ($details->adjustments === '') {
            $this->reviewnotes[] = get_string('reviewnote_noadjustments', 'report_evidencereporter');
        }
        foreach (tool_details::TEXTFIELDS as $field) {
            if (tool_details::is_example($details->$field)) {
                $this->reviewnotes[] = get_string(
                    'reviewnote_example',
                    'report_evidencereporter',
                    get_string('tool' . $field, 'report_evidencereporter')
                );
            }
        }

        // Questions.
        $html .= '<h2>' . s(get_string('questionsandanswers', 'report_evidencereporter')) . '</h2>';
        $structure = \mod_quiz\quiz_settings::create((int) $quiz->id)->get_structure();
        $slots = $structure->get_slots();
        if (!$slots) {
            $html .= '<p>' . s(get_string('noquestions', 'quiz')) . '</p>';
        }
        foreach ($slots as $slot) {
            $number = $structure->get_displayed_number_for_slot($slot->slot);
            // Random slots: qtype 'random' up to Moodle 5.1, no qtype and ->random from 5.2; both have a filter.
            if (!empty($slot->filtercondition) || !empty($slot->random) || $slot->qtype === 'random') {
                $html .= $this->random_html($slot, (string) $number, $quizcompetencies, $sat, $nys);
                continue;
            }
            if ($slot->qtype === 'missingtype' || !is_numeric($slot->questionid)) {
                continue;
            }
            $html .= $this->question_html(
                (int) $slot->questionid,
                (string) $number,
                (float) $slot->maxmark,
                $quizcompetencies,
                $sat,
                $nys
            );
        }

        // Mapping matrix.
        $html .= $this->matrix_html($quizcompetencies);

        // Outcome summary, general feedback and sign-off.
        $attempts = max(1, min(3, (int) $quiz->attempts ?: 3));
        $html .= '<h2>' . s(get_string('outcomesummary', 'report_evidencereporter')) . '</h2>'
            . '<table border="1" cellpadding="6" width="100%" nobr="true"><tr style="background-color:#e9ecef;font-weight:bold;">'
            . '<td width="20%">' . s(get_string('attemptnumber', 'report_evidencereporter')) . '</td>'
            . '<td width="20%">' . s(get_string('date')) . '</td>'
            . '<td width="35%">' . s(get_string('quizresult', 'report_evidencereporter')) . '</td>'
            . '<td width="25%">' . s(get_string('assessorinitials', 'report_evidencereporter')) . '</td></tr>';
        for ($i = 1; $i <= $attempts; $i++) {
            $html .= '<tr><td width="20%">' . $i . '</td><td width="20%"></td><td width="35%">'
                . $this->boxes($sat, $nys) . '</td><td width="25%"></td></tr>';
        }
        $html .= '</table>'
            . '<h2>' . s(get_string('generalfeedback', 'report_evidencereporter')) . '</h2>'
            . '<table border="1" cellpadding="6" width="100%"><tr><td><br><br><br><br><br><br></td></tr></table>'
            . '<h2>' . s(get_string('signoff', 'report_evidencereporter')) . '</h2>'
            . '<table border="1" cellpadding="6" width="100%" nobr="true">'
            . '<tr><td colspan="3" width="100%"><strong>' . s(get_string('taskoutcome', 'report_evidencereporter'))
            . '</strong>: ' . $this->boxes($sat, $nys) . '</td></tr>'
            . $this->signature_row(get_string('assessordeclaration', 'report_evidencereporter'))
            . $this->signature_row(get_string('learnerdeclaration', 'report_evidencereporter'))
            . '</table>';

        if ($this->reviewnotes) {
            $html .= '<h2>' . s(get_string('reviewnotes', 'report_evidencereporter')) . '</h2>'
                . '<p><small>' . s(get_string('reviewnotes_intro', 'report_evidencereporter')) . '</small></p><ul>'
                . implode('', array_map(fn($note) => '<li>' . s($note) . '</li>', $this->reviewnotes)) . '</ul>';
        }
        return $this->html = $html;
    }

    /**
     * One question: text, model answer, judgement boxes, feedback box, competencies in small print.
     *
     * @param int $questionid
     * @param string $number
     * @param float $maxmark
     * @param int[] $quizcompetencies
     * @param string $sat
     * @param string $nys
     * @param bool $inpool Part of a random pool (numbered within it)
     * @return string
     */
    private function question_html(
        int $questionid,
        string $number,
        float $maxmark,
        array $quizcompetencies,
        string $sat,
        string $nys,
        bool $inpool = false
    ): string {
        try {
            $question = \question_bank::load_question($questionid);
        } catch (\Throwable $e) {
            return '';
        }
        $qtype = $question->get_type_name();
        $qcontext = \context::instance_by_id($question->contextid);
        $name = format_string($question->name, true, ['context' => $qcontext]);
        $heading = $qtype === 'description' ? $name : get_string('questionx', 'report_evidencereporter', $number) . ' · '
            . get_string('marksx', 'report_evidencereporter', format_float($maxmark, 2)) . ' · ' . $name;
        $text = $this->question_text($question, $qcontext);

        $out = '<table border="1" cellpadding="4" width="100%" nobr="true">'
            . '<tr style="background-color:#e9ecef;"><td colspan="2" width="100%"><strong>' . s($heading)
            . '</strong></td></tr>'
            . '<tr><td colspan="2" width="100%">' . $text . '</td></tr>';
        if ($qtype === 'description') {
            return $out . '</table><p></p>';
        }

        [$answer, $missing] = $this->model_answer($question, $qcontext);
        if ($missing) {
            $this->reviewnotes[] = get_string('reviewnote_nomodelanswer', 'report_evidencereporter', $number);
        }
        $out .= '<tr><td colspan="2" width="100%" style="background-color:#f3f8f3;"><strong>'
            . s(get_string('modelanswer', 'report_evidencereporter')) . '</strong><br>' . $answer . '</td></tr>';
        $general = trim(strip_tags((string) $question->generalfeedback)) !== ''
            ? $this->clean(format_text(
                $question->generalfeedback,
                $question->generalfeedbackformat,
                ['context' => $qcontext, 'filter' => false]
            )) : '';
        if ($general !== '') {
            $out .= '<tr><td colspan="2" width="100%"><strong>' . s(get_string('markingnotes', 'report_evidencereporter'))
                . '</strong><br>' . $general . '</td></tr>';
        }
        $out .= '<tr><td width="70%"><strong>' . s(get_string('specificfeedback', 'report_evidencereporter'))
            . '</strong><br><br><br><br></td><td width="30%">' . $this->boxes($sat, $nys, '<br>') . '</td></tr>';

        // Competencies the question is mapped to (supporting evidence), in small print.
        $mapped = $this->question_competencies($questionid);
        foreach (array_keys($mapped) as $competencyid) {
            $this->matrix[$competencyid][] = $number;
        }
        if ($mapped) {
            $notlinked = array_diff(array_keys($mapped), $quizcompetencies);
            $out .= '<tr><td colspan="2" width="100%"><span style="font-size:7pt;color:#555555;">'
                . s(get_string('questioncompetencies', 'report_evidencereporter')) . ': '
                . s(implode('; ', $mapped))
                . ($notlinked ? ' · ' . s(get_string(
                    'qflag_notlinkedatquiz',
                    'report_evidencereporter',
                    \core_text::strtolower(terms::get('competency'))
                )) . ': ' . s(implode(
                    '; ',
                    array_intersect_key($mapped, array_flip($notlinked))
                )) : '')
                . '</span></td></tr>';
            if ($notlinked && !$inpool) {
                $this->reviewnotes[] = get_string('reviewnote_notlinked', 'report_evidencereporter', $number);
            }
        }
        return $out . '</table><p></p>';
    }

    /**
     * A random slot: every question that can be drawn, each with its model answer.
     *
     * @param stdClass $slot
     * @param string $number
     * @param int[] $quizcompetencies
     * @param string $sat
     * @param string $nys
     * @return string
     */
    private function random_html(stdClass $slot, string $number, array $quizcompetencies, string $sat, string $nys): string {
        global $DB;
        $filter = $slot->filtercondition['filter'] ?? [];
        try {
            $loader = new \core_question\local\bank\random_question_loader(new \qubaid_list([]));
            $questionids = array_keys($loader->get_filtered_questions($filter, 0, 0, ['id']));
        } catch (\Throwable $e) {
            $questionids = [];
        }
        $category = $DB->get_field('question_categories', 'name', ['id' => (int) ($slot->category ?? 0)]);
        $out = '<table border="1" cellpadding="4" width="100%"><tr style="background-color:#fff3cd;"><td width="100%"><strong>'
            . s(get_string('questionx', 'report_evidencereporter', $number)) . ' · '
            . s(get_string('randompool', 'report_evidencereporter', (object) [
                'count' => count($questionids),
                'category' => $category !== false ? format_string($category) : '?',
            ])) . '</strong></td></tr></table><br>';
        $this->reviewnotes[] = get_string('reviewnote_random', 'report_evidencereporter', $number);
        $i = 0;
        foreach (array_slice($questionids, 0, self::MAXPOOL) as $questionid) {
            $i++;
            $out .= $this->question_html(
                (int) $questionid,
                $number . '.' . $i,
                (float) $slot->maxmark,
                $quizcompetencies,
                $sat,
                $nys,
                true
            );
        }
        if (count($questionids) > self::MAXPOOL) {
            $out .= '<p><small>' . s(get_string(
                'randompoolmore',
                'report_evidencereporter',
                count($questionids) - self::MAXPOOL
            )) . '</small></p>';
        }
        return $out;
    }

    /**
     * Question text for the PDF: gaps shown as blanks, images as a note.
     *
     * @param \question_definition $question
     * @param \context $context
     * @return string
     */
    private function question_text(\question_definition $question, \context $context): string {
        $text = format_text(
            $question->questiontext,
            $question->questiontextformat,
            ['context' => $context, 'filter' => false]
        );
        // Cloze gaps.
        $text = preg_replace('/\{#(\d+)\}/', ' ______ ($1) ', $text);
        return $this->clean($text);
    }

    /**
     * The model answer: Information for graders for essays, otherwise the question's right answer.
     *
     * @param \question_definition $question
     * @param \context $context
     * @return array [HTML, bool missing]
     */
    private function model_answer(\question_definition $question, \context $context): array {
        if ($question->get_type_name() === 'essay') {
            $info = (string) ($question->graderinfo ?? '');
            if (trim(strip_tags($info)) === '') {
                return ['<em>' . s(get_string('nomodelanswer_essay', 'report_evidencereporter')) . '</em>', true];
            }
            return [$this->clean(format_text(
                $info,
                $question->graderinfoformat ?? FORMAT_HTML,
                ['context' => $context, 'filter' => false]
            )), false];
        }
        $summary = null;
        if (method_exists($question, 'get_right_answer_summary')) {
            try {
                // Some types (e.g. multiple choice) need an attempt started to know their order.
                $question->start_attempt(new \question_attempt_step(), 1);
                $summary = $question->get_right_answer_summary();
            } catch (\Throwable $e) {
                $summary = null;
            }
        }
        if ($summary === null || trim((string) $summary) === '') {
            return ['<em>' . s(get_string('nomodelanswer', 'report_evidencereporter')) . '</em>', true];
        }
        return [nl2br(s((string) $summary)), false];
    }

    /**
     * Competencies a question is mapped to (see {@see question_mappings}), as labels.
     *
     * @param int $questionid
     * @return array competencyid => label
     */
    private function question_competencies(int $questionid): array {
        if (!question_evidence::is_available()) {
            return [];
        }
        $entryid = question_mappings::get_entryid($questionid);
        if (!$entryid) {
            return [];
        }
        $labels = [];
        foreach (question_mappings::get_for_entries([$entryid])[$entryid] ?? [] as $competency) {
            $labels[(int) $competency->id] = question_mappings::label($competency);
        }
        return $labels;
    }

    /**
     * Competencies mapped to any question the quiz can show (for the list of units).
     *
     * @param stdClass $quiz
     * @param \context $context
     * @return int[]
     */
    private function question_competencyids(stdClass $quiz, \context $context): array {
        global $DB;
        $mappings = question_mappings::get_sql();
        if ($mappings === null) {
            return [];
        }
        $ids = $DB->get_fieldset_sql("SELECT DISTINCT qcm.competencyid
                                        FROM {quiz_slots} qs
                                        JOIN {question_references} qr ON qr.usingcontextid = :ctx
                                             AND qr.component = 'mod_quiz' AND qr.questionarea = 'slot'
                                             AND qr.itemid = qs.id
                                        JOIN {$mappings} qcm ON qcm.questionbankentryid = qr.questionbankentryid
                                       WHERE qs.quizid = :quizid", ['ctx' => $context->id, 'quizid' => $quiz->id]);
        return array_map('intval', $ids);
    }

    /**
     * The units (top-level competencies) the given competencies belong to, with their frameworks.
     *
     * @param int[] $competencyids
     * @return string[]
     */
    private function units(array $competencyids): array {
        global $DB;
        $competencyids = array_values(array_unique($competencyids));
        if (!$competencyids) {
            return [];
        }
        $roots = [];
        foreach ($DB->get_records_list('competency', 'id', $competencyids, '', 'id, path') as $competency) {
            $path = array_values(array_filter(explode('/', (string) $competency->path), fn($id) => (int) $id > 0));
            $roots[] = $path ? (int) $path[0] : (int) $competency->id;
        }
        $roots = array_values(array_unique($roots));
        $sql = "SELECT c.id, c.idnumber, c.shortname, cf.shortname AS framework
                  FROM {competency} c
                  JOIN {competency_framework} cf ON cf.id = c.competencyframeworkid
                 WHERE c.id IN (" . implode(',', $roots) . ")
              ORDER BY cf.shortname, c.sortorder, c.idnumber";
        $units = [];
        foreach ($DB->get_records_sql($sql) as $unit) {
            $name = format_string($unit->shortname, true, ['escape' => false]);
            $units[] = (trim((string) $unit->idnumber) !== '' && $unit->idnumber !== $name ? $unit->idnumber . ' ' : '')
                . $name . ' (' . format_string($unit->framework, true, ['escape' => false]) . ')';
        }
        return $units;
    }

    /**
     * Mapping matrix: each competency, the questions mapped to it, and whether the quiz is linked to it.
     *
     * @param int[] $quizcompetencies
     * @return string
     */
    private function matrix_html(array $quizcompetencies): string {
        global $DB;
        $ids = array_values(array_unique(array_merge($quizcompetencies, array_keys($this->matrix))));
        $out = '<h2>' . s(get_string('mappingmatrix', 'report_evidencereporter')) . '</h2>';
        if (!$ids) {
            return $out . '<p>' . $this->notset() . '</p>';
        }
        $competencies = $DB->get_records_list(
            'competency',
            'id',
            $ids,
            'competencyframeworkid, path, sortorder',
            'id, idnumber, shortname, path, sortorder, competencyframeworkid'
        );
        $out .= '<table border="1" cellpadding="4" width="100%"><tr style="background-color:#e9ecef;font-weight:bold;">'
            . '<td width="45%">' . s(terms::get('competency')) . '</td>'
            . '<td width="35%">' . s(get_string('matrixquestions', 'report_evidencereporter')) . '</td>'
            . '<td width="20%">' . s(get_string('matrixlinked', 'report_evidencereporter')) . '</td></tr>';
        foreach ($competencies as $competency) {
            $name = format_string($competency->shortname, true, ['escape' => false]);
            $label = trim((string) $competency->idnumber) !== '' && $competency->idnumber !== $name
                ? $competency->idnumber . ' ' . $name : $name;
            $numbers = $this->matrix[(int) $competency->id] ?? [];
            $out .= '<tr><td width="45%">' . s($label) . '</td>'
                . '<td width="35%">' . s($numbers ? implode(', ', $numbers) : '–') . '</td>'
                . '<td width="20%">' . s(get_string(in_array((int) $competency->id, $quizcompetencies) ? 'yes' : 'no'))
                . '</td></tr>';
        }
        return $out . '</table><p><small>' . s(get_string('matrixnote', 'report_evidencereporter')) . '</small></p>';
    }

    /**
     * The quiz's grade to pass, e.g. "7.00 / 10.00".
     *
     * @param stdClass $quiz
     * @return string HTML
     */
    private function pass_mark(stdClass $quiz): string {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id,
            'itemnumber' => 0, 'courseid' => $quiz->course]);
        if (!$item || (float) $item->gradepass <= 0) {
            $this->reviewnotes[] = get_string('reviewnote_nopassmark', 'report_evidencereporter');
            return $this->notset();
        }
        return s(format_float((float) $item->gradepass, 2) . ' / ' . format_float((float) $item->grademax, 2));
    }

    /**
     * A label and value row.
     *
     * @param string $label
     * @param string|int $valuehtml
     * @return string
     */
    private function row(string $label, $valuehtml): string {
        return '<tr><td width="30%" style="background-color:#f2f4f7;"><strong>' . s($label) . '</strong></td>'
            . '<td width="70%">' . $valuehtml . '</td></tr>';
    }

    /**
     * A signature row: declaration, name, signature, date.
     *
     * @param string $declaration
     * @return string
     */
    private function signature_row(string $declaration): string {
        return '<tr><td colspan="3" width="100%"><small>' . s($declaration) . '</small></td></tr>'
            . '<tr><td width="40%">' . s(get_string('name')) . ':<br><br></td>'
            . '<td width="40%">' . s(get_string('signature', 'report_evidencereporter')) . ':<br><br></td>'
            . '<td width="20%">' . s(get_string('date')) . ':<br><br></td></tr>';
    }

    /**
     * Tick boxes for the two results.
     *
     * @param string $sat
     * @param string $nys
     * @param string $separator
     * @return string
     */
    private function boxes(string $sat, string $nys, string $separator = '&nbsp;&nbsp;&nbsp;'): string {
        $box = '<span style="font-size:11pt;">&#9633;</span> ';
        return $box . s($sat) . $separator . $box . s($nys);
    }

    /**
     * A plain-text value, or "Not set".
     *
     * @param string $value
     * @return string HTML
     */
    private function value(string $value): string {
        return $value !== '' ? s($value) : $this->notset();
    }

    /**
     * Several lines of plain text, or "Not set".
     *
     * @param string $value
     * @return string HTML
     */
    private function text(string $value): string {
        return $value !== '' ? nl2br(s($value)) : $this->notset();
    }

    /**
     * "Not set", greyed.
     *
     * @return string HTML
     */
    private function notset(): string {
        return '<span style="color:#999999;">' . s(get_string('notset', 'report_evidencereporter')) . '</span>';
    }

    /**
     * HTML that the PDF renderer copes with: images become a note, scripts and media go.
     *
     * @param string $html
     * @return string
     */
    private function clean(string $html): string {
        $html = preg_replace(
            '/<img[^>]*>/i',
            '<em>[' . s(get_string('imagenote', 'report_evidencereporter')) . ']</em>',
            $html
        );
        $html = preg_replace('#<(script|style|video|audio|iframe|object)\b[^>]*>.*?</\1>#is', '', $html);
        // Paragraph margins leave large gaps in table cells: use line breaks instead.
        $html = preg_replace(['#<p\b[^>]*>#i', '#</p>#i'], ['', '<br>'], $html);
        return preg_replace('#^(\s*<br\s*/?>)+|(<br\s*/?>\s*)+$#i', '', trim($html));
    }
}
