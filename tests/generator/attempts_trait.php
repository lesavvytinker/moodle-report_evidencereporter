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

/**
 * Test helper: real assignment and quiz attempts (graded, reopened, failed and passed).
 *
 * @package    report_evidencereporter
 * @category   test
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait report_evidencereporter_attempts_trait {
    /**
     * Grade an assignment attempt through the assignment's own grading code, optionally reopening for another attempt.
     *
     * @param stdClass $assigninstance Module instance from create_module('assign', ...)
     * @param int $userid
     * @param int $markerid
     * @param float $grade
     * @param string $feedback
     * @param bool $reopen
     * @param string|null $file Path relative to dirroot of a file to submit
     */
    protected function grade_assign_attempt(
        stdClass $assigninstance,
        int $userid,
        int $markerid,
        float $grade,
        string $feedback,
        bool $reopen = false,
        ?string $file = null
    ): void {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $submission = ['userid' => $userid, 'cmid' => $assigninstance->cmid, 'onlinetext' => 'My answer'];
        if ($file) {
            $submission['file'] = $file;
        }
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission($submission);
        [$course, $cm] = get_course_and_cm_from_cmid($assigninstance->cmid, 'assign');
        $previous = $USER;
        $this->setUser($markerid);
        $assign = new assign(context_module::instance($cm->id), $cm, $course);
        $assign->save_grade($userid, (object) [
            'grade' => $grade,
            'attemptnumber' => -1,
            'addattempt' => $reopen ? 1 : 0,
            'applytoall' => 0,
            'sendstudentnotifications' => 0,
            'assignfeedbackcomments_editor' => ['text' => $feedback, 'format' => FORMAT_HTML],
        ]);
        $this->setUser($previous);
    }

    /**
     * Create a quiz with one true/false question (correct answer: True).
     *
     * @param int $courseid
     * @param array $settings Extra quiz settings
     * @return stdClass quiz instance
     */
    protected function create_quiz_with_question(int $courseid, array $settings = []): stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $quiz = $this->getDataGenerator()->create_module('quiz', $settings + ['course' => $courseid, 'grade' => 10,
            'sumgrades' => 1]);
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $qgen->create_question_category();
        $question = $qgen->create_question('truefalse', null, ['category' => $cat->id]);
        quiz_add_quiz_question($question->id, $quiz);
        \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
        return $quiz;
    }

    /**
     * Make and finish a quiz attempt.
     *
     * @param stdClass $quiz
     * @param int $userid
     * @param bool $correct
     */
    protected function attempt_quiz(stdClass $quiz, int $userid, bool $correct): void {
        global $USER;
        $previous = $USER;
        $this->setUser($userid);
        $qgen = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $attempt = $qgen->create_attempt($quiz->id, $userid);
        $qgen->submit_responses($attempt->id, [1 => $correct ? 'True' : 'False'], false, true);
        $this->setUser($previous);
    }
}
