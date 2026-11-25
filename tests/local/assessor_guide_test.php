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

#[\PHPUnit\Framework\Attributes\CoversClass(assessor_guide::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(tool_details::class)]
/**
 * Tests for blank assessor guides and assessment tool details.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\assessor_guide
 * @covers     \report_evidencereporter\local\tool_details
 */
final class assessor_guide_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        attempts::reset_cache();
        activity_grade::reset_cache();
        $this->resetAfterTest();
        set_config('enabled', 1, 'core_competency');
        $this->setAdminUser();
    }

    /**
     * A quiz with true/false, multiple choice, two essays (one with Information for graders), a description and a
     * random question from a pool of two; linked to PC1, with the true/false question mapped to PC1 and PC2.
     *
     * @return array [course, quiz (with cmid), pc1 id, pc2 id, framework]
     */
    private function setup_quiz(): array {
        global $DB;
        $gen = $this->getDataGenerator();
        $cgen = $gen->get_plugin_generator('core_competency');
        $qgen = $gen->get_plugin_generator('core_question');

        $framework = $cgen->create_framework(['shortname' => 'Detention']);
        $unit = $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => 'CSCSAS001',
            'shortname' => 'Maintain security']);
        $pc1 = (int) $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => 'PC1.1',
            'shortname' => 'Identify hazards', 'parentid' => $unit->get('id')])->get('id');
        $pc2 = (int) $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => 'PC1.2',
            'shortname' => 'Report incidents', 'parentid' => $unit->get('id')])->get('id');

        $course = $gen->create_course(['fullname' => 'Cert III']);
        foreach ([$pc1, $pc2] as $pc) {
            $cgen->create_course_competency(['courseid' => $course->id, 'competencyid' => $pc]);
        }
        $quiz = $gen->create_module('quiz', ['course' => $course->id, 'name' => 'Knowledge quiz', 'grade' => 10,
            'gradepass' => 7, 'timelimit' => 1800, 'attempts' => 2, 'intro' => '<p>Answer every question.</p>']);
        $cat = $qgen->create_question_category(['contextid' => \context_module::instance((int) $quiz->cmid)->id]);

        $tf = $qgen->create_question('truefalse', null, ['category' => $cat->id, 'name' => 'Hazard ID']);
        $mc = $qgen->create_question('multichoice', 'one_of_four', ['category' => $cat->id, 'name' => 'Pick one']);
        $essay = $qgen->create_question('essay', 'editor', ['category' => $cat->id, 'name' => 'Explain controls']);
        $DB->set_field(
            'qtype_essay_options',
            'graderinfo',
            '<p>Mentions elimination and PPE.</p>',
            ['questionid' => $essay->id]
        );
        $bare = $qgen->create_question('essay', 'editor', ['category' => $cat->id, 'name' => 'Describe a report']);
        $desc = $qgen->create_question('description', null, ['category' => $cat->id, 'name' => 'Scenario']);
        foreach ([$desc, $tf, $mc, $essay, $bare] as $question) {
            quiz_add_quiz_question($question->id, $quiz);
        }
        $pool = $qgen->create_question_category(['contextid' => $cat->contextid, 'name' => 'Pool A']);
        $qgen->create_question('truefalse', null, ['category' => $pool->id, 'name' => 'Pool question 1']);
        $qgen->create_question('truefalse', null, ['category' => $pool->id, 'name' => 'Pool question 2']);
        \mod_quiz\quiz_settings::create((int) $quiz->id)->get_structure()->add_random_questions(0, 1, ['filter' => [
            'category' => ['jointype' => 1, 'values' => [$pool->id], 'filteroptions' => ['includesubcategories' => false]],
        ]]);
        \mod_quiz\quiz_settings::create((int) $quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();

        $cgen->create_course_module_competency(['cmid' => $quiz->cmid, 'competencyid' => $pc1]);
        if (question_evidence::is_available()) {
            \qbank_competencies\local\api::set_competencies(
                \qbank_competencies\local\api::get_entryid((int) $tf->id),
                [$pc1, $pc2]
            );
        }
        return [$course, $quiz, $pc1, $pc2, $framework];
    }

    /**
     * The guide has the tool details, every question with its model answer and boxes, the matrix and sign-off.
     */
    public function test_guide_content(): void {
        [, $quiz] = $this->setup_quiz();
        tool_details::save((int) $quiz->cmid, (object) ['toolversion' => 'v2.1', 'reviewdate' => mktime(0, 0, 0, 11, 1, 2026),
            'reviewedby' => 'J. Validator', 'conditions' => 'Simulated custodial environment', 'resources' => 'Open book',
            'instructions' => 'Supervise closely.', 'adjustments' => 'Extra time may be given.']);

        $cm = get_fast_modinfo($quiz->course)->get_cm((int) $quiz->cmid);
        $guide = new assessor_guide($cm);
        $html = $guide->build_html();

        foreach (
            ['Assessor guide: Knowledge quiz', 'v2.1', 'J. Validator', 'Simulated custodial environment', 'Open book',
                'Supervise closely.', 'Extra time may be given.', 'Answer every question.', '30 mins',
                '7.00 / 10.00', 'CSCSAS001 Maintain security (Detention)',
                'Learner name', 'Adjustments made for this learner'] as $expected
        ) {
            $this->assertStringContainsString($expected, $html);
        }
        // Questions: the description has no answer section; numbering skips it.
        $this->assertStringContainsString('Scenario', $html);
        $this->assertStringContainsString('Question 1 · 1.00 marks · Hazard ID', $html);
        $this->assertStringContainsString('Model answer</strong><br>True', $html);
        $this->assertStringContainsString('Question 2 · 1.00 marks · Pick one', $html);
        $this->assertStringContainsString('Model answer</strong><br>One', $html);
        $this->assertStringContainsString('Mentions elimination and PPE.', $html);
        $this->assertStringContainsString('No model answer recorded', $html);
        $this->assertStringContainsString('Specific feedback', $html);
        $this->assertStringContainsString('</span> Satisfactory', $html);
        $this->assertStringContainsString('</span> Not satisfactory', $html);
        $this->assertStringNotContainsString('Question i', $html);
        // The random question lists its whole pool.
        $this->assertStringContainsString('One question drawn at random for each learner, from 2 in Pool A', $html);
        $this->assertStringContainsString('Question 5.1', $html);
        $this->assertStringContainsString('Question 5.2', $html);
        // Outcome summary: one row per allowed attempt; sign-off.
        $this->assertStringContainsString('Outcome summary', $html);
        $this->assertStringContainsString('Assessor: I declare', $html);

        $notes = $guide->get_review_notes();
        $this->assertContains('Question 4 has no model answer.', $notes);
        $this->assertContains('Question 5 is drawn at random: not every learner gets the same question.', $notes);
        $this->assertNotContains('No tool version (activity settings, Assessment tool details).', $notes);

        if (question_evidence::is_available()) {
            $this->assertStringContainsString('Mapped competencies (supporting evidence): PC1.1 Identify hazards; '
                . 'PC1.2 Report incidents', $html);
            $this->assertContains('Question 1 is mapped to a competency the quiz isn\'t linked to.', $notes);
            // Matrix: PC1.1 linked with question 1, PC1.2 not linked.
            $this->assertMatchesRegularExpression('#PC1\.1 Identify hazards</td><td width="35%">1</td>'
                . '<td width="20%">Yes#', $html);
            $this->assertMatchesRegularExpression('#PC1\.2 Report incidents</td><td width="35%">1</td>'
                . '<td width="20%">No#', $html);
        }

        $pdf = $guide->build_pdf();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringEndsWith('.pdf', $guide->get_filename());
        $this->assertStringContainsString('v2.1', $guide->get_filename());
    }

    /**
     * Missing tool details show as review notes.
     */
    public function test_review_notes_for_missing_details(): void {
        [, $quiz] = $this->setup_quiz();
        $guide = new assessor_guide(get_fast_modinfo($quiz->course)->get_cm((int) $quiz->cmid));
        $html = $guide->build_html();
        $notes = $guide->get_review_notes();
        $this->assertContains('No tool version (activity settings, Assessment tool details).', $notes);
        $this->assertContains('No review date: record when the tool was reviewed before use.', $notes);
        $this->assertContains('No reasonable adjustment guidance (activity settings, Assessment tool details).', $notes);
        $this->assertStringContainsString('Review notes', $html);
        $this->assertEmpty(array_filter($notes, fn($note) => str_contains($note, 'example text')));
    }

    /**
     * Example text left in the tool details is printed as it is and listed in the review notes.
     */
    public function test_review_notes_for_example_text(): void {
        [, $quiz] = $this->setup_quiz();
        $cmid = (int) $quiz->cmid;
        tool_details::save($cmid, (object) [
            'toolversion' => '1.0',
            'conditions' => tool_details::example('conditions'),
            'resources' => 'Open book: learners may use their learner guide.',
            'instructions' => tool_details::example('instructions') . ' Plus our own note.',
            'adjustments' => '',
        ]);
        $this->assertTrue(tool_details::is_example(tool_details::example('resources')));
        $this->assertFalse(tool_details::is_example('Open book: learners may use their learner guide.'));

        $guide = new assessor_guide(get_fast_modinfo($quiz->course)->get_cm($cmid));
        $html = $guide->build_html();
        $notes = $guide->get_review_notes();
        $this->assertContains(
            'Assessment conditions still has example text (activity settings, Assessment tool details).',
            $notes
        );
        $this->assertContains('Instructions for the assessor still has example text (activity settings, '
            . 'Assessment tool details).', $notes);
        $this->assertNotContains(
            'Resources allowed still has example text (activity settings, Assessment tool details).',
            $notes
        );
        $this->assertStringContainsString('EXAMPLE: Simulated environment', $html);
    }

    /**
     * Only staff with the capability may download it; only quizzes are supported.
     */
    public function test_access(): void {
        [$course, $quiz] = $this->setup_quiz();
        $gen = $this->getDataGenerator();
        $cm = get_fast_modinfo($course)->get_cm((int) $quiz->cmid);
        $this->assertTrue(assessor_guide::can_view($cm));

        $teacher = $gen->create_and_enrol($course, 'teacher');
        $student = $gen->create_and_enrol($course, 'student');
        $this->setUser($teacher);
        $this->assertTrue(assessor_guide::can_view($cm));
        $this->setUser($student);
        $this->assertFalse(assessor_guide::can_view($cm));

        $assign = $gen->create_module('assign', ['course' => $course->id]);
        $this->assertFalse(assessor_guide::supports('assign'));
        $this->expectException(\coding_exception::class);
        new assessor_guide(get_fast_modinfo($course)->get_cm((int) $assign->cmid));
    }

    /**
     * Tool details: saved from the activity form, empty ones removed, deleted with the activity.
     */
    public function test_tool_details_lifecycle(): void {
        global $DB;
        [$course, $quiz] = $this->setup_quiz();
        $cmid = (int) $quiz->cmid;

        tool_details::save_from_form((object) ['coursemodule' => $cmid, 'modulename' => 'quiz',
            'ertool_toolversion' => ' 1.0 ', 'ertool_reviewdate' => 0, 'ertool_reviewedby' => '',
            'ertool_conditions' => 'Workplace', 'ertool_resources' => '', 'ertool_instructions' => '',
            'ertool_adjustments' => '']);
        $details = tool_details::get($cmid);
        $this->assertSame('1.0', $details->toolversion);
        $this->assertSame('Workplace', $details->conditions);

        // A form without the section (e.g. a module type Evidence Reporter doesn't read) changes nothing.
        tool_details::save_from_form((object) ['coursemodule' => $cmid, 'modulename' => 'label']);
        $this->assertSame('1.0', tool_details::get($cmid)->toolversion);

        tool_details::save($cmid, (object) []);
        $this->assertFalse($DB->record_exists(tool_details::TABLE, ['cmid' => $cmid]));

        tool_details::save($cmid, (object) ['toolversion' => '2']);
        course_delete_module($cmid);
        $this->assertFalse($DB->record_exists(tool_details::TABLE, ['cmid' => $cmid]));
    }

    /**
     * The settings form gets the section for activity types Evidence Reporter reads.
     */
    public function test_form_elements(): void {
        global $CFG;
        require_once($CFG->libdir . '/formslib.php');
        [, $quiz] = $this->setup_quiz();
        tool_details::save((int) $quiz->cmid, (object) ['toolversion' => '3.0']);

        $mform = new \MoodleQuickForm('test', 'post', '');
        tool_details::add_form_elements($mform, 'quiz', (int) $quiz->cmid);
        $this->assertTrue($mform->elementExists('ertool_toolversion'));
        $this->assertTrue($mform->elementExists('ertool_adjustments'));
        $this->assertTrue($mform->elementExists('ertool_guide'));
        $this->assertSame('3.0', $mform->getElement('ertool_toolversion')->getValue());

        // Saved details are shown as they are, with no example text.
        $this->assertFalse($mform->elementExists('ertool_examples'));
        $this->assertSame('', $mform->getElement('ertool_resources')->getValue());

        $mform = new \MoodleQuickForm('test2', 'post', '');
        tool_details::add_form_elements($mform, 'label', 0);
        $this->assertFalse($mform->elementExists('ertool_toolversion'));
    }

    /**
     * A quiz with nothing saved (new, or existing) starts from marked example text; the print button needs a saved quiz.
     */
    public function test_form_examples(): void {
        global $CFG;
        require_once($CFG->libdir . '/formslib.php');
        [$course, $quiz] = $this->setup_quiz();

        $mform = new \MoodleQuickForm('new', 'post', '');
        tool_details::add_form_elements($mform, 'quiz', 0);
        $this->assertTrue($mform->elementExists('ertool_examples'));
        $this->assertFalse($mform->elementExists('ertool_guide'));
        $this->assertSame(
            'EXAMPLE: Open book: learners may use their learner guide.',
            $mform->getElement('ertool_resources')->getValue()
        );
        $this->assertEmpty($mform->getElement('ertool_toolversion')->getValue());

        $mform = new \MoodleQuickForm('existing', 'post', '');
        tool_details::add_form_elements($mform, 'quiz', (int) $quiz->cmid);
        $this->assertTrue($mform->elementExists('ertool_examples'));
        $this->assertTrue($mform->elementExists('ertool_guide'));
        $this->assertTrue(tool_details::is_example($mform->getElement('ertool_conditions')->getValue()));

        // Other activity types read by Evidence Reporter get the section but no quiz examples or button.
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $mform = new \MoodleQuickForm('assign', 'post', '');
        tool_details::add_form_elements($mform, 'assign', (int) $assign->cmid);
        $this->assertTrue($mform->elementExists('ertool_conditions'));
        $this->assertFalse($mform->elementExists('ertool_examples'));
        $this->assertFalse($mform->elementExists('ertool_guide'));

        // Students don't get the button (they can't see the form anyway, but the check is the guide's own).
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));
        $mform = new \MoodleQuickForm('student', 'post', '');
        tool_details::add_form_elements($mform, 'quiz', (int) $quiz->cmid);
        $this->assertFalse($mform->elementExists('ertool_guide'));
    }

    /**
     * "Assessor guide" in the quiz's navigation, for staff only.
     */
    public function test_navigation(): void {
        global $CFG;
        require_once($CFG->dirroot . '/report/evidencereporter/lib.php');
        [$course, $quiz] = $this->setup_quiz();
        $cm = get_fast_modinfo($course)->get_cm((int) $quiz->cmid);

        $node = \navigation_node::create('root');
        report_evidencereporter_extend_navigation_module($node, $cm);
        $child = $node->get('evidencereporterassessorguide');
        $this->assertNotFalse($child);
        $this->assertStringEndsWith('/report/evidencereporter/assessorguide.php', $child->action->get_path(false));

        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));
        $node = \navigation_node::create('root');
        report_evidencereporter_extend_navigation_module($node, $cm);
        $this->assertFalse($node->get('evidencereporterassessorguide'));
    }

    /**
     * Evidence packs include the blank guide in the quiz's folder, unless turned off.
     */
    public function test_pack_includes_guide(): void {
        [$course, $quiz, , , $framework] = $this->setup_quiz();
        $gen = $this->getDataGenerator();
        $learner = $gen->create_and_enrol($course, 'student');
        $quizgen = $gen->get_plugin_generator('mod_quiz');
        $this->setUser($learner);
        $attempt = $quizgen->create_attempt((int) $quiz->id, (int) $learner->id);
        $quizgen->submit_responses((int) $attempt->id, [2 => 'True'], false, true);
        $this->setAdminUser();

        $pack = new evidence_pack((int) $learner->id, (int) $framework->get('id'));
        $pack->build();
        $guides = array_filter($pack->get_paths(), fn($path) => str_contains($path, 'Assessor guide (blank tool)'));
        $this->assertCount(1, $guides);
        $this->assertStringContainsString('Knowledge quiz', reset($guides));

        set_config('packassessorguides', 0, 'report_evidencereporter');
        attempts::reset_cache();
        $pack = new evidence_pack((int) $learner->id, (int) $framework->get('id'));
        $pack->build();
        $this->assertEmpty(array_filter($pack->get_paths(), fn($path) => str_contains($path, 'Assessor guide')));
    }
}
