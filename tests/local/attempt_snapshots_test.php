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

#[\PHPUnit\Framework\Attributes\CoversClass(attempt_snapshots::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\report_evidencereporter\observer::class)]
/**
 * Tests for attempt snapshots (activities without attempts of their own).
 *
 * @package    report_evidencereporter
 * @category   test
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\attempt_snapshots
 * @covers     \report_evidencereporter\observer
 */
final class attempt_snapshots_test extends \advanced_testcase {
    /**
     * A course with a rated database (grade out of 100, pass at 50) linked to a criterion, a learner and a teacher.
     *
     * @return stdClass
     */
    private function setup_data(): stdClass {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        set_config('enabled', 1, 'core_competency');
        $gen = $this->getDataGenerator();
        $cgen = $gen->get_plugin_generator('core_competency');
        $d = new stdClass();
        $d->course = $gen->create_course();
        $fw = $cgen->create_framework();
        $d->framework = $fw;
        $unit = $cgen->create_competency(['competencyframeworkid' => $fw->get('id'), 'idnumber' => 'U1']);
        $d->pc = $cgen->create_competency(['competencyframeworkid' => $fw->get('id'), 'idnumber' => 'U1.PC1',
            'parentid' => $unit->get('id')]);
        $cgen->create_course_competency(['courseid' => $d->course->id, 'competencyid' => $d->pc->get('id')]);
        $d->learner = $gen->create_and_enrol($d->course, 'student', ['firstname' => 'Lee', 'lastname' => 'Learner']);
        $d->teacher = $gen->create_and_enrol($d->course, 'editingteacher', ['firstname' => 'Tia', 'lastname' => 'Teacher']);
        $d->data = $gen->create_module('data', ['course' => $d->course->id, 'name' => 'Research log', 'assessed' => 1,
            'scale' => 100]);
        $cgen->create_course_module_competency(['cmid' => $d->data->cmid, 'competencyid' => $d->pc->get('id')]);
        $d->cm = get_fast_modinfo($d->course)->get_cm($d->data->cmid);
        $d->item = attempt_snapshots::get_grade_item($d->cm);
        $d->item->gradepass = 50;
        $d->item->update();
        return $d;
    }

    /**
     * Grade the learner as the teacher (as the activity would: through the gradebook, which announces it).
     *
     * @param stdClass $d
     * @param float $grade
     * @param string $feedback
     */
    private function grade(stdClass $d, float $grade, string $feedback = ''): void {
        $this->setUser($d->teacher);
        grade_update('mod/data', $d->course->id, 'mod', 'data', $d->data->id, 0, [$d->learner->id => (object) [
            'userid' => $d->learner->id, 'rawgrade' => $grade, 'feedback' => $feedback, 'feedbackformat' => FORMAT_HTML,
            'usermodified' => $d->teacher->id]]);
    }

