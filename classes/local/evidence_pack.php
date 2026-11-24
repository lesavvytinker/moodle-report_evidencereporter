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

use stdClass;
use stored_file;

/**
 * Builds a learner's evidence pack: one ZIP per learner and competency framework.
 *
 * Contents:
 *  - 01 Evidence summary.pdf: the printable summary (overview, competencies, evidence, attempts);
 *  - 02 Assessments/<activity>/Attempt N/: a record PDF for every assessed attempt, failed ones included, plus for
 *    assignments the submitted files and online text, and feedback files and annotated PDFs; quiz records hold every
 *    question with the learner's answer, the right answer, the mark and all feedback;
 *  - 03 Prior learning/<evidence>/: the learner's evidence of prior learning files and details;
 *  - README.txt and manifest.csv (every file with its size and SHA-256 checksum, so the pack can be shown to be
 *    unchanged later).
 *
 * Applies the same access and release rules as the report and summary: only activities in courses the current user
 * may see, and unreleased grades or feedback only for users who could see them in Moodle.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evidence_pack {
    /** @var array Archive path => stored_file or [string content]. */
    private array $files = [];

    /** @var array Manifest rows: path, size, sha256. */
    private array $manifest = [];

    /** @var stdClass The learner. */
    private stdClass $learner;

    /** @var scope What the pack covers: a framework, a learning plan, or the learner's active plans. */
    private scope $scope;

    /** @var bool Whether the viewer may see full names. */
    private bool $viewfullnames;

    /** @var identity Learner identity and redaction choices. */
    private identity $identity;

    /** @var string[] HTML of every PDF generated, kept in unit tests only (PDF text is compressed). */
    private array $renderedhtml = [];

    /**
     * Constructor.
     *
     * @param int $userid Learner
     * @param scope|int $scope What to cover (a framework ID is short for that framework)
     * @param bool $includefeedback Include assessor feedback, feedback files and quiz feedback
     * @param array $redact Redaction flags: redactname, redactusername, redactstaff
     */
    public function __construct(
        /** @var int Learner ID. */
        protected int $userid,
        scope|int $scope,
        /** @var bool Include feedback. */
        protected bool $includefeedback = true,
        array $redact = []
    ) {
        $this->learner = \core_user::get_user($userid, '*', MUST_EXIST);
        $this->scope = is_int($scope) ? scope::for_framework($userid, $scope) : $scope;
        $this->viewfullnames = has_capability('moodle/site:viewfullnames', \context_system::instance());
        $this->identity = identity::from_flags($this->learner, $redact);
        // The words to use: the profile of the frameworks covered (e.g. VET or higher education).
        terms::use_profile($this->scope->get_profile());
    }

    /**
     * The learner (for evidence sources adding to the pack).
     *
     * @return int
     */
    public function get_userid(): int {
        return $this->userid;
    }

    /**
     * Does the pack include feedback?
     *
     * @return bool
     */
    public function includes_feedback(): bool {
        return $this->includefeedback;
    }

    /**
     * May the current user see full names?
     *
     * @return bool
     */
    public function can_view_fullnames(): bool {
        return $this->viewfullnames;
    }

    /**
     * What the pack covers.
     *
     * @return scope
     */
    public function get_scope(): scope {
        return $this->scope;
    }

    /**
     * HTML behind each generated PDF (unit tests only).
     *
     * @return string[]
     */
    public function get_rendered_html(): array {
        return $this->renderedhtml;
    }

    /**
     * The learner identity used in this pack.
     *
     * @return identity
     */
    public function get_identity(): identity {
        return $this->identity;
    }

    /**
     * Suggested download file name.
     *
     * @return string
     */
    public function get_filename(): string {
        return branding::file_name([
            'document' => terms::get('evidencepack'),
            'learner' => $this->identity->file_label(),
            'covering' => $this->scope->get_file_label(),
            'date' => userdate(time(), '%Y-%m-%d'),
        ]) . '.zip';
    }

    /**
     * Build the pack.
     *
     * @return string Path to the ZIP file in a temporary directory
     */
    public function build(): string {
        \core_php_time_limit::raise(300);
        raise_memory_limit(MEMORY_EXTRA);

        $summary = summary::build($this->userid, $this->scope, $this->identity);
        $this->add_content(
            '01 ' . terms::get('summarytitle') . '.pdf',
            $this->html_to_pdf(terms::get('summarytitle'), $this->summary_html($summary))
        );

        $this->add_assessments();
        // Records that aren't attempts, from any source (e.g. checklists).
        $competencyids = $this->scope->get_competency_ids();
        foreach (sources::all() as $source) {
            $source->add_records_to_pack($this, $competencyids);
        }
        $this->add_prior_learning();
        $this->add_readme();

        // Manifest last, covering every other file.
        $csv = "path,bytes,sha256\n";
        foreach ($this->manifest as $row) {
            $csv .= '"' . str_replace('"', '""', $row['path']) . "\",{$row['size']},{$row['sha256']}\n";
        }
        $this->files['manifest.csv'] = [$csv];

        $dir = make_request_directory();
        $zippath = $dir . '/pack.zip';
        $packer = get_file_packer('application/zip');
        if (!$packer->archive_to_pathname($this->files, $zippath)) {
            throw new \moodle_exception('packfailed', 'report_evidencereporter');
        }
        return $zippath;
    }

    /**
     * Just the evidence summary as a PDF (used by bulk downloads of summaries).
     *
     * @return string PDF bytes
     */
    public function build_summary_pdf(): string {
        \core_php_time_limit::raise(120);
        $summary = summary::build($this->userid, $this->scope, $this->identity);
        return $this->html_to_pdf(terms::get('summarytitle'), $this->summary_html($summary));
    }

    /**
     * Archive paths in the pack (for tests and the README).
     *
     * @return string[]
     */
    public function get_paths(): array {
        return array_keys($this->files);
    }

    /**
     * Every assessed attempt at assignments and quizzes linked to the framework's competencies.
     */
    private function add_assessments(): void {
        $links = summary::get_linked_activities($this->userid, null, $this->scope->get_competency_ids());
        $competenciesbycm = [];
        foreach ($links as $link) {
            $competenciesbycm[$link->cmid][] = (int) $link->competencyid;
        }
        if (!$competenciesbycm) {
            return;
        }
        $byactivity = attempts::get_for_user($this->userid, array_keys($competenciesbycm));
        foreach ($byactivity as $cmid => $rows) {
            try {
                [$course, $cm] = get_course_and_cm_from_cmid($cmid);
            } catch (\Throwable $e) {
                continue;
            }
            $folder = '02 ' . get_string('packassessments', 'report_evidencereporter') . '/'
                . $this->safe_name(format_string($course->shortname) . ' - ' . $cm->get_formatted_name());
            $compnames = $this->competency_names($competenciesbycm[$cmid]);
            // The blank assessment tool the learner was assessed with.
            if (assessor_guide::supports($cm->modname) && get_config('report_evidencereporter', 'packassessorguides') !== '0') {
                try {
                    $guide = new assessor_guide($cm);
                    $this->add_content($folder . '/' . $this->safe_name(
                        get_string('assessorguide_packfile', 'report_evidencereporter')
                    ) . '.pdf', $guide->build_pdf());
                } catch (\Throwable $e) {
                    debugging(
                        'Evidence Reporter: the assessor guide could not be built: ' . $e->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }
            }
            foreach ($rows as $row) {
                $attempt = $this->redact_attempt(attempts::format($row, $this->viewfullnames));
                $attemptfolder = $folder . '/' . get_string(
                    $attempt->isentry ? 'entrysummary' : 'attemptsummary',
                    'report_evidencereporter',
                    $attempt->attemptno
                );
                sources::get((string) $row->modname)?->add_attempt_to_pack(
                    $this,
                    $attemptfolder,
                    $cm,
                    $row,
                    $attempt,
                    $compnames
                );
            }
        }
    }

    /**
     * Copy of a formatted attempt with redaction applied to the marker, raters, comments and feedback.
     *
     * @param stdClass $attempt
     * @return stdClass
     */
    private function redact_attempt(stdClass $attempt): stdClass {
        $copy = clone $attempt;
        $copy->marker = attempts::marker($attempt, $this->identity);
        $copy->ratings = array_map(function ($rating) {
            $rating = clone $rating;
            $rating->rater = $this->identity->person((int) $rating->raterid, $rating->rater);
            return $rating;
        }, $attempt->ratings);
        $copy->feedback = $attempt->isentry ? data_entries::comments_html($attempt->comments, $this->identity)
            : $this->identity->text($attempt->feedback);
        return $copy;
    }

    /**
     * Evidence of prior learning linked to the framework's competencies, if the current user may see it.
     */
    private function add_prior_learning(): void {
        global $DB, $USER;
        $usercontext = \context_user::instance($this->userid);
        if ((int) $USER->id !== $this->userid && !has_capability('moodle/competency:userevidenceview', $usercontext)) {
            return;
        }
        if (!$competencyids = $this->scope->get_competency_ids()) {
            return;
        }
        [$compinsql, $compparams] = $DB->get_in_or_equal($competencyids, SQL_PARAMS_NAMED, 'pl');
        $sql = "SELECT DISTINCT ue.*
                  FROM {competency_userevidence} ue
                  JOIN {competency_userevidencecomp} uec ON uec.userevidenceid = ue.id
                  JOIN {competency} c ON c.id = uec.competencyid
                 WHERE ue.userid = :userid AND c.id {$compinsql}
              ORDER BY ue.timecreated";
        $fs = get_file_storage();
        foreach ($DB->get_records_sql($sql, ['userid' => $this->userid] + $compparams) as $ue) {
            $folder = '03 ' . get_string('packpriorlearning', 'report_evidencereporter') . '/'
                . $this->safe_name($this->identity->text($ue->name, true));
            $compids = $DB->get_fieldset_select('competency_userevidencecomp', 'competencyid', 'userevidenceid = ?', [$ue->id]);
            $uename = $this->identity->text($ue->name);
            $html = '<h2>' . s($uename) . '</h2><table border="1" cellpadding="4" width="100%">'
                . $this->learner_rows()
                . $this->table_row(
                    terms::get('competency'),
                    s(implode('; ', $this->competency_names($compids)))
                )
                . $this->table_row(get_string('timecreated', 'report_evidencereporter'), s(userdate($ue->timecreated)))
                . ($ue->url ? $this->table_row('URL', s($this->identity->text($ue->url))) : '')
                . '</table>'
                . $this->identity->text(format_text(
                    (string) $ue->description,
                    (int) $ue->descriptionformat,
                    ['context' => $usercontext]
                ));
            $this->add_content(
                $folder . '/' . get_string('packdetails', 'report_evidencereporter') . '.pdf',
                $this->html_to_pdf($uename, $html)
            );
            foreach (
                $fs->get_area_files(
                    $usercontext->id,
                    'core_competency',
                    'userevidence',
                    $ue->id,
                    'filepath, filename',
                    false
                ) as $file
            ) {
                $this->add_file($folder, $file);
            }
        }
    }

    /**
     * README.txt describing the pack.
     */
    private function add_readme(): void {
        global $USER, $SITE;
        $lines = [
            terms::get('evidencepack') . ' · ' . branding::get_orgname(),
            str_repeat('=', 40),
        ];
        if (branding::get_header() !== '') {
            $lines[] = branding::get_header();
            $lines[] = '';
        }
        foreach ($this->identity->rows() as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }
        foreach ($this->scope->describe() as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }
        $lines = array_merge($lines, [
            get_string('generated', 'report_evidencereporter') . ': ' . userdate(time()) . ' · '
                . $this->identity->staff(fullname($USER)) . ' · ' . format_string($SITE->fullname),
            get_string('includefeedback', 'report_evidencereporter') . ': '
                . get_string($this->includefeedback ? 'yes' : 'no'),
        ]);
        if ($this->identity->is_redacted()) {
            $lines[] = $this->identity->redaction_note();
            $lines[] = get_string('redactionfilesnote', 'report_evidencereporter');
        }
        $lines[] = '';
        $lines[] = get_string('packreadme', 'report_evidencereporter');
        if (branding::get_footer() !== '') {
            $lines[] = '';
            $lines[] = branding::get_footer();
        }
        $this->add_content('README.txt', implode("\r\n", $lines) . "\r\n");
    }

    /**
     * Attempt header: activity, competencies, attempt, dates, grade, marker.
     *
     * @param \cm_info $cm
     * @param stdClass $attempt
     * @param string[] $compnames
     * @return string HTML
     */
    public function attempt_header_html(\cm_info $cm, stdClass $attempt, array $compnames): string {
        $date = fn(?int $time) => $time ? userdate($time) : '-';
        return '<h2>' . s($cm->get_formatted_name()) . ' - '
            . s(get_string(
                $attempt->isentry ? 'entrysummary' : 'attemptsummary',
                'report_evidencereporter',
                $attempt->attemptno
            )) . '</h2>'
            . '<table border="1" cellpadding="4" width="100%">'
            . $this->learner_rows()
            . $this->table_row(terms::get('competency'), s(implode('; ', $compnames)))
            . $this->table_row(
                get_string('activitytype', 'report_evidencereporter'),
                s(get_string('modulename', 'mod_' . $cm->modname))
            )
            . $this->table_row(get_string('timesubmitted', 'report_evidencereporter'), s($date($attempt->submitted)))
            . $this->table_row(get_string('timegraded', 'report_evidencereporter'), s($date($attempt->graded)))
            . $this->table_row(get_string('activitygrade', 'report_evidencereporter'), s($attempt->grade ?: '-'))
            . $this->table_row(get_string('marker', 'report_evidencereporter'), s($attempt->marker ?: '-'))
            . ($attempt->url ? $this->table_row('URL', s($attempt->url->out(false))) : '')
            . '</table>';
    }

    /**
     * Summary as simple HTML for the PDF (TCPDF handles a limited subset of HTML and CSS).
     *
     * @param stdClass $summary From {@see summary::build()}
     * @return string
     */
    private function summary_html(stdClass $summary): string {
        global $USER, $SITE;
        $html = '<h1>' . s(terms::get('summarytitle')) . '</h1>'
            . '<table border="1" cellpadding="4" width="100%">'
            . $this->learner_rows()
            . $this->scope_rows()
            . $this->table_row(
                get_string('generated', 'report_evidencereporter'),
                s(userdate(time()) . ' · ' . $this->identity->staff(fullname($USER)) . ' · ' . format_string($SITE->fullname))
            )
            . '</table>';
        if ($this->identity->is_redacted()) {
            $html .= '<p><em>' . s($this->identity->redaction_note()) . '</em></p>';
        }
        if ($summary->restricted) {
            $html .= '<p><em>' . s(get_string('restrictednotice', 'report_evidencereporter')) . '</em></p>';
        }
        if (!$this->includefeedback) {
            $html .= '<p><em>' . s(get_string('feedbackexcluded', 'report_evidencereporter')) . '</em></p>';
        }

        $html .= '<h2>' . s(get_string('summaryheading', 'report_evidencereporter')) . '</h2>'
            . '<table border="1" cellpadding="4" width="100%"><tr style="background-color:#e9ecef;font-weight:bold;">'
            . '<td width="40%">' . s(terms::get('competency')) . '</td>'
            . '<td width="18%">' . s(get_string('currentrating', 'report_evidencereporter')) . '</td>'
            . '<td width="22%">' . s(get_string('judgement', 'report_evidencereporter')) . '</td>'
            . '<td width="10%">' . s(get_string('evidenceitems', 'report_evidencereporter')) . '</td>'
            . '<td width="10%">' . s(get_string('attemptcount', 'report_evidencereporter')) . '</td></tr>';
        foreach ($summary->competencies as $comp) {
            if (!empty($comp->group)) {
                $html .= '<tr><td colspan="5" style="background-color:#f8f9fa;"><strong>' . s($comp->group) . '</strong></td></tr>';
            }
            $judgement = $comp->judgementdate ? trim(($comp->judgementrating ? $comp->judgementrating . ' · ' : '')
                . userdate($comp->judgementdate, get_string('strftimedate', 'core_langconfig'))
                . ($comp->judgementby ? ' · ' . $comp->judgementby : '')) : '';
            $html .= '<tr><td width="40%">' . str_repeat('&nbsp;&nbsp;&nbsp;', (int) $comp->depth)
                . '<strong>' . s($comp->idnumber) . '</strong> ' . s($comp->name) . '</td>'
                . '<td width="18%">' . s($comp->currentrating) . '</td>'
                . '<td width="22%">' . s($judgement) . '</td>'
                . '<td width="10%">' . (count($comp->evidence) ?: '-') . '</td>'
                . '<td width="10%">' . (count($comp->attempts) ?: '-') . '</td></tr>';
        }
        $html .= '</table>';

        // Evidence detail per competency (unless switched off in the settings).
        $showdetail = branding::show('evidencedetail');
        foreach ($showdetail ? $summary->competencies : [] as $comp) {
            if (!$comp->evidence && !($comp->attempts && branding::show('attempts')) && empty($comp->records)) {
                continue;
            }
            $html .= '<h3>' . s($comp->idnumber . ' ' . $comp->name) . '</h3>';
            if ($comp->evidence) {
                $html .= '<table border="1" cellpadding="3" width="100%"><tr style="background-color:#e9ecef;font-weight:bold;">'
                    . '<td width="16%">' . s(get_string('timecreated', 'report_evidencereporter')) . '</td>'
                    . '<td width="' . ($this->includefeedback ? 34 : 54) . '%">'
                    . s(get_string('evidencesource', 'report_evidencereporter')) . '</td>'
                    . '<td width="14%">' . s(get_string('rating', 'report_evidencereporter')) . '</td>'
                    . '<td width="16%">' . s(terms::get('assessor')) . '</td>'
                    . ($this->includefeedback ? '<td width="20%">' . s(get_string('feedback', 'report_evidencereporter'))
                        . '</td>' : '')
                    . '</tr>';
                foreach ($comp->evidence as $item) {
                    $source = s($item->source) . ($item->description ? '<br><small>' . s($item->description) . '</small>' : '')
                        . ($item->grade ? '<br>' . s(get_string('activitygrade', 'report_evidencereporter') . ': '
                            . $item->grade) : '')
                        . (!empty($item->gradeadjustment) ? '<br><small>' . $item->gradeadjustment . '</small>' : '')
                        . (!empty($item->similaritytext) ? '<br><small>' . s(get_string(
                            'similaritycheck',
                            'report_evidencereporter'
                        )) . ': ' . s($item->similaritytext) . '</small>' : '')
                        . ($item->url ? '<br><small>' . s($item->url) . '</small>' : '');
                    $html .= '<tr><td width="16%">' . s($item->date) . '<br><small>' . s($item->type) . '</small></td>'
                        . '<td width="' . ($this->includefeedback ? 34 : 54) . '%">' . $source . '</td>'
                        . '<td width="14%">' . s($item->ratinggiven) . '</td>'
                        . '<td width="16%">' . s($item->assessor) . ($item->note ? '<br><small>' . $item->note . '</small>' : '')
                        . '</td>'
                        . ($this->includefeedback ? '<td width="20%">' . $item->feedback . '</td>' : '')
                        . '</tr>';
                }
                $html .= '</table>';
            }
            if ($comp->attempts && branding::show('attempts')) {
                $html .= '<p><strong>' . s(get_string('attemptsheading', 'report_evidencereporter')) . '</strong></p>'
                    . '<table border="1" cellpadding="3" width="100%"><tr style="background-color:#e9ecef;font-weight:bold;">'
                    . '<td width="30%">' . s(get_string('activity', 'report_evidencereporter')) . '</td>'
                    . '<td width="10%">' . s(get_string('attemptnumber', 'report_evidencereporter')) . '</td>'
                    . '<td width="20%">' . s(get_string('timegraded', 'report_evidencereporter')) . '</td>'
                    . '<td width="20%">' . s(get_string('activitygrade', 'report_evidencereporter')) . '</td>'
                    . '<td width="20%">' . s(get_string('marker', 'report_evidencereporter')) . '</td></tr>';
                foreach ($comp->attempts as $item) {
                    $html .= '<tr><td width="30%">' . s($item->activity) . '</td><td width="10%">' . s($item->label)
                        . '</td><td width="20%">' . s($item->graded) . '</td><td width="20%">' . s($item->grade)
                        . '</td><td width="20%">' . s($item->marker) . '</td></tr>';
                }
                $html .= '</table><p><small>' . s(get_string('packseeassessments', 'report_evidencereporter')) . '</small></p>';
            }
            if (!empty($comp->questions) && branding::show('attempts')) {
                $html .= $this->questions_html($comp->questions);
            }
            if (!empty($comp->records)) {
                $html .= '<p><strong>' . s(get_string('packrecords', 'report_evidencereporter')) . '</strong></p><ul>';
                foreach ($comp->records as $record) {
                    $html .= '<li>' . s($record->heading . ': ' . $record->activity) . ' (' . s($record->count) . ')</li>';
                }
                $html .= '</ul><p><small>' . s(get_string('packseerecords', 'report_evidencereporter')) . '</small></p>';
            }
        }
        if (branding::show('signoff')) {
            $html .= '<p>&nbsp;</p><p><strong>' . s(branding::get_signoff_heading()) . '</strong></p>'
                . (branding::get_signoff_statement() !== '' ? '<p>' . nl2br(s(branding::get_signoff_statement())) . '</p>' : '')
                . '<p>' . s(get_string('reviewedby', 'report_evidencereporter')) . ': ________________________ &nbsp; '
                . s(get_string('signature', 'report_evidencereporter')) . ': __________________ &nbsp; '
                . s(get_string('date')) . ': ____________</p>';
        }
        return $html;
    }

    /**
     * Supporting evidence from quiz questions, for the summary PDF: each quiz attempt with its result, and each question
     * with its mark, result, flags and a link to the learner's answer.
     *
     * @param stdClass[] $groups From {@see question_evidence::get_for_user()}
     * @return string HTML
     */
    private function questions_html(array $groups): string {
        $html = '<p><strong>' . s(get_string('questionsheading', 'report_evidencereporter')) . '</strong><br><small>'
            . s(get_string('questionsnote', 'report_evidencereporter')) . '</small></p>'
            . '<table border="1" cellpadding="3" width="100%"><tr style="background-color:#e9ecef;font-weight:bold;">'
            . '<td width="45%">' . s(get_string('question', 'report_evidencereporter')) . '</td>'
            . '<td width="15%">' . s(get_string('questionmark', 'report_evidencereporter')) . '</td>'
            . '<td width="15%">' . s(get_string('questionresult', 'report_evidencereporter')) . '</td>'
            . '<td width="25%">' . s(get_string('questionnotes', 'report_evidencereporter')) . '</td></tr>';
        foreach ($groups as $group) {
            $heading = $group->activity . ' · ' . $group->label
                . ($group->quizresult !== '' ? ' · ' . get_string('quizresult', 'report_evidencereporter') . ': '
                    . $group->quizresult : '')
                . ($group->grade !== '' ? ' (' . $group->grade . ')' : '');
            $html .= '<tr style="background-color:#f8f9fa;"><td colspan="4" width="100%"><strong>' . s($heading) . '</strong>'
                . implode('', array_map(fn($flag) => '<br><small>' . s($flag) . '</small>', $group->flags)) . '</td></tr>';
            foreach ($group->questions as $question) {
                $html .= '<tr><td width="45%">' . s($question->number . '. ' . $question->name)
                    . '<br><small>' . s($question->reviewurl) . '</small></td>'
                    . '<td width="15%">' . s($question->mark) . '</td>'
                    . '<td width="15%">' . s($question->outcome) . '</td>'
                    . '<td width="25%">' . implode('<br>', array_map('s', $question->flags)) . '</td></tr>';
            }
        }
        return $html . '</table>';
    }

    /**
     * Names of competencies, "ID number name".
     *
     * @param int[] $ids
     * @return string[]
     */
    public function competency_names(array $ids): array {
        global $DB;
        if (!$ids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_unique($ids));
        $names = [];
        foreach ($DB->get_records_select('competency', "id {$insql}", $params, 'sortorder', 'id, idnumber, shortname') as $c) {
            $names[] = trim($c->idnumber . ' ' . format_string($c->shortname));
        }
        return $names;
    }

    /**
     * Rows describing what the pack covers: the framework, or the learning plans with their status.
     *
     * @return string HTML
     */
    private function scope_rows(): string {
        $html = '';
        foreach ($this->scope->describe() as $label => $value) {
            $html .= $this->table_row($label, s($value));
        }
        return $html;
    }

    /**
     * Rows identifying the learner: name, username, ID number, reference (redaction applied).
     *
     * @return string HTML
     */
    public function learner_rows(): string {
        $html = '';
        foreach ($this->identity->rows() as $label => $value) {
            $html .= $this->table_row($label, s($value));
        }
        return $html;
    }

    /**
     * Two-cell table row.
     *
     * @param string $label Plain text
     * @param string $valuehtml Already escaped HTML
     * @return string
     */
    public function table_row(string $label, string $valuehtml): string {
        return '<tr><td width="25%" style="background-color:#f2f4f7;"><strong>' . s($label) . '</strong></td>'
            . '<td width="75%">' . $valuehtml . '</td></tr>';
    }

    /**
     * Render HTML to a PDF.
     *
     * @param string $title
     * @param string $html
     * @return string PDF bytes
     */
    public function html_to_pdf(string $title, string $html): string {
        $html = self::branded_html($html);
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            $this->renderedhtml[] = $html;
        }
        return self::render_pdf($title, $html);
    }

    /**
     * HTML for a PDF with the organisation's name and header text at the top and footer text at the end. Images are
     * removed: they need a logged-in browser.
     *
     * @param string $html
     * @return string
     */
    public static function branded_html(string $html): string {
        $html = preg_replace('/<img[^>]*>/i', '', $html);
        return '<p style="color:#555555;font-size:8pt;">' . s(branding::get_orgname())
            . (branding::get_header() !== '' ? '<br>' . nl2br(s(branding::get_header())) : '') . '</p>'
            . $html
            . (branding::get_footer() !== '' ? '<p style="color:#555555;font-size:8pt;">'
                . nl2br(s(branding::get_footer())) . '</p>' : '');
    }

    /**
     * Render HTML (from {@see branded_html()}) to a PDF, with the organisation's logo at the top.
     *
     * @param string $title
     * @param string $html
     * @return string PDF bytes
     */
    public static function render_pdf(string $title, string $html): string {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');
        $pdf = new \pdf();
        $pdf->SetTitle($title);
        $pdf->SetAuthor(branding::get_orgname());
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->SetFont('freesans', '', 9);
        $pdf->AddPage();
        if ($logo = branding::get_logo()) {
            try {
                $pdf->Image('@' . $logo->get_content(), 15, 10, 0, 14, '', '', 'N', true);
                $pdf->Ln(2);
            } catch (\Throwable $e) {
                debugging('Evidence Reporter: the logo could not be added to a PDF: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        $pdf->writeHTML($html);
        return $pdf->Output('', 'S');
    }

    /**
     * Wrap HTML in a minimal standalone document.
     *
     * @param string $title
     * @param string $body
     * @return string
     */
    public function html_document(string $title, string $body): string {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . s($title) . '</title></head><body>'
            . $body . '</body></html>';
    }

    /**
     * Add generated content.
     *
     * @param string $path
     * @param string $content
     */
    public function add_content(string $path, string $content): void {
        $path = $this->unique_path($path);
        $this->files[$path] = [$content];
        $this->manifest[] = ['path' => $path, 'size' => strlen($content), 'sha256' => hash('sha256', $content)];
    }

    /**
     * Add a stored file into a folder.
     *
     * @param string $folder
     * @param stored_file $file
     */
    public function add_file(string $folder, stored_file $file): void {
        $name = $this->identity->text($file->get_filename(), true);
        $path = $this->unique_path($folder . '/' . $this->safe_name($name, 120));
        $this->files[$path] = $file;
        $hash = hash_init('sha256');
        $handle = $file->get_content_file_handle();
        hash_update_stream($hash, $handle);
        fclose($handle);
        $this->manifest[] = ['path' => $path, 'size' => $file->get_filesize(), 'sha256' => hash_final($hash)];
    }

    /**
     * Unique archive path (adds " (2)" etc. before the extension when needed).
     *
     * @param string $path
     * @return string
     */
    private function unique_path(string $path): string {
        $candidate = $path;
        $n = 2;
        while (isset($this->files[$candidate])) {
            $dot = strrpos($path, '.');
            $candidate = $dot === false ? "{$path} ({$n})" : substr($path, 0, $dot) . " ({$n})" . substr($path, $dot);
            $n++;
        }
        return $candidate;
    }

    /**
     * File/folder name safe for ZIPs on any system.
     *
     * @param string $name
     * @param int $max
     * @return string
     */
    public function safe_name(string $name, int $max = 80): string {
        $name = clean_filename(html_entity_decode(strip_tags($name), ENT_QUOTES, 'UTF-8'));
        $name = trim(preg_replace('/[\\\\\/:*?"<>|]+/', '-', $name), " .-");
        return \core_text::substr($name === '' ? 'item' : $name, 0, $max);
    }
}
