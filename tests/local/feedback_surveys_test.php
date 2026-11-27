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

#[\PHPUnit\Framework\Attributes\CoversClass(feedback_surveys::class)]
/**
 * Tests for learner feedback on assessments.
 *
 * @package    report_evidencereporter
 * @category   test
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\feedback_surveys
 */
final class feedback_surveys_test extends \advanced_testcase {
    /**
     * A course (completion on) with two assignments linked to a criterion, six learners, and a survey after each
     * assignment: the first available once AT1 is complete, the second set on its form.
     *
     * @return stdClass
     */
    private function setup_data(): stdClass {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        set_config('enabled', 1, 'core_competency');
        $CFG->enablecompletion = 1;
        $CFG->enableavailability = 1;
        $gen = $this->getDataGenerator();
        $cgen = $gen->get_plugin_generator('core_competency');
        $d = new stdClass();
        $fw = $cgen->create_framework();
        $d->unit = $cgen->create_competency(['competencyframeworkid' => $fw->get('id'), 'idnumber' => 'U1']);
        $d->pc = $cgen->create_competency(['competencyframeworkid' => $fw->get('id'), 'idnumber' => 'U1.1',
            'parentid' => $d->unit->get('id')]);
        $d->course = $gen->create_course(['enablecompletion' => 1]);
        $cgen->create_course_competency(['courseid' => $d->course->id, 'competencyid' => $d->pc->get('id')]);
        $d->learners = [];
        for ($i = 1; $i <= 6; $i++) {
            $d->learners[] = $gen->create_and_enrol($d->course, 'student');
        }
        $d->at1 = $gen->create_module('assign', ['course' => $d->course->id, 'name' => 'AT1',
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $d->at2 = $gen->create_module('assign', ['course' => $d->course->id, 'name' => 'AT2']);
        foreach ([$d->at1, $d->at2] as $assign) {
            $cgen->create_course_module_competency(['cmid' => $assign->cmid, 'competencyid' => $d->pc->get('id')]);
        }
        $availability = json_encode(['op' => '&', 'showc' => [false],
            'c' => [['type' => 'completion', 'cm' => (int) $d->at1->cmid, 'e' => COMPLETION_COMPLETE]]]);
        $d->survey1 = $gen->create_module('feedback', ['course' => $d->course->id, 'name' => 'AT1 feedback',
            'anonymous' => 1, 'availability' => $availability]);
        $d->survey2 = $gen->create_module('feedback', ['course' => $d->course->id, 'name' => 'AT2 feedback',
            'anonymous' => 1]);
        feedback_surveys::save_from_form((object) ['coursemodule' => $d->survey2->cmid, 'modulename' => 'feedback',
            feedback_surveys::FIELD => $d->at2->cmid]);
        // The same rated question and a comment box in each survey.
        foreach ([$d->survey1, $d->survey2] as $survey) {
            $survey->rated = $this->add_item(
                $survey,
                'multichoicerated',
                'The instructions were clear',
                'r>>>>>1####Disagree|2####Neutral|3####Agree'
            );
            $survey->comment = $this->add_item($survey, 'textarea', 'Anything else?', '30|5');
        }
        return $d;
    }

    /**
     * Add a question.
     *
     * @param stdClass $survey
     * @param string $type
     * @param string $name
     * @param string $presentation
     * @return int item id
     */
    private function add_item(stdClass $survey, string $type, string $name, string $presentation): int {
        global $DB;
        return (int) $DB->insert_record('feedback_item', ['feedback' => $survey->id, 'template' => 0, 'name' => $name,
            'label' => '', 'presentation' => $presentation, 'typ' => $type, 'hasvalue' => 1, 'position' => 1,
            'required' => 0, 'dependitem' => 0, 'dependvalue' => '', 'options' => '']);
    }

    /**
     * A learner answers (anonymously, as Moodle stores it: with their id, hidden in the interface).
     *
     * @param stdClass $survey
     * @param int $userid
     * @param int $rating Option index (1-based), 0 for none
     * @param string $comment
     */
    private function answer(stdClass $survey, int $userid, int $rating, string $comment = ''): void {
        global $DB;
        $completed = $DB->insert_record('feedback_completed', ['feedback' => $survey->id, 'userid' => $userid,
            'timemodified' => time(), 'random_response' => 0, 'anonymous_response' => 1, 'courseid' => $survey->course]);
        $DB->insert_record('feedback_value', ['course_id' => $survey->course, 'item' => $survey->rated,
            'completed' => $completed, 'tmp_completed' => 0, 'value' => (string) $rating]);
        $DB->insert_record('feedback_value', ['course_id' => $survey->course, 'item' => $survey->comment,
            'completed' => $completed, 'tmp_completed' => 0, 'value' => $comment]);
    }

    /**
     * Surveys belong to assessments: from Restrict access, or set on the form; a grade condition works too.
     */
    public function test_links(): void {
        $d = $this->setup_data();
        $links = feedback_surveys::get_links((int) $d->course->id);
        $this->assertSame(
            [(int) $d->survey1->cmid => (int) $d->at1->cmid, (int) $d->survey2->cmid => (int) $d->at2->cmid],
            $links
        );

        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $d->at2->id]);
        $survey3 = $this->getDataGenerator()->create_module('feedback', ['course' => $d->course->id,
            'availability' => json_encode(['op' => '&', 'showc' => [false],
                'c' => [['type' => 'grade', 'id' => (int) $item->id, 'min' => 50]]])]);
        $this->assertSame((int) $d->at2->cmid, feedback_surveys::get_links((int) $d->course->id)[$survey3->cmid]);
        $this->assertArrayHasKey($d->at1->cmid, feedback_surveys::get_assessment_choices((int) $d->course->id));
    }

