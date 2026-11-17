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

namespace report_evidencereporter\local\source;

use moodle_url;
use report_evidencereporter\local\evidence_pack;
use report_evidencereporter\local\feedback_visibility;
use stdClass;

/**
 * Quizzes: links to the review of the learner's latest attempt, every finished attempt under the quiz's own review
 * rules, and in the pack a record of every question, answer, mark and feedback.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz extends base {
    /** @var array Quiz records cached by ID. */
    private static array $quizzes = [];

    #[\Override]
    public function get_modname(): string {
        return 'quiz';
    }

    #[\Override]
    public function get_description(): string {
        return get_string('sourcedesc_quiz', 'report_evidencereporter');
    }

    #[\Override]
    public function get_row_fields_sql(string $instance, string $userid): array {
        return ['quizattemptid' => "(SELECT MAX(erqa.id) FROM {quiz_attempts} erqa
                                      WHERE erqa.quiz = {$instance} AND erqa.userid = {$userid}
                                        AND erqa.state = 'finished')"];
    }

    #[\Override]
    public function get_url(stdClass $row): ?moodle_url {
        if (!empty($row->quizattemptid)) {
            return new moodle_url('/mod/quiz/review.php', ['attempt' => (int) $row->quizattemptid, 'showall' => 1]);
        }
        return new moodle_url('/mod/quiz/view.php', ['id' => (int) $row->cmid]);
    }

    #[\Override]
    public function has_attempts(): bool {
        return true;
    }

    #[\Override]
    public function format_attempt(stdClass $row, stdClass $result, bool $canseeunreleased, bool $viewfullnames): stdClass {
        $value = null;
        $overallfeedback = '';
        $showmarks = true;
        $showfeedback = true;
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $quiz = self::get_quiz((int) $row->quizid);
        // The attempt review with every question on one page: the learner's answers with specific, general and
        // overall feedback and any marker comments, under the quiz's own review rules.
        $result->url = new moodle_url('/mod/quiz/review.php', ['attempt' => $row->attemptid, 'showall' => 1]);
        if ($quiz && $row->quizsumgrades !== null) {
            $value = (float) quiz_rescale_grade($row->quizsumgrades, $quiz, false);
        }
        $released = feedback_visibility::is_released((object) ['gradeitemhidden' => $row->gradeitemhidden]);
        if ($quiz && !$canseeunreleased) {
            // What the quiz itself would show the learner right now.
            $attempt = (object) ['state' => 'finished', 'timefinish' => (int) $row->timesubmitted];
            $options = \mod_quiz\question\display_options::make_from_quiz($quiz, quiz_attempt_state($quiz, $attempt));
            $showmarks = $released && $options->marks >= \question_display_options::MARK_AND_MAX;
            $showfeedback = $released && ($options->feedback || $options->generalfeedback || $options->manualcomment
                || $options->overallfeedback);
            $released = $showmarks;
        }
        $result->released = $released;
        $result->feedbacklink = true;
        return (object) ['value' => $value, 'showmarks' => $showmarks, 'showfeedback' => $showfeedback,
            'overallfeedback' => $overallfeedback];
    }

    #[\Override]
    public function add_attempt_to_pack(
        evidence_pack $pack,
        string $folder,
        \cm_info $cm,
        stdClass $row,
        stdClass $attempt,
        array $compnames
    ): void {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $showmarks = $attempt->released || $attempt->canseeunreleased;
        $showfeedback = $pack->includes_feedback() && strpos($attempt->feedback, 'er-feedbacklink') !== false;
        $record = $pack->attempt_header_html($cm, $attempt, $compnames);
        if (!$pack->includes_feedback()) {
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
            $record .= $pack->table_row(
                get_string('packresponse', 'report_evidencereporter'),
                s($pack->get_identity()->text($qa->get_response_summary() ?? '-'))
            );
            if ($showmarks) {
                $record .= $pack->table_row(get_string('status'), s($qa->get_state_string(true)));
                $record .= $pack->table_row(
                    get_string('packmark', 'report_evidencereporter'),
                    s($qa->format_mark(2) . ' / ' . $qa->format_max_mark(2))
                );
            }
            if ($showfeedback) {
                $record .= $pack->table_row(get_string('rightanswer', 'question'), s($qa->get_right_answer_summary() ?? ''));
            }
            $record .= '</table>';
            if ($showfeedback) {
                $feedback = $question->get_renderer($PAGE)->feedback($qa, $options);
                if (trim(strip_tags($feedback)) !== '') {
                    $record .= '<p><strong>' . s(get_string('feedback', 'report_evidencereporter')) . '</strong></p>'
                        . $pack->get_identity()->text($feedback);
                }
                [$comment, $commentformat] = $qa->get_manual_comment();
                if ($comment !== null && trim(strip_tags($comment)) !== '') {
                    $record .= '<p><strong>' . s(get_string('packcomment', 'report_evidencereporter')) . '</strong></p>'
                        . $pack->get_identity()->text(format_text($comment, $commentformat, ['context' => $options->context]));
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

        $pack->add_content(
            $folder . '/' . get_string('packattemptrecord', 'report_evidencereporter') . '.pdf',
            $pack->html_to_pdf($cm->get_formatted_name(), $record)
        );
    }

    /**
     * Quiz record (with review options), cached.
     *
     * @param int $quizid
     * @return stdClass|null
     */
    private static function get_quiz(int $quizid): ?stdClass {
        global $DB;
        if (!array_key_exists($quizid, self::$quizzes)) {
            self::$quizzes[$quizid] = $DB->get_record('quiz', ['id' => $quizid]) ?: null;
        }
        return self::$quizzes[$quizid];
    }

    /**
     * Reset the quiz cache (unit tests).
     */
    public static function reset_cache(): void {
        self::$quizzes = [];
    }
}
