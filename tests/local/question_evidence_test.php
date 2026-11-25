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

declare(strict_types=1);

namespace report_evidencereporter\local;

use stdClass;

#[\PHPUnit\Framework\Attributes\CoversClass(question_evidence::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(summary::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(question_mappings::class)]
/**
 * Tests for supporting evidence from quiz questions (needs the Question competencies plugin).
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\question_evidence
 * @covers     \report_evidencereporter\local\summary
 * @covers     \report_evidencereporter\local\question_mappings
 */
final class question_evidence_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        if (!question_evidence::is_available()) {
            $this->markTestSkipped('No plugin that maps questions to competencies is installed.');
        }
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        feedback_visibility::reset_cache();
        attempts::reset_cache();
        rating::reset_cache();
        activity_grade::reset_cache();
        $this->resetAfterTest();
        set_config('enabled', 1, 'core_competency');
    }

    /**
     * A framework with two PCs, a quiz of three true/false questions (pass mark 7 / 10) in a course, q1 mapped to PC1
     * and q2 to PC2, the quiz linked to PC1 only, and a learner with two attempts: 1/3 (not satisfactory) then 3/3.
     *
     * @param string|null $source The mapping plugin to map with (null: the first installed)
     * @return array [framework, learner, course, quiz, pc1 id, pc2 id, questions]
     */
    private function setup_data(?string $source = null): array {
        $gen = $this->getDataGenerator();
        $cgen = $gen->get_plugin_generator('core_competency');
        $qgen = $gen->get_plugin_generator('core_question');

        $framework = $cgen->create_framework(['shortname' => 'FW']);
        $pc1 = (int) $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => 'PC1',
            'shortname' => 'PC 1'])->get('id');
        $pc2 = (int) $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => 'PC2',
            'shortname' => 'PC 2'])->get('id');
        $course = $gen->create_course();
        $learner = $gen->create_and_enrol($course, 'student');
        foreach ([$pc1, $pc2] as $pc) {
            $cgen->create_course_competency(['courseid' => $course->id, 'competencyid' => $pc]);
        }

        $quiz = $gen->create_module('quiz', ['course' => $course->id, 'name' => 'Knowledge quiz', 'grade' => 10,
            'sumgrades' => 3, 'gradepass' => 7]);
        $cat = $qgen->create_question_category(['contextid' => \context_module::instance($quiz->cmid)->id]);
        $questions = [];
        foreach (['Hazards', 'Controls', 'Reporting'] as $name) {
            $questions[] = $q = $qgen->create_question('truefalse', null, ['category' => $cat->id, 'name' => $name]);
            quiz_add_quiz_question($q->id, $quiz);
        }
        \mod_quiz\quiz_settings::create((int) $quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
        $source = $source ?? question_mappings::get_sources()[0];
        $this->map($source, $questions[0], $pc1, (int) $course->id);
        $this->map($source, $questions[1], $pc2, (int) $course->id);
        $cgen->create_course_module_competency(['cmid' => $quiz->cmid, 'competencyid' => $pc1]);

        $quizgen = $gen->get_plugin_generator('mod_quiz');
        $this->setUser($learner);
        $attempt = $quizgen->create_attempt((int) $quiz->id, (int) $learner->id);
        $quizgen->submit_responses((int) $attempt->id, [1 => 'True', 2 => 'False', 3 => 'False'], false, true);
        $attempt = $quizgen->create_attempt((int) $quiz->id, (int) $learner->id);
        $quizgen->submit_responses((int) $attempt->id, [1 => 'True', 2 => 'True', 3 => 'True'], false, true);
        $this->setAdminUser();
        return [$framework, $learner, $course, $quiz, $pc1, $pc2, $questions];
    }

    /**
     * Map a question to a competency with one of the mapping plugins (the test is skipped without it).
     *
     * @param string $source Component
     * @param stdClass $question
     * @param int $competencyid
     * @param int $courseid
     */
    private function map(string $source, stdClass $question, int $competencyid, int $courseid): void {
        global $DB;
        if (!in_array($source, question_mappings::get_sources())) {
            $this->markTestSkipped($source . ' is not installed.');
        }
        if ($source === question_mappings::QBANK_COMPETENCIES) {
            \qbank_competencies\local\api::add_competency(question_mappings::get_entryid((int) $question->id), $competencyid);
        } else {
            $DB->insert_record('qbank_comp_ext_qmap', ['courseid' => $courseid, 'questionid' => $question->id,
                'competencyid' => $competencyid, 'timecreated' => time()]);
        }
    }

    /**
     * Each question sits under its attempt with the quiz result, and the flags show where the two disagree.
     */
    public function test_groups_and_flags(): void {
        [$framework, $learner, , $quiz, $pc1, $pc2] = $this->setup_data();

        $summary = summary::build((int) $learner->id, $framework->get('id'));
        $bycomp = [];
        foreach ($summary->competencies as $comp) {
            $bycomp[(int) $comp->id] = $comp;
        }

        // PC1: two attempts, the quiz is linked. Attempt 1 wasn't satisfactory, but the question was passed.
        $groups = $bycomp[$pc1]->questions;
        $this->assertCount(2, $groups);
        [$first, $second] = $groups;
        $this->assertSame('Knowledge quiz', $first->activity);
        $this->assertSame('Attempt 1', $first->label);
        $this->assertSame('fail', $first->quizoutcome);
        $this->assertSame('Not satisfactory', $first->quizresult);
        $this->assertSame([], $first->flags);
        $this->assertCount(1, $first->questions);
        $question = $first->questions[0];
        $this->assertSame('1', $question->number);
        $this->assertSame('Hazards', $question->name);
        $this->assertSame('passed', $question->outcomekey);
        $this->assertSame('1.00 / 1.00', $question->mark);
        $this->assertSame(['Passed, but the quiz result is Not satisfactory'], $question->flags);
        $this->assertStringContainsString('/mod/quiz/reviewquestion.php', $question->reviewurl);
        $this->assertStringContainsString('slot=1', $question->reviewurl);
        $this->assertStringContainsString('/mod/quiz/comment.php', $question->commenturl);

        $this->assertSame('Attempt 2 (latest)', $second->label);
        $this->assertSame('Satisfactory', $second->quizresult);
        $this->assertSame([], $second->questions[0]->flags);

        // PC2: the quiz isn't linked to it. Attempt 1: not passed in a not satisfactory quiz, so no question flag.
        [$first, $second] = $bycomp[$pc2]->questions;
        $this->assertSame(["Quiz isn't linked to this competency"], $first->flags);
        $this->assertSame('notpassed', $first->questions[0]->outcomekey);
        $this->assertSame([], $first->questions[0]->flags);
        $this->assertSame('passed', $second->questions[0]->outcomekey);

        // PC2's only evidence is from questions, and it still counts as assessed.
        $this->assertContains($pc2, summary::get_assessed_competencyids((int) $learner->id));
    }

    /**
     * A question not passed in a satisfactory quiz is flagged.
     */
    public function test_not_passed_in_satisfactory_quiz(): void {
        [$framework, $learner, , , , $pc2] = $this->setup_data();
        // Lower the pass mark to 6 / 10, so 2 of 3 right (6.67) is satisfactory.
        global $DB;
        $DB->set_field('grade_items', 'gradepass', 6, ['itemtype' => 'mod', 'itemmodule' => 'quiz']);
        activity_grade::reset_cache();
        attempts::reset_cache();

        $gen = $this->getDataGenerator();
        $quizgen = $gen->get_plugin_generator('mod_quiz');
        $quiz = $DB->get_record('quiz', []);
        $this->setUser($learner);
        $attempt = $quizgen->create_attempt((int) $quiz->id, (int) $learner->id);
        $quizgen->submit_responses((int) $attempt->id, [1 => 'True', 2 => 'False', 3 => 'True'], false, true);
        $this->setAdminUser();

        $groups = question_evidence::get_for_user((int) $learner->id, [$pc2], false)[$pc2];
        $last = end($groups);
        $this->assertSame('Satisfactory', $last->quizresult);
        $this->assertSame(['Not passed, though the quiz result is Satisfactory'], $last->questions[0]->flags);
    }

    /**
     * A teacher in another course sees none of it; the summary page and PDF show the section.
     */
    public function test_access_and_output(): void {
        global $DB, $PAGE;
        [$framework, $learner] = $this->setup_data();
        $gen = $this->getDataGenerator();

        $othercourse = $gen->create_course();
        $teacher = $gen->create_and_enrol($othercourse, 'editingteacher');
        $gen->enrol_user($learner->id, $othercourse->id, 'student');
        $this->setUser($teacher);
        attempts::reset_cache();
        $this->assertSame([], question_evidence::get_competencyids((int) $learner->id));

        $this->setAdminUser();
        attempts::reset_cache();
        $summary = summary::build((int) $learner->id, $framework->get('id'));
        $page = new \report_evidencereporter\output\summary_page(
            $DB->get_record('user', ['id' => $learner->id]),
            $framework->to_record(),
            $summary
        );
        $output = $PAGE->get_renderer('core');
        $html = $output->render_from_template('report_evidencereporter/summary', $page->export_for_template($output));
        $this->assertStringContainsString('Supporting evidence: quiz questions', $html);
        $this->assertStringContainsString('Passed, but the quiz result is Not satisfactory', $html);
        $this->assertStringContainsString('reviewquestion.php', $html);

        // Turning the attempts section off hides it too.
        set_config('showattempts', 0, 'report_evidencereporter');
        $html = $output->render_from_template('report_evidencereporter/summary', $page->export_for_template($output));
        $this->assertStringNotContainsString('Supporting evidence: quiz questions', $html);
    }

    /**
     * Without mapped questions nothing changes.
     */
    public function test_no_mappings(): void {
        $learner = $this->getDataGenerator()->create_user();
        $this->assertSame([], question_evidence::get_for_user((int) $learner->id, [1, 2], false));
        $this->assertSame([], question_evidence::get_competencyids((int) $learner->id));
    }

    /**
     * Question Bank Competency Mapping (qbank_comp_ext) works the same way. Its mappings are per question version, so
     * a mapping on an older version still counts after the question is edited.
     */
    public function test_qbank_comp_ext(): void {
        [$framework, $learner, $course, , $pc1, $pc2, $questions] = $this->setup_data(question_mappings::QBANK_COMP_EXT);
        $groups = question_evidence::get_for_user((int) $learner->id, [$pc1, $pc2], false);
        $this->assertCount(2, $groups[$pc1]);
        $this->assertSame('Hazards', $groups[$pc1][0]->questions[0]->name);
        $this->assertSame(['Passed, but the quiz result is Not satisfactory'], $groups[$pc1][0]->questions[0]->flags);
        $this->assertSame('Controls', $groups[$pc2][0]->questions[0]->name);

        // A new version of question 3, mapped only on its first version, counts for PC2 too.
        $this->map(question_mappings::QBANK_COMP_EXT, $questions[2], $pc2, (int) $course->id);
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $v2 = $qgen->update_question($questions[2], null, ['name' => 'Reporting v2']);
        $entryid = question_mappings::get_entryid((int) $v2->id);
        $this->assertSame([$pc2], array_keys(question_mappings::get_for_entries([$entryid])[$entryid]));
        $groups = question_evidence::get_for_user((int) $learner->id, [$pc2], false);
        $this->assertSame(['Controls', 'Reporting'], array_map(fn($q) => $q->name, end($groups[$pc2])->questions));
    }

    /**
     * With both plugins installed, their mappings are combined, and a mapping in both counts once.
     */
    public function test_both_sources(): void {
        if (count(question_mappings::get_sources()) < 2) {
            $this->markTestSkipped('Needs both qbank_competencies and qbank_comp_ext.');
        }
        [, $learner, $course, , $pc1, $pc2, $questions] = $this->setup_data(question_mappings::QBANK_COMPETENCIES);
        $this->map(question_mappings::QBANK_COMP_EXT, $questions[0], $pc1, (int) $course->id);
        $this->map(question_mappings::QBANK_COMP_EXT, $questions[2], $pc2, (int) $course->id);
        $groups = question_evidence::get_for_user((int) $learner->id, [$pc1, $pc2], false);
        $this->assertCount(1, $groups[$pc1][0]->questions, 'Mapped in both plugins, shown once');
        $this->assertSame(['Controls', 'Reporting'], array_map(fn($q) => $q->name, $groups[$pc2][0]->questions));
        $this->assertStringContainsString('Question competencies', implode(', ', question_mappings::get_source_names()));
    }

    /**
     * With Question competencies turned off, Question Bank Competency Mapping alone is enough, and a question passes
     * only with full marks.
     */
    public function test_qbank_comp_ext_alone(): void {
        if (count(question_mappings::get_sources()) < 2) {
            $this->markTestSkipped('Needs both qbank_competencies and qbank_comp_ext.');
        }
        set_config('passfraction', 0.5, 'qbank_competencies');
        \core\plugininfo\qbank::enable_plugin('competencies', 0);
        $this->assertSame([question_mappings::QBANK_COMP_EXT], question_mappings::get_sources());
        $this->assertSame(1.0, question_mappings::get_pass_fraction());
        [, $learner, , , $pc1] = $this->setup_data(question_mappings::QBANK_COMP_EXT);
        $groups = question_evidence::get_for_user((int) $learner->id, [$pc1], false);
        $this->assertCount(2, $groups[$pc1]);
    }
}