    /**
     * A learner's record: offered after how many assessments, never whether they answered.
     */
    public function test_offered_to(): void {
        $d = $this->setup_data();
        $learner = $d->learners[0];
        $cmids = [(int) $d->at1->cmid, (int) $d->at2->cmid];
        $this->assertEquals(
            (object) ['offered' => 1, 'total' => 2],
            feedback_surveys::offered_to((int) $learner->id, $cmids),
            'AT2\'s survey is open; AT1\'s waits until AT1 is complete'
        );
        $cm = get_fast_modinfo($d->course)->get_cm($d->at1->cmid);
        (new \completion_info($d->course))->update_state($cm, COMPLETION_COMPLETE, (int) $learner->id);
        $this->assertEquals((object) ['offered' => 2, 'total' => 2], feedback_surveys::offered_to((int) $learner->id, $cmids));

        // On the learner's summary.
        $this->setAdminUser();
        $summary = summary::build((int) $learner->id, (int) $d->pc->get('competencyframeworkid'));
        $this->assertEquals((object) ['offered' => 2, 'total' => 2], $summary->feedback);
    }

    /**
     * Collated: response rates; the same question combined across surveys; hidden below the minimum; comments sorted.
     */
    public function test_collate(): void {
        $d = $this->setup_data();
        $ids = array_map(fn($u) => (int) $u->id, $d->learners);
        // AT2's survey: four answers. AT1's: two.
        $this->answer($d->survey2, $ids[0], 3, 'More examples please');
        $this->answer($d->survey2, $ids[1], 3);
        $this->answer($d->survey2, $ids[2], 2, 'all good');
        $this->answer($d->survey2, $ids[3], 1);
        $this->answer($d->survey1, $ids[0], 3);
        $this->answer($d->survey1, $ids[1], 2, 'Clear');
        $modinfo = get_fast_modinfo($d->course);
        $surveys = [
            (int) $d->survey1->cmid => $modinfo->get_cm($d->at1->cmid),
            (int) $d->survey2->cmid => $modinfo->get_cm($d->at2->cmid),
        ];

        $result = feedback_surveys::collate($surveys);
        $this->assertSame(5, $result->min);
        $rows = array_column(array_map(fn($r) => (array) $r, $result->surveys), null, 'assessment');
        $this->assertSame(2, $rows['AT1']['offered'], 'AT1 isn\'t complete for anyone yet, but two answered');
        $this->assertSame(2, $rows['AT1']['responses']);
        $this->assertSame(6, $rows['AT2']['offered']);
        $this->assertSame(4, $rows['AT2']['responses']);
        $this->assertEquals(67, $rows['AT2']['rate']);

        $questions = array_column(array_map(fn($q) => (array) $q, $result->questions), null, 'question');
        $rated = $questions['The instructions were clear'];
        $this->assertSame(6, $rated['responses'], 'Combined across both surveys');
        $this->assertTrue($rated['shown']);
        $this->assertEqualsWithDelta(2.33, $rated['average'], 0.01);
        $this->assertSame(
            ['Disagree' => 1, 'Neutral' => 2, 'Agree' => 3],
            array_map(fn($o) => $o->count, $rated['options']),
            'In the scale\'s order'
        );
        $this->assertEquals(3, $rated['ratingmax']);
        $comments = $questions['Anything else?'];
        $this->assertSame(3, $comments['responses']);
        $this->assertFalse($comments['shown'], 'Fewer than 5: hidden');
        $this->assertSame([], $comments['comments']);

        set_config('feedbackmin', 3, 'report_evidencereporter');
        $result = feedback_surveys::collate($surveys);
        $comments = array_column(array_map(fn($q) => (array) $q, $result->questions), null, 'question')['Anything else?'];
        $this->assertSame(['all good', 'Clear', 'More examples please'], $comments['comments'], 'In no telling order');
    }

    /**
     * Deleting an activity forgets its link.
     */
    public function test_delete(): void {
        global $DB;
        $d = $this->setup_data();
        course_delete_module((int) $d->at2->cmid);
        $this->assertFalse($DB->record_exists(feedback_surveys::TABLE, ['assesscmid' => $d->at2->cmid]));
    }
}
