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

#[\PHPUnit\Framework\Attributes\CoversClass(forum_posts::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(advanced_grading::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(source\forum::class)]
/**
 * Tests for forum contributions as evidence.
 *
 * @package    report_evidencereporter
 * @category   test
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\forum_posts
 * @covers     \report_evidencereporter\local\advanced_grading
 * @covers     \report_evidencereporter\local\source\forum
 */
final class forum_posts_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        forum_posts::reset_cache();
        similarity::set_link_source(null);
        sources::reset_cache();
        activity_grade::reset_cache();
    }

    #[\Override]
    protected function tearDown(): void {
        similarity::set_link_source(null);
        parent::tearDown();
    }

    /**
     * A forum graded as a whole (with a rubric) and by ratings, linked to a competency, with the learner's posts.
     *
     * @param bool $rubric Grade the whole forum with a rubric
     * @return \stdClass
     */
    private function setup_forum(bool $rubric = true): \stdClass {
        global $DB;
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['shortname' => 'SHK101']);
        $student = $gen->create_and_enrol($course, 'student', ['firstname' => 'Ava', 'lastname' => 'Thesis']);
        $classmate = $gen->create_and_enrol($course, 'student', ['firstname' => 'Ben', 'lastname' => 'Classmate']);
        $teacher = $gen->create_and_enrol($course, 'editingteacher', ['firstname' => 'Tess', 'lastname' => 'Tutor']);
        $forum = $gen->create_module('forum', ['course' => $course->id, 'name' => 'Week 3 discussion',
            'grade_forum' => 10, 'assessed' => 1, 'scale' => 5]);
        $context = \context_module::instance($forum->cmid);

        $cgen = $gen->get_plugin_generator('core_competency');
        $framework = $cgen->create_framework(['shortname' => 'SHK101']);
        $comp = $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => '2.2',
            'shortname' => 'Analyse imagery']);
        $cgen->create_course_competency(['courseid' => $course->id, 'competencyid' => $comp->get('id')]);
        $cgen->create_course_module_competency(['competencyid' => $comp->get('id'), 'cmid' => $forum->cmid]);

        $fgen = $gen->get_plugin_generator('mod_forum');
        $long = 'Lady Macbeth keeps returning to blood because guilt has become physical for her and no amount of '
            . 'washing can take it away which is why the sleepwalking scene matters so much to how we read her '
            . 'collapse in the final act of the play';
        $d1 = $fgen->create_discussion(['course' => $course->id, 'forum' => $forum->id, 'userid' => $classmate->id,
            'name' => 'Blood imagery', 'message' => $long]);
        $reply1 = $fgen->create_post(['discussion' => $d1->id, 'parent' => $d1->firstpost, 'userid' => $student->id,
            'subject' => 'Re: Blood imagery', 'message' => 'I agree, and Ava Thesis thinks the "damned spot" line shows it.']);
        $d2 = $fgen->create_discussion(['course' => $course->id, 'forum' => $forum->id, 'userid' => $teacher->id,
            'name' => 'Question from Tess', 'message' => 'How does Shakespeare use darkness?']);
        $reply2 = $fgen->create_post(['discussion' => $d2->id, 'parent' => $d2->firstpost, 'userid' => $student->id,
            'subject' => 'Re: Question from Tess', 'message' => 'Darkness hides the crime.']);
        $d3 = $fgen->create_discussion(['course' => $course->id, 'forum' => $forum->id, 'userid' => $student->id,
            'name' => 'My reading of Act 5', 'message' => 'Sleepwalking as confession. <img src="x.png">']);
        get_file_storage()->create_file_from_string(
            ['contextid' => $context->id, 'component' => 'mod_forum',
            'filearea' => 'attachment', 'itemid' => $d3->firstpost, 'filepath' => '/', 'filename' => 'act5-notes.pdf'],
            'notes'
        );

        // Ratings on the learner's own discussion: 4 and 2, average 3.
        foreach ([[$teacher->id, 4], [$classmate->id, 2]] as [$rater, $value]) {
            $DB->insert_record('rating', ['contextid' => $context->id, 'component' => 'mod_forum', 'ratingarea' => 'post',
                'itemid' => $d3->firstpost, 'scaleid' => 5, 'rating' => $value, 'userid' => $rater,
                'timecreated' => time(), 'timemodified' => time()]);
        }
        grade_update(
            'mod/forum',
            $course->id,
            'mod',
            'forum',
            $forum->id,
            0,
            [$student->id => ['userid' => $student->id, 'rawgrade' => 3]]
        );

        // Whole-forum grade 8/10, with a rubric.
        $this->setUser($teacher);
        $forumgradeid = $DB->insert_record('forum_grades', ['forum' => $forum->id, 'itemnumber' => 1,
            'userid' => $student->id, 'grade' => 8, 'timecreated' => time(), 'timemodified' => time()]);
        if ($rubric) {
            $rgen = $gen->get_plugin_generator('gradingform_rubric');
            $controller = $rgen->get_test_rubric($context, 'mod_forum', 'forum');
            $data = $rgen->get_test_form_data($controller, $forumgradeid, 2, 'Careful spelling', 1, 'Add more images');
            $controller->create_instance($teacher->id, $forumgradeid)->submit_and_get_grade($data, $forumgradeid);
        }
        grade_update(
            'mod/forum',
            $course->id,
            'mod',
            'forum',
            $forum->id,
            1,
            [$student->id => ['userid' => $student->id, 'rawgrade' => 8]]
        );
        $this->setAdminUser();

        return (object) ['course' => $course, 'forum' => $forum, 'cmid' => (int) $forum->cmid, 'student' => $student,
            'classmate' => $classmate, 'teacher' => $teacher, 'framework' => $framework, 'comp' => $comp,
            'replies' => [$reply1, $reply2], 'own' => $d3];
    }

    public function test_linked_and_contents(): void {
        $s = $this->setup_forum();
        $links = forum_posts::get_linked((int) $s->student->id);
        $this->assertCount(1, $links);
        $this->assertEquals($s->cmid, $links[0]->cmid);

        $data = forum_posts::for_user($s->cmid, (int) $s->student->id);
        $this->assertCount(3, $data->posts);
        $this->assertEquals(['wholeforum', 'ratings'], array_keys($data->grades));

        $html = forum_posts::html($data, null, true);
        // Whole-forum grade first, with its rubric; then ratings.
        $this->assertStringContainsString('Whole forum grade: <strong>8.00 / 10.00</strong>', $html);
        $this->assertStringContainsString('Careful spelling', $html);
        $this->assertStringContainsString('No mistakes (2)', $html);
        $this->assertLessThan(strpos($html, 'Ratings (Average of ratings)'), strpos($html, 'Whole forum grade'));
        $this->assertStringContainsString('Rating: 3.00 (2 rating(s))', $html);
        $this->assertStringContainsString('3 post(s) by the learner', $html);

        // Replies: staff named, another learner not, with a 30-word excerpt.
        $this->assertStringContainsString('In reply to Tess Tutor', $html);
        $this->assertStringContainsString('In reply to another learner', $html);
        $this->assertStringNotContainsString('Ben Classmate', $html);
        $this->assertStringContainsString('the sleepwalking scene matters so…', $html);
        $this->assertStringNotContainsString('much to how', $html);
        $this->assertStringContainsString('Started a discussion', $html);
        $this->assertStringContainsString('Reply in &quot;Blood imagery&quot;', $html);

        // Summary: folded posts, attachment link, link to the post.
        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('act5-notes.pdf</a>', $html);
        $this->assertStringContainsString('discuss.php?d=', $html);

        // PDF: posts in full, images noted, attachments by name.
        $pdf = forum_posts::html($data, null, true, true);
        $this->assertStringNotContainsString('<details', $pdf);
        $this->assertStringNotContainsString('<img', $pdf);
        $this->assertStringContainsString('[image]', $pdf);
        $this->assertStringContainsString('act5-notes.pdf (in this folder)', $pdf);

        // Without feedback, the rubric is left out.
        $this->assertStringNotContainsString('Careful spelling', forum_posts::html($data, null, true, true, false, false));
    }

    public function test_summary_uses_whole_forum_grade(): void {
        $s = $this->setup_forum(false);
        $this->getDataGenerator()->get_plugin_generator('report_evidencereporter')->create_evidence([
            'userid' => $s->student->id, 'competencyid' => $s->comp->get('id'), 'cmid' => $s->cmid,
            'action' => \core_competency\evidence::ACTION_LOG]);
        $summary = summary::build((int) $s->student->id, (int) $s->framework->get('id'));
        $comp = $summary->competencies[0];
        $this->assertEquals('8.00 / 10.00', $comp->evidence[0]->grade);
        $this->assertCount(1, $comp->forums);
        $this->assertEquals('Week 3 discussion', $comp->forums[0]->activity);
        $this->assertEquals(3, $comp->forums[0]->postcount);

        // Forum posts alone count as evidence for "units with evidence".
        $this->assertContains((int) $s->comp->get('id'), summary::get_assessed_competencyids((int) $s->student->id));
    }

    public function test_redaction(): void {
        $s = $this->setup_forum(false);
        $data = forum_posts::for_user($s->cmid, (int) $s->student->id);
        $identity = identity::from_flags(\core_user::get_user($s->student->id), ['redactname' => 1, 'redactstaff' => 1]);
        $html = forum_posts::html($data, $identity, true);
        $this->assertStringNotContainsString('Ava Thesis', $html);
        $this->assertStringNotContainsString('Tess Tutor', $html);
    }

    public function test_similarity_for_staff_only(): void {
        $s = $this->setup_forum(false);
        similarity::set_link_source(fn($linkarray) => isset($linkarray['file']) ? '<span>9%</span>' : '<span>21%</span>');
        $data = forum_posts::for_user($s->cmid, (int) $s->student->id);

        $this->setUser($s->teacher);
        $this->assertTrue(similarity::allowed($s->cmid, 'forum'));
        $html = forum_posts::html($data, null, true, false, similarity::allowed($s->cmid, 'forum'));
        $this->assertStringContainsString('Post text: <span>21%</span>', $html);
        $this->assertStringContainsString('act5-notes.pdf: <span>9%</span>', $html);
        $this->assertFalse(similarity::allowed($s->cmid, 'forum', true));

        $this->setUser($s->student);
        $this->assertFalse(similarity::allowed($s->cmid, 'forum'));
    }

    public function test_pack(): void {
        $s = $this->setup_forum();
        $pack = new evidence_pack((int) $s->student->id, (int) $s->framework->get('id'));
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($pack->build()));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        $folder = '02 Assessments/SHK101 - Week 3 discussion/';
        $this->assertContains($folder . 'Forum posts.pdf', $names);
        $this->assertContains($folder . 'Attachments/act5-notes.pdf', $names);
        $html = implode("\n", $pack->get_rendered_html());
        $this->assertStringContainsString('In reply to another learner', $html);
        $this->assertStringContainsString('Forum contributions', $html);
    }

    public function test_ratings_only_and_qanda(): void {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $forum = $gen->create_module('forum', ['course' => $course->id, 'type' => 'qanda', 'assessed' => 1, 'scale' => 10]);
        $cgen = $gen->get_plugin_generator('core_competency');
        $framework = $cgen->create_framework();
        $comp = $cgen->create_competency(['competencyframeworkid' => $framework->get('id')]);
        $cgen->create_course_competency(['courseid' => $course->id, 'competencyid' => $comp->get('id')]);
        $cgen->create_course_module_competency(['competencyid' => $comp->get('id'), 'cmid' => $forum->cmid]);
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $d = $gen->get_plugin_generator('mod_forum')->create_discussion(['course' => $course->id, 'forum' => $forum->id,
            'userid' => $teacher->id]);
        $gen->get_plugin_generator('mod_forum')->create_post(['discussion' => $d->id, 'parent' => $d->firstpost,
            'userid' => $student->id, 'message' => 'My answer']);
        grade_update(
            'mod/forum',
            $course->id,
            'mod',
            'forum',
            $forum->id,
            0,
            [$student->id => ['userid' => $student->id, 'rawgrade' => 7]]
        );
        $gen->get_plugin_generator('report_evidencereporter')->create_evidence(['userid' => $student->id,
            'competencyid' => $comp->get('id'), 'cmid' => $forum->cmid, 'action' => \core_competency\evidence::ACTION_LOG]);
        $this->setAdminUser();

        $summary = summary::build((int) $student->id, (int) $framework->get('id'));
        $this->assertEquals('7.00 / 10.00', $summary->competencies[0]->evidence[0]->grade);
        $html = $summary->competencies[0]->forums[0]->html;
        $this->assertStringNotContainsString('Whole forum grade', $html);
        $this->assertStringContainsString('Ratings (Average of ratings): <strong>7.00 / 10.00</strong>', $html);
        $this->assertStringContainsString('1 post(s) by the learner', $html);
    }
}
