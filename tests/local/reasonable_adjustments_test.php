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

#[\PHPUnit\Framework\Attributes\CoversClass(reasonable_adjustments::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\report_evidencereporter\privacy\provider::class)]
/**
 * Tests for reasonable adjustments.
 *
 * @package    report_evidencereporter
 * @category   test
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\reasonable_adjustments
 * @covers     \report_evidencereporter\privacy\provider
 */
final class reasonable_adjustments_test extends \advanced_testcase {
    /**
     * A framework with a unit and a criterion, a course with a quiz and an assignment linked to the criterion, a
     * learner, a teacher and a teacher of another course.
     *
     * @return stdClass
     */
    private function setup_data(): stdClass {
        $this->resetAfterTest();
        set_config('enabled', 1, 'core_competency');
        $gen = $this->getDataGenerator();
        $cgen = $gen->get_plugin_generator('core_competency');
        $d = new stdClass();
        $d->framework = $cgen->create_framework(['shortname' => 'FW']);
        $d->unit = $cgen->create_competency(['competencyframeworkid' => $d->framework->get('id'), 'idnumber' => 'U1',
            'shortname' => 'Unit 1']);
        $d->pc = $cgen->create_competency(['competencyframeworkid' => $d->framework->get('id'), 'idnumber' => 'PC1.1',
            'shortname' => 'Criterion', 'parentid' => $d->unit->get('id')]);
        $d->course = $gen->create_course();
        $d->learner = $gen->create_and_enrol($d->course, 'student', ['firstname' => 'Lee', 'lastname' => 'Learner']);
        $d->teacher = $gen->create_and_enrol($d->course, 'editingteacher', ['firstname' => 'Tia', 'lastname' => 'Teacher']);
        $other = $gen->create_course();
        $d->otherteacher = $gen->create_and_enrol($other, 'editingteacher');
        $gen->enrol_user($d->learner->id, $other->id, 'student');
        $cgen->create_course_competency(['courseid' => $d->course->id, 'competencyid' => $d->pc->get('id')]);
        $d->quiz = $gen->create_module('quiz', ['course' => $d->course->id, 'name' => 'Knowledge quiz']);
        $d->assign = $gen->create_module('assign', ['course' => $d->course->id, 'name' => 'Practice plan']);
        foreach ([$d->quiz, $d->assign] as $activity) {
            $cgen->create_course_module_competency(['cmid' => $activity->cmid, 'competencyid' => $d->pc->get('id')]);
        }
        return $d;
    }

    /**
     * Record an adjustment as the teacher.
     *
     * @param stdClass $d
     * @param int $cmid
     * @param array $fields
     * @return int
     */
    private function record(stdClass $d, int $cmid, array $fields = []): int {
        $this->setUser($d->teacher);
        return reasonable_adjustments::save((object) ($fields + ['cmid' => $cmid, 'userid' => $d->learner->id,
            'adjtype' => 'reader', 'details' => 'Questions read aloud', 'reason' => 'Learning support plan',
            'approvedby' => 'Learner support officer']));
    }

    /**
     * Saving, editing and deleting, with events; who may see them.
     */
    public function test_save_and_access(): void {
        $d = $this->setup_data();
        $sink = $this->redirectEvents();
        $id = $this->record($d, (int) $d->quiz->cmid);
        $events = $sink->get_events();
        $this->assertInstanceOf(\report_evidencereporter\event\adjustment_saved::class, end($events));
        $this->assertEquals($d->learner->id, end($events)->relateduserid);

        // Editing keeps the activity and learner, whatever is sent.
        reasonable_adjustments::save((object) ['id' => $id, 'cmid' => $d->assign->cmid, 'userid' => $d->teacher->id,
            'adjtype' => 'nonsense', 'details' => 'Read aloud, twice', 'reason' => 'Support plan', 'approvedby' => 'LSO']);
        $record = reasonable_adjustments::get($id);
        $this->assertEquals($d->quiz->cmid, $record->cmid);
        $this->assertEquals($d->learner->id, $record->userid);
        $this->assertSame('other', $record->adjtype);
        $this->assertEquals($d->teacher->id, $record->usermodified);

        $context = \context_module::instance($d->quiz->cmid);
        $this->assertTrue(reasonable_adjustments::can_view($context, (int) $d->learner->id));
        $this->setUser($d->otherteacher);
        $this->assertFalse(reasonable_adjustments::can_view($context, (int) $d->learner->id));
        $this->setUser($d->learner);
        $this->assertTrue(reasonable_adjustments::can_view($context, (int) $d->learner->id));
        $this->assertFalse(reasonable_adjustments::can_manage($context));
        set_config('radjlearner', 0, 'report_evidencereporter');
        $this->assertFalse(reasonable_adjustments::can_view($context, (int) $d->learner->id));

        $this->setUser($d->teacher);
        reasonable_adjustments::delete($id);
        $this->assertNull(reasonable_adjustments::get($id));
        $events = $sink->get_events();
        $this->assertInstanceOf(\report_evidencereporter\event\adjustment_deleted::class, end($events));
        $sink->close();
    }