    /**
     * Move a learner's snapshots and work back in time, so later steps happen "after" them.
     *
     * @param int $seconds
     */
    private function rewind(int $seconds): void {
        global $DB;
        $DB->execute("UPDATE {" . attempt_snapshots::TABLE . "} SET timemarked = timemarked - ?,
                             timefrozen = CASE WHEN timefrozen > 0 THEN timefrozen - ? ELSE 0 END", [$seconds, $seconds]);
        $DB->execute(
            "UPDATE {data_records} SET timemodified = timemodified - ?, timecreated = timecreated - ?",
            [$seconds, $seconds]
        );
        $DB->execute("UPDATE {comments} SET timecreated = timecreated - ?", [$seconds]);
    }

    /**
     * Not satisfactory, more feedback, re-marked, resubmitted, Not satisfactory again, then Satisfactory: two snapshots
     * kept with their own feedback, the third attempt live.
     */
    public function test_trail(): void {
        global $DB;
        $d = $this->setup_data();
        $record = $DB->insert_record('data_records', ['userid' => $d->learner->id, 'dataid' => $d->data->id,
            'timecreated' => time(), 'timemodified' => time(), 'approved' => 1]);
        $this->rewind(1000);

        // Attempt 1: Not satisfactory, with feedback in the gradebook.
        $this->grade($d, 30, 'Add two more sources.');
        $snapshots = attempt_snapshots::get((int) $d->cm->id, (int) $d->learner->id);
        $this->assertCount(1, $snapshots);
        $this->assertSame(0, (int) $snapshots[0]->timefrozen, 'Open until the learner does more');

        // A comment on the entry, then a re-mark before the learner does anything: the same attempt.
        $DB->insert_record('comments', ['contextid' => \context_module::instance($d->cm->id)->id, 'component' => 'mod_data',
            'commentarea' => 'database_entry', 'itemid' => $record, 'content' => 'Check the dates too.',
            'format' => FORMAT_MOODLE, 'userid' => $d->teacher->id, 'timecreated' => time()]);
        attempt_snapshots::feedback_given((int) $d->cm->id, (int) $d->learner->id);
        $this->grade($d, 35, 'Add two more sources, and date them.');
        $snapshots = attempt_snapshots::get((int) $d->cm->id, (int) $d->learner->id);
        $this->assertCount(1, $snapshots);
        $this->assertEquals(35, $snapshots[0]->grade);
        $texts = array_column(json_decode($snapshots[0]->feedback, true), 'text');
        $this->assertSame(['Add two more sources, and date them.', 'Check the dates too.'], $texts);

        // The learner updates the entry; marked again, still Not satisfactory: attempt 2.
        $this->rewind(500);
        $DB->set_field('data_records', 'timemodified', time() - 100, ['id' => $record]);
        $this->grade($d, 40, 'Closer: one source is out of date.');
        $snapshots = attempt_snapshots::get((int) $d->cm->id, (int) $d->learner->id);
        $this->assertCount(2, $snapshots);
        $this->assertGreaterThan(0, (int) $snapshots[0]->timefrozen);
        $this->assertSame(
            ['Add two more sources, and date them.', 'Check the dates too.'],
            array_column(json_decode($snapshots[0]->feedback, true), 'text'),
            'Attempt 1 keeps its own feedback'
        );
        $this->assertSame(
            ['Closer: one source is out of date.'],
            array_column(json_decode($snapshots[1]->feedback, true), 'text')
        );

        // Attempt 3: Satisfactory. Nothing new kept; attempt 2 closes; the trail ends with the live result.
        $this->rewind(300);
        $DB->set_field('data_records', 'timemodified', time() - 50, ['id' => $record]);
        $this->grade($d, 80, 'Well done.');
        $snapshots = attempt_snapshots::get((int) $d->cm->id, (int) $d->learner->id);
        $this->assertCount(2, $snapshots);
        $this->assertGreaterThan(0, (int) $snapshots[1]->timefrozen);
        $trail = attempt_snapshots::get_trail($d->cm, (int) $d->learner->id);
        $this->assertSame([1, 2, 3], array_column($trail, 'attemptno'));
        $this->assertSame(['fail', 'fail', 'pass'], array_column($trail, 'outcome'));
        $this->assertTrue($trail[2]->live);
        $this->assertSame('Well done.', $trail[2]->feedback[0]['text']);
        $this->assertSame(3, attempt_snapshots::attempts_used($d->cm, (int) $d->learner->id, true));

        // In the summary, under the criterion, with each attempt's feedback.
        $summary = summary::build((int) $d->learner->id, (int) $d->framework->get('id'));
        $pc = array_values(array_filter($summary->competencies, fn($c) => (int) $c->id === (int) $d->pc->get('id')))[0];
        $rows = array_values(array_filter($pc->attempts, fn($a) => !empty($a->snapshot)));
        $this->assertSame(['Attempt 1', 'Attempt 2', 'Attempt 3 (latest)'], array_column($rows, 'label'));
        $this->assertStringContainsString('Check the dates too.', $rows[0]->feedbackplain);
        $this->assertSame('Tia Teacher', $rows[0]->marker);
    }

    /**
     * The marking page counts attempts, and flags Not satisfactory on the last one.
     */
    public function test_marking_attempts(): void {
        global $DB;
        $d = $this->setup_data();
        set_config('snapattempts', 2, 'report_evidencereporter');
        $record = $DB->insert_record('data_records', ['userid' => $d->learner->id, 'dataid' => $d->data->id,
            'timecreated' => time(), 'timemodified' => time(), 'approved' => 1]);
        $this->rewind(1000);
        $this->grade($d, 30, 'Not yet.');
        $this->rewind(500);
        $DB->set_field('data_records', 'timemodified', time() - 100, ['id' => $record]);
        $this->grade($d, 20, 'Still not.');

        $cells = marking::get_cells($d->cm, [(int) $d->learner->id]);
        $cell = reset($cells);
        $this->assertSame('Attempt 2 of 2', $cell->attempt);
        $this->assertTrue($cell->noattemptsleft);
        $this->assertSame(2, attempt_snapshots::get_max_attempts());
    }

    /**
     * Grades with no pass mark, Satisfactory first time, and other activity types aren't kept.
     */
    public function test_nothing_kept(): void {
        $d = $this->setup_data();
        $this->grade($d, 90, 'Great.');
        $this->assertSame([], attempt_snapshots::get((int) $d->cm->id, (int) $d->learner->id));
        $this->assertSame([], attempt_snapshots::get_trail($d->cm, (int) $d->learner->id));
        $this->assertSame(1, attempt_snapshots::attempts_used($d->cm, (int) $d->learner->id, true));

        $d->item->gradepass = 0;
        $d->item->update();
        $this->grade($d, 10);
        $this->assertSame([], attempt_snapshots::get((int) $d->cm->id, (int) $d->learner->id));
        $this->assertFalse(attempt_snapshots::tracks('assign'));
    }

    /**
     * Marking from before: filled in from the gradebook's history.
     */
    public function test_backfill(): void {
        global $DB;
        $d = $this->setup_data();
        $this->grade($d, 30, 'Old feedback.');
        $DB->delete_records(attempt_snapshots::TABLE);
        $this->assertSame(1, attempt_snapshots::backfill());
        $snapshot = attempt_snapshots::get((int) $d->cm->id, (int) $d->learner->id)[0];
        $this->assertSame(attempt_snapshots::SOURCE_HISTORY, $snapshot->source);
        $this->assertSame('Old feedback.', json_decode($snapshot->feedback, true)[0]['text']);
        $this->assertSame(0, attempt_snapshots::backfill(), 'Run again: nothing more');
    }

    /**
     * Privacy: exported for the learner and the assessor; deleted for the learner, anonymised for the assessor; gone
     * with the activity.
     */
    public function test_privacy_and_deletion(): void {
        global $DB;
        $d = $this->setup_data();
        $this->grade($d, 30, 'Not yet.');
        $context = \context_module::instance($d->cm->id);
        $provider = \report_evidencereporter\privacy\provider::class;
        $this->assertContains((int) $context->id, array_map(
            'intval',
            $provider::get_contexts_for_userid((int) $d->learner->id)->get_contextids()
        ));
        $provider::export_user_data(new \core_privacy\local\request\approved_contextlist(
            \core_user::get_user($d->learner->id),
            'report_evidencereporter',
            [$context->id]
        ));
        $data = \core_privacy\local\request\writer::with_context($context)->get_data(['Attempt history']);
        $this->assertSame(['Not yet.'], $data->yourattempts[0]['feedback']);

        $provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            \core_user::get_user($d->teacher->id),
            'report_evidencereporter',
            [$context->id]
        ));
        $this->assertEquals(0, $DB->get_field(attempt_snapshots::TABLE, 'assessorid', ['cmid' => $d->cm->id]));
        course_delete_module((int) $d->cm->id);
        $this->assertFalse($DB->record_exists(attempt_snapshots::TABLE, ['cmid' => $d->cm->id]));
    }

    /**
     * A forum with whole-forum grading: the assessor's reply to the learner's post is the attempt's feedback.
     */
    public function test_forum_reply(): void {
        global $DB;
        $d = $this->setup_data();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $d->course->id, 'grade_forum' => 100]);
        $cm = get_fast_modinfo($d->course)->get_cm($forum->cmid);
        $item = attempt_snapshots::get_grade_item($cm);
        $this->assertEquals(1, $item->itemnumber, 'Whole-forum grading, not ratings');
        $item->gradepass = 50;
        $item->update();
        $fgen = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $fgen->create_discussion(['course' => $d->course->id, 'forum' => $forum->id,
            'userid' => $d->learner->id]);
        $first = $DB->get_record('forum_posts', ['discussion' => $discussion->id, 'parent' => 0]);

        $this->setUser($d->teacher);
        grade_update('mod/forum', $d->course->id, 'mod', 'forum', $forum->id, 1, [$d->learner->id => (object) [
            'userid' => $d->learner->id, 'rawgrade' => 20, 'usermodified' => $d->teacher->id]]);
        $this->assertCount(1, attempt_snapshots::get((int) $cm->id, (int) $d->learner->id));

        $reply = $fgen->create_post(['discussion' => $discussion->id, 'parent' => $first->id, 'userid' => $d->teacher->id,
            'message' => 'Say how you checked the information was current.']);
        \mod_forum\event\post_created::create(['objectid' => $reply->id, 'context' => \context_module::instance($cm->id),
            'other' => ['discussionid' => $discussion->id, 'forumid' => $forum->id, 'forumtype' => 'general']])->trigger();
        $snapshot = attempt_snapshots::get((int) $cm->id, (int) $d->learner->id)[0];
        $pieces = json_decode($snapshot->feedback, true);
        $this->assertSame('forum', $pieces[0]['source']);
        $this->assertSame('Say how you checked the information was current.', $pieces[0]['text']);
    }
}
