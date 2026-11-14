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

    /** @var stdClass The framework. */
    private stdClass $framework;

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
     * @param int $frameworkid Competency framework
     * @param bool $includefeedback Include assessor feedback, feedback files and quiz feedback
     * @param array $redact Redaction flags: redactname, redactusername, redactstaff
     */
    public function __construct(
        /** @var int Learner ID. */
        protected int $userid,
        /** @var int Framework ID. */
        protected int $frameworkid,
        /** @var bool Include feedback. */
        protected bool $includefeedback = true,
        array $redact = []
    ) {
        global $DB;
        $this->learner = \core_user::get_user($userid, '*', MUST_EXIST);
        $this->framework = $DB->get_record('competency_framework', ['id' => $frameworkid], '*', MUST_EXIST);
        $this->viewfullnames = has_capability('moodle/site:viewfullnames', \context_system::instance());
        $this->identity = identity::from_flags($this->learner, $redact);
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
        $parts = [
            get_string('evidencepack', 'report_evidencereporter'),
            $this->identity->file_label(),
            $this->framework->idnumber ?: $this->framework->shortname,
            userdate(time(), '%Y-%m-%d'),
        ];
        return clean_filename(implode(' - ', $parts)) . '.zip';
    }

    /**
     * Build the pack.
     *
     * @return string Path to the ZIP file in a temporary directory
     */
    public function build(): string {
        \core_php_time_limit::raise(300);
        raise_memory_limit(MEMORY_EXTRA);

        $summary = summary::build($this->userid, $this->frameworkid, $this->identity);
        $this->add_content(
            '01 ' . get_string('summarytitle', 'report_evidencereporter') . '.pdf',
            $this->html_to_pdf(get_string('summarytitle', 'report_evidencereporter'), $this->summary_html($summary))
        );

        $this->add_assessments();
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
        $summary = summary::build($this->userid, $this->frameworkid, $this->identity);
        return $this->html_to_pdf(get_string('summarytitle', 'report_evidencereporter'), $this->summary_html($summary));
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
        $links = summary::get_linked_activities($this->userid, $this->frameworkid);
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
            foreach ($rows as $row) {
                $attempt = $this->redact_attempt(attempts::format($row, $this->viewfullnames));
                $attemptfolder = $folder . '/' . get_string(
                    $attempt->isentry ? 'entrysummary' : 'attemptsummary',
                    'report_evidencereporter',
                    $attempt->attemptno
                );
                if ($row->modname === 'data') {
                    $this->add_data_entry($attemptfolder, $cm, $row, $attempt, $compnames);
                } else if ($row->modname === 'assign') {
                    $this->add_assign_attempt($attemptfolder, $cm, $row, $attempt, $compnames);
                } else if ($row->modname === 'quiz') {
                    $this->add_quiz_attempt($attemptfolder, $cm, $row, $attempt, $compnames);
                }
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
     * Assignment attempt: record PDF, submitted files, online text, feedback files and annotated PDF.
     *
     * @param string $folder
     * @param \cm_info $cm
     * @param stdClass $row Raw attempt row
     * @param stdClass $attempt Formatted attempt
     * @param string[] $compnames
     */
    private function add_assign_attempt(string $folder, \cm_info $cm, stdClass $row, stdClass $attempt, array $compnames): void {
        global $DB;
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();
        $showfeedback = $this->includefeedback && ($attempt->released || $attempt->canseeunreleased);

        $record = $this->attempt_header_html($cm, $attempt, $compnames);
        if ($this->includefeedback) {
            $record .= '<h3>' . s(get_string('feedback', 'report_evidencereporter')) . '</h3>'
                . ($attempt->feedback !== '' ? $attempt->feedback : '<p>-</p>');
        } else {
            $record .= '<p><em>' . s(get_string('feedbackexcluded', 'report_evidencereporter')) . '</em></p>';
        }

        $submission = $DB->get_record('assign_submission', ['assignment' => $cm->instance, 'userid' => $this->userid,
            'attemptnumber' => $attempt->attemptno - 1, 'groupid' => 0]);
        if ($submission) {
            $record .= '<h3>' . s(get_string('packsubmission', 'report_evidencereporter')) . '</h3><p>'
                . s(get_string('submissionstatus_' . $submission->status, 'mod_assign')) . '</p>';
            $onlinetext = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submission->id]);
            if ($onlinetext && trim(strip_tags((string) $onlinetext->onlinetext)) !== '') {
                $text = format_text(
                    $onlinetext->onlinetext,
                    (int) $onlinetext->onlineformat,
                    ['context' => $context, 'noclean' => false]
                );
                $text = $this->identity->text($text);
                $this->add_content(
                    $folder . '/' . get_string('packonlinetext', 'report_evidencereporter') . '.html',
                    $this->html_document(get_string('packonlinetext', 'report_evidencereporter'), $text)
                );
            }
            foreach (
                $fs->get_area_files(
                    $context->id,
                    'assignsubmission_file',
                    'submission_files',
                    $submission->id,
                    'filepath, filename',
                    false
                ) as $file
            ) {
                $this->add_file($folder . '/' . get_string('packsubmittedfiles', 'report_evidencereporter'), $file);
            }
        }

        if ($showfeedback) {
            foreach (
                [['assignfeedback_file', 'feedback_files'], ['assignfeedback_editpdf', 'download']] as [$component,
                    $area]
            ) {
                foreach (
                    $fs->get_area_files(
                        $context->id,
                        $component,
                        $area,
                        (int) $row->attemptid,
                        'filepath, filename',
                        false
                    ) as $file
                ) {
                    $this->add_file($folder . '/' . get_string('packfeedbackfiles', 'report_evidencereporter'), $file);
                }
            }
        }

        $this->add_content(
            $folder . '/' . get_string('packattemptrecord', 'report_evidencereporter') . '.pdf',
            $this->html_to_pdf($cm->get_formatted_name(), $record)
        );
    }

    /**
     * Quiz attempt: record PDF with every question, the learner's answer, the right answer, the mark and all feedback.
     *
     * @param string $folder
     * @param \cm_info $cm
     * @param stdClass $row Raw attempt row
     * @param stdClass $attempt Formatted attempt
     * @param string[] $compnames
     */
    private function add_quiz_attempt(string $folder, \cm_info $cm, stdClass $row, stdClass $attempt, array $compnames): void {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $showmarks = $attempt->released || $attempt->canseeunreleased;
        $showfeedback = $this->includefeedback && strpos($attempt->feedback, 'er-feedbacklink') !== false;
        $record = $this->attempt_header_html($cm, $attempt, $compnames);
        if (!$this->includefeedback) {
            $record .= '<p><em>' . s(get_string('feedbackexcluded', 'report_evidencereporter')) . '</em></p>';
        }

        $attemptobj = \mod_quiz\quiz_attempt::create((int) $row->attemptid);
        $options = new \question_display_options();
        $options->feedback = $options->generalfeedback = $options->rightanswer = $options->manualcomment
            = $showfeedback ? \question_display_options::VISIBLE : \question_display_options::HIDDEN;
        $options->marks = $showmarks ? \question_display_options::MARK_AND_MAX : \question_display_options::HIDDEN;
        $options->context = \context_module::instance($cm->id);

        foreach ($attemptobj->get_slots() as $slot) {
            $qa = $attemptobj->get_question_attempt($slot);
            $question = $qa->get_question();
            $record .= '<h3>' . s(get_string('questionx', 'question', $attemptobj->get_question_number($slot))) . '</h3>';
            $record .= '<div>' . $question->format_questiontext($qa) . '</div>';
            $record .= '<table border="1" cellpadding="4" width="100%">';
            $record .= $this->table_row(
                get_string('packresponse', 'report_evidencereporter'),
                s($this->identity->text($qa->get_response_summary() ?? '-'))
            );
            if ($showmarks) {
                $record .= $this->table_row(get_string('status'), s($qa->get_state_string(true)));
                $record .= $this->table_row(
                    get_string('packmark', 'report_evidencereporter'),
                    s($qa->format_mark(2) . ' / ' . $qa->format_max_mark(2))
                );
            }
            if ($showfeedback) {
                $record .= $this->table_row(get_string('rightanswer', 'question'), s($qa->get_right_answer_summary() ?? ''));
            }
            $record .= '</table>';
            if ($showfeedback) {
                $feedback = $question->get_renderer($PAGE)->feedback($qa, $options);
                if (trim(strip_tags($feedback)) !== '') {
                    $record .= '<p><strong>' . s(get_string('feedback', 'report_evidencereporter')) . '</strong></p>'
                        . $this->identity->text($feedback);
                }
                [$comment, $commentformat] = $qa->get_manual_comment();
                if ($comment !== null && trim(strip_tags($comment)) !== '') {
                    $record .= '<p><strong>' . s(get_string('packcomment', 'report_evidencereporter')) . '</strong></p>'
                        . $this->identity->text(format_text($comment, $commentformat, ['context' => $options->context]));
                }
            }
        }
        if ($showfeedback) {
            $overall = quiz_feedback_for_grade(
                (float) quiz_rescale_grade($row->quizsumgrades, $attemptobj->get_quiz(), false),
                $attemptobj->get_quiz(),
                $options->context
            );
            if (trim(strip_tags($overall)) !== '') {
                $record .= '<h3>' . s(get_string('overallfeedback', 'quiz')) . '</h3>' . $overall;
            }
        }

        $this->add_content(
            $folder . '/' . get_string('packattemptrecord', 'report_evidencereporter') . '.pdf',
            $this->html_to_pdf($cm->get_formatted_name(), $record)
        );
    }

    /**
     * Rated database entry: record PDF with the entry's content, every rating and every comment, plus attached files.
     *
     * @param string $folder
     * @param \cm_info $cm
     * @param stdClass $row Raw attempt row
     * @param stdClass $attempt Formatted attempt
     * @param string[] $compnames
     */
    private function add_data_entry(string $folder, \cm_info $cm, stdClass $row, stdClass $attempt, array $compnames): void {
        global $DB;
        $context = \context_module::instance($cm->id);
        $showmarks = $attempt->released || $attempt->canseeunreleased;
        $record = $this->attempt_header_html($cm, $attempt, $compnames);

        // The entry's content, field by field.
        $contents = $DB->get_records_sql("SELECT c.id, c.content, f.name, f.type
                                            FROM {data_content} c
                                            JOIN {data_fields} f ON f.id = c.fieldid
                                           WHERE c.recordid = :recordid
                                        ORDER BY f.id", ['recordid' => $row->datarecordid]);
        $record .= '<h3>' . s(get_string('packentrycontent', 'report_evidencereporter')) . '</h3>'
            . '<table border="1" cellpadding="4" width="100%">';
        foreach ($contents as $content) {
            $record .= $this->table_row(format_string($content->name), s($this->identity->text(trim(html_to_text(
                (string) $content->content,
                0,
                false
            )))));
        }
        $record .= '</table>';

        if ($showmarks && $attempt->ratings) {
            $record .= '<h3>' . s(get_string('packratings', 'report_evidencereporter')) . '</h3>'
                . '<table border="1" cellpadding="4" width="100%">';
            foreach ($attempt->ratings as $rating) {
                $record .= $this->table_row($rating->rater, s(data_entries::rating_text($rating->scaleid, $rating->rating)
                    . ' · ' . userdate($rating->time)));
            }
            $record .= '</table>';
        }
        if ($this->includefeedback) {
            $record .= '<h3>' . s(get_string('comments')) . '</h3>'
                . ($attempt->comments ? data_entries::comments_html($attempt->comments, $this->identity) : '<p>-</p>');
        } else {
            $record .= '<p><em>' . s(get_string('feedbackexcluded', 'report_evidencereporter')) . '</em></p>';
        }

        $fs = get_file_storage();
        foreach (array_keys($contents) as $contentid) {
            foreach ($fs->get_area_files($context->id, 'mod_data', 'content', $contentid, 'filepath, filename', false) as $file) {
                $this->add_file($folder . '/' . get_string('packsubmittedfiles', 'report_evidencereporter'), $file);
            }
        }
        $this->add_content(
            $folder . '/' . get_string('packattemptrecord', 'report_evidencereporter') . '.pdf',
            $this->html_to_pdf($cm->get_formatted_name(), $record)
        );
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
        $sql = "SELECT DISTINCT ue.*
                  FROM {competency_userevidence} ue
                  JOIN {competency_userevidencecomp} uec ON uec.userevidenceid = ue.id
                  JOIN {competency} c ON c.id = uec.competencyid
                 WHERE ue.userid = :userid AND c.competencyframeworkid = :frameworkid
              ORDER BY ue.timecreated";
        $fs = get_file_storage();
        foreach ($DB->get_records_sql($sql, ['userid' => $this->userid, 'frameworkid' => $this->frameworkid]) as $ue) {
            $folder = '03 ' . get_string('packpriorlearning', 'report_evidencereporter') . '/'
                . $this->safe_name($this->identity->text($ue->name, true));
            $compids = $DB->get_fieldset_select('competency_userevidencecomp', 'competencyid', 'userevidenceid = ?', [$ue->id]);
            $uename = $this->identity->text($ue->name);
            $html = '<h2>' . s($uename) . '</h2><table border="1" cellpadding="4" width="100%">'
                . $this->learner_rows()
                . $this->table_row(
                    get_string('competency', 'report_evidencereporter'),
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
            get_string('evidencepack', 'report_evidencereporter'),
            str_repeat('=', 40),
        ];
        foreach ($this->identity->rows() as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }
        $lines = array_merge($lines, [
            get_string('framework', 'report_evidencereporter') . ': ' . format_string($this->framework->shortname)
                . ($this->framework->idnumber ? " ({$this->framework->idnumber})" : ''),
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
    private function attempt_header_html(\cm_info $cm, stdClass $attempt, array $compnames): string {
        $date = fn(?int $time) => $time ? userdate($time) : '-';
        return '<h2>' . s($cm->get_formatted_name()) . ' - '
            . s(get_string(
                $attempt->isentry ? 'entrysummary' : 'attemptsummary',
                'report_evidencereporter',
                $attempt->attemptno
            )) . '</h2>'
            . '<table border="1" cellpadding="4" width="100%">'
            . $this->learner_rows()
            . $this->table_row(get_string('competency', 'report_evidencereporter'), s(implode('; ', $compnames)))
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
        $html = '<h1>' . s(get_string('summarytitle', 'report_evidencereporter')) . '</h1>'
            . '<table border="1" cellpadding="4" width="100%">'
            . $this->learner_rows()
            . $this->table_row(get_string('framework', 'report_evidencereporter'), s(format_string($this->framework->shortname))
                . ($this->framework->idnumber ? ' (' . s($this->framework->idnumber) . ')' : ''))
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
            . '<td width="40%">' . s(get_string('competency', 'report_evidencereporter')) . '</td>'
            . '<td width="18%">' . s(get_string('currentrating', 'report_evidencereporter')) . '</td>'
            . '<td width="22%">' . s(get_string('judgement', 'report_evidencereporter')) . '</td>'
            . '<td width="10%">' . s(get_string('evidenceitems', 'report_evidencereporter')) . '</td>'
            . '<td width="10%">' . s(get_string('attemptcount', 'report_evidencereporter')) . '</td></tr>';
        foreach ($summary->competencies as $comp) {
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

        foreach ($summary->competencies as $comp) {
            if (!$comp->evidence && !$comp->attempts) {
                continue;
            }
            $html .= '<h3>' . s($comp->idnumber . ' ' . $comp->name) . '</h3>';
            if ($comp->evidence) {
                $html .= '<table border="1" cellpadding="3" width="100%"><tr style="background-color:#e9ecef;font-weight:bold;">'
                    . '<td width="16%">' . s(get_string('timecreated', 'report_evidencereporter')) . '</td>'
                    . '<td width="' . ($this->includefeedback ? 34 : 54) . '%">'
                    . s(get_string('evidencesource', 'report_evidencereporter')) . '</td>'
                    . '<td width="14%">' . s(get_string('rating', 'report_evidencereporter')) . '</td>'
                    . '<td width="16%">' . s(get_string('assessor', 'report_evidencereporter')) . '</td>'
                    . ($this->includefeedback ? '<td width="20%">' . s(get_string('feedback', 'report_evidencereporter'))
                        . '</td>' : '')
                    . '</tr>';
                foreach ($comp->evidence as $item) {
                    $source = s($item->source) . ($item->description ? '<br><small>' . s($item->description) . '</small>' : '')
                        . ($item->grade ? '<br>' . s(get_string('activitygrade', 'report_evidencereporter') . ': '
                            . $item->grade) : '')
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
            if ($comp->attempts) {
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
        }
        $html .= '<p>&nbsp;</p><p>' . s(get_string('reviewedby', 'report_evidencereporter')) . ': ________________________ &nbsp; '
            . s(get_string('signature', 'report_evidencereporter')) . ': __________________ &nbsp; '
            . s(get_string('date')) . ': ____________</p>';
        return $html;
    }

    /**
     * Names of competencies, "ID number name".
     *
     * @param int[] $ids
     * @return string[]
     */
    private function competency_names(array $ids): array {
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
     * Rows identifying the learner: name, username, ID number, reference (redaction applied).
     *
     * @return string HTML
     */
    private function learner_rows(): string {
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
    private function table_row(string $label, string $valuehtml): string {
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
    private function html_to_pdf(string $title, string $html): string {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');
        $pdf = new \pdf();
        $pdf->SetTitle($title);
        $pdf->SetAuthor(format_string(get_site()->fullname));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->SetFont('freesans', '', 9);
        $pdf->AddPage();
        // Images and embedded media need a logged-in browser, so keep text only.
        $html = preg_replace('/<img[^>]*>/i', '', $html);
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            $this->renderedhtml[] = $html;
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
    private function html_document(string $title, string $body): string {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . s($title) . '</title></head><body>'
            . $body . '</body></html>';
    }

    /**
     * Add generated content.
     *
     * @param string $path
     * @param string $content
     */
    private function add_content(string $path, string $content): void {
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
    private function add_file(string $folder, stored_file $file): void {
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
    private function safe_name(string $name, int $max = 80): string {
        $name = clean_filename(html_entity_decode(strip_tags($name), ENT_QUOTES, 'UTF-8'));
        $name = trim(preg_replace('/[\\\\\/:*?"<>|]+/', '-', $name), " .-");
        return \core_text::substr($name === '' ? 'item' : $name, 0, $max);
    }
}