    /**
     * Quiz user overrides and assignment extensions are offered until confirmed.
     */
    public function test_suggestions(): void {
        global $DB;
        $d = $this->setup_data();
        $DB->insert_record('quiz_overrides', ['quiz' => $d->quiz->id, 'userid' => $d->learner->id, 'timelimit' => 5400]);
        $DB->insert_record('quiz_overrides', ['quiz' => $d->quiz->id, 'groupid' => 0, 'userid' => null, 'attempts' => 5]);
        $DB->insert_record('assign_user_flags', ['assignment' => $d->assign->id, 'userid' => $d->learner->id,
            'extensionduedate' => make_timestamp(2026, 12, 1, 17), 'locked' => 0, 'mailed' => 0]);
        $this->setUser($d->teacher);

        $quizcm = get_fast_modinfo($d->course)->get_cm($d->quiz->cmid);
        $suggestions = reasonable_adjustments::get_suggestions($quizcm);
        $this->assertSame([(int) $d->learner->id], array_keys($suggestions));
        $suggestion = $suggestions[$d->learner->id][0];
        $this->assertSame('extratime', $suggestion->adjtype);
        $this->assertSame('Time limit: 1 hour 30 mins', $suggestion->details);

        $assigncm = get_fast_modinfo($d->course)->get_cm($d->assign->cmid);
        $extension = reasonable_adjustments::get_suggestions($assigncm)[$d->learner->id][0];
        $this->assertSame(reasonable_adjustments::SOURCE_ASSIGNEXTENSION, $extension->source);
        $this->assertStringStartsWith('Extension to ', $extension->details);

        // Confirmed: no longer offered, and shown with where it came from.
        $this->record($d, (int) $d->quiz->cmid, ['adjtype' => 'extratime', 'details' => $suggestion->details,
            'source' => $suggestion->source, 'sourceid' => $suggestion->sourceid]);
        $this->assertSame([], reasonable_adjustments::get_suggestions($quizcm));
        $formatted = reasonable_adjustments::get_for_user((int) $d->learner->id, [(int) $d->pc->get('id')]);
        $this->assertSame('Quiz user override', $formatted[0]->source);
    }

    /**
     * The summary shows adjustments with reasons; downloads leave reasons out unless the site includes them; the sample
     * register lists them per judgement.
     */
    public function test_summary_pack_and_register(): void {
        global $PAGE;
        $d = $this->setup_data();
        $this->record($d, (int) $d->quiz->cmid);
        $this->record($d, (int) $d->assign->cmid, ['adjtype' => 'format', 'details' => 'Recorded presentation',
            'reason' => '']);
        $pcid = (int) $d->pc->get('id');

        $this->setUser($d->teacher);
        $summary = summary::build((int) $d->learner->id, (int) $d->framework->get('id'));
        $this->assertCount(2, $summary->adjustments);
        $this->assertSame('Reader', $summary->adjustments[0]->type);
        $this->assertSame('Learning support plan', $summary->adjustments[0]->reason);
        $page = new \report_evidencereporter\output\summary_page(
            \core_user::get_user($d->learner->id),
            $d->framework->to_record(),
            $summary
        );
        $html = $PAGE->get_renderer('core')->render_from_template(
            'report_evidencereporter/summary',
            $page->export_for_template($PAGE->get_renderer('core'))
        );
        $this->assertStringContainsString('Reasonable adjustments', $html);
        $this->assertStringContainsString('Learning support plan', $html);
        $this->assertStringContainsString('name="cmid"', $html, 'The teacher may record more.');

        // A teacher in another course sees none of them.
        $this->setUser($d->otherteacher);
        $this->assertSame([], reasonable_adjustments::get_for_user((int) $d->learner->id, [$pcid]));

        // Downloads: reason left out by default, but noted; included when the site allows.
        $this->setUser($d->teacher);
        $download = reasonable_adjustments::get_for_user((int) $d->learner->id, [$pcid], null, true);
        $this->assertNull($download[0]->reason);
        $text = reasonable_adjustments::to_text($download);
        $this->assertStringContainsString('Knowledge quiz: Reader (Questions read aloud). Reason recorded, not included.', $text);
        $this->assertStringContainsString(
            'Practice plan: Alternative format or way of responding (Recorded presentation)',
            $text
        );
        $pack = new evidence_pack((int) $d->learner->id, scope::for_framework(
            (int) $d->learner->id,
            (int) $d->framework->get('id')
        ));
        $this->assertStringStartsWith('%PDF', $pack->build_summary_pdf());
        set_config('radjreasons', 1, 'report_evidencereporter');
        $download = reasonable_adjustments::get_for_user((int) $d->learner->id, [$pcid], null, true);
        $this->assertSame('Learning support plan', $download[0]->reason);

        // Per judgement in a sample: the learner's, in that course, under that unit.
        $line = reasonable_adjustments::for_judgement(
            (int) $d->learner->id,
            (int) $d->course->id,
            (int) $d->unit->get('id')
        );
        $this->assertStringContainsString('Reason: Learning support plan', $line);
        $this->assertSame('', reasonable_adjustments::for_judgement(
            (int) $d->teacher->id,
            (int) $d->course->id,
            (int) $d->unit->get('id')
        ));

        // The record menu lists activities the teacher may record in.
        $this->assertEqualsCanonicalizing(
            [(int) $d->quiz->cmid, (int) $d->assign->cmid],
            array_keys(reasonable_adjustments::get_manageable_activities((int) $d->learner->id, [$pcid]))
        );
    }

    /**
     * Deleting the activity removes its adjustments.
     */
    public function test_activity_deleted(): void {
        global $DB;
        $d = $this->setup_data();
        $this->record($d, (int) $d->quiz->cmid);
        course_delete_module((int) $d->quiz->cmid);
        $this->assertFalse($DB->record_exists(reasonable_adjustments::TABLE, ['cmid' => $d->quiz->cmid]));
    }

    /**
     * Privacy: export, and deletion (learners' records go; the staff member who recorded one is anonymised).
     */
    public function test_privacy(): void {
        global $DB;
        $d = $this->setup_data();
        $id = $this->record($d, (int) $d->quiz->cmid);
        $context = \context_module::instance($d->quiz->cmid);
        $provider = \report_evidencereporter\privacy\provider::class;

        $this->assertContains((int) $context->id, array_map(
            'intval',
            $provider::get_contexts_for_userid((int) $d->learner->id)->get_contextids()
        ));
        $this->assertContains((int) $context->id, array_map(
            'intval',
            $provider::get_contexts_for_userid((int) $d->teacher->id)->get_contextids()
        ));
        $userlist = new \core_privacy\local\request\userlist($context, 'report_evidencereporter');
        $provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([(int) $d->learner->id, (int) $d->teacher->id], $userlist->get_userids());

        $writer = \core_privacy\local\request\writer::with_context($context);
        $provider::export_user_data(new \core_privacy\local\request\approved_contextlist(
            \core_user::get_user($d->learner->id),
            'report_evidencereporter',
            [$context->id]
        ));
        $data = $writer->get_data(['Reasonable adjustments']);
        $this->assertSame('Learning support plan', $data->madeforyou[0]['reason']);

        $provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            \core_user::get_user($d->teacher->id),
            'report_evidencereporter',
            [$context->id]
        ));
        $this->assertEquals(0, $DB->get_field(reasonable_adjustments::TABLE, 'usermodified', ['id' => $id]));
        $provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            \core_user::get_user($d->learner->id),
            'report_evidencereporter',
            [$context->id]
        ));
        $this->assertFalse($DB->record_exists(reasonable_adjustments::TABLE, ['id' => $id]));
    }
}
