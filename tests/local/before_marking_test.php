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

#[\PHPUnit\Framework\Attributes\CoversClass(before_marking::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\report_evidencereporter\hook_callbacks::class)]
/**
 * Tests for the before-marking checks.
 *
 * @package    report_evidencereporter
 * @category   test
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\before_marking
 * @covers     \report_evidencereporter\local\tool_details
 * @covers     \report_evidencereporter\hook_callbacks
 */
final class before_marking_test extends \advanced_testcase {
    /**
     * A course with an assignment linked to a criterion, an editing teacher, an assessor (non-editing) and a learner.
     *
     * @return stdClass
     */
    private function setup_data(): stdClass {
        $this->resetAfterTest();
        set_config('enabled', 1, 'core_competency');
        $gen = $this->getDataGenerator();
        $cgen = $gen->get_plugin_generator('core_competency');
        $d = new stdClass();
        $fw = $cgen->create_framework();
        $pc = $cgen->create_competency(['competencyframeworkid' => $fw->get('id'), 'idnumber' => 'PC1']);
        $d->course = $gen->create_course();
        $cgen->create_course_competency(['courseid' => $d->course->id, 'competencyid' => $pc->get('id')]);
        $d->editor = $gen->create_and_enrol($d->course, 'editingteacher', ['firstname' => 'Ed', 'lastname' => 'Editor']);
        $d->assessor = $gen->create_and_enrol($d->course, 'teacher', ['firstname' => 'Tia', 'lastname' => 'Assessor']);
        $d->learner = $gen->create_and_enrol($d->course, 'student', ['firstname' => 'Sam', 'lastname' => 'Student']);
        $assign = $gen->create_module('assign', ['course' => $d->course->id, 'name' => 'AT1', 'grade' => 100]);
        $cgen->create_course_module_competency(['cmid' => $assign->cmid, 'competencyid' => $pc->get('id')]);
        $d->cm = get_fast_modinfo($d->course)->get_cm($assign->cmid);
        return $d;
    }

    /**
     * Required tool fields: the default three, example text doesn't count, the setting decides.
     */
    public function test_tool_fields(): void {
        $d = $this->setup_data();
        $this->assertSame(['toolversion', 'reviewdate', 'reviewedby'], tool_details::get_missing_fields((int) $d->cm->id));
        tool_details::save((int) $d->cm->id, (object) ['toolversion' => 'v2', 'reviewdate' => time(),
            'reviewedby' => tool_details::example('conditions')]);
        $this->assertSame(['reviewedby'], tool_details::get_missing_fields((int) $d->cm->id), 'Example text is not filled in');
        set_config('toolrequired', 'toolversion,conditions', 'report_evidencereporter');
        $this->assertSame(['conditions'], tool_details::get_missing_fields((int) $d->cm->id));
        set_config('toolrequired', '', 'report_evidencereporter');
        $this->assertSame([], tool_details::get_missing_fields((int) $d->cm->id), 'Nothing required');
    }

    /**
     * The checklist status; assessors can't edit the activity, so they tell the editing teachers (messaged, logged,
     * shown); starting before the checks is logged.
     */
    public function test_status_notify_and_log(): void {
        $d = $this->setup_data();
        $this->setUser($d->assessor);
        $status = before_marking::status($d->cm, (int) $d->learner->id);
        $this->assertFalse($status->ready);
        $this->assertFalse($status->tool->canedit);
        $this->assertFalse($status->declcomplete);
        $this->assertSame('assessment tool details, RA and LLN', $status->missing);

        $sink = $this->redirectMessages();
        $events = $this->redirectEvents();
        $this->assertSame(1, before_marking::notify_tool($d->cm), 'The editing teacher, not the assessor');
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertEquals($d->editor->id, $messages[0]->useridto);
        $this->assertStringContainsString('Tool version, Reviewed before use on, Reviewed by', $messages[0]->fullmessage);
        $this->assertStringContainsString('Tia Assessor', before_marking::tool_status($d->cm)->notified);

        declarations::save($d->cm, [$d->learner->id => ['ra' => false, 'radetails' => '', 'lln' => false,
            'llndetails' => '']]);
        $status = before_marking::status($d->cm, (int) $d->learner->id);
        $this->assertTrue($status->declcomplete);
        $this->assertSame('assessment tool details', $status->missing);
        before_marking::log_unchecked($d->cm, (int) $d->learner->id, $status->missing);
        $names = array_map(fn($e) => $e->eventname, $events->get_events());
        $this->assertContains('\\report_evidencereporter\\event\\tool_gap_reported', $names);
        $this->assertContains('\\report_evidencereporter\\event\\marking_started_unchecked', $names);

        // Filled in: the notification no longer shows, and everything's ready.
        $this->setUser($d->editor);
        $this->assertTrue(before_marking::tool_status($d->cm)->canedit);
        tool_details::save((int) $d->cm->id, (object) ['toolversion' => 'v2', 'reviewdate' => time(), 'reviewedby' => 'Ed']);
        $this->assertTrue(before_marking::status($d->cm, (int) $d->learner->id)->ready);
        $this->assertSame('', before_marking::tool_status($d->cm)->notified);
    }

    /**
     * The preference, and the marking page warning.
     */
    public function test_preference_and_warning(): void {
        $d = $this->setup_data();
        $this->setUser($d->assessor);
        $this->assertFalse(before_marking::is_on());
        before_marking::set(true);
        $this->assertTrue(before_marking::is_on());

        global $PAGE;
        $PAGE->set_url(new \moodle_url('/mod/assign/view.php', ['id' => $d->cm->id, 'action' => 'grading']));
        $html = \report_evidencereporter\hook_callbacks::warning_html($d->cm, 0);
        $this->assertStringContainsString('assessment tool details are missing', $html);
        $this->assertStringContainsString('recorded for 1 learner(s)', $html);
        $this->assertStringContainsString('Let the editing teachers know', $html);
        $html = \report_evidencereporter\hook_callbacks::warning_html($d->cm, (int) $d->learner->id);
        $this->assertStringContainsString('Sam Student', $html);

        declarations::save($d->cm, [$d->learner->id => ['ra' => false, 'radetails' => '', 'lln' => false,
            'llndetails' => '']]);
        set_config('toolrequired', '', 'report_evidencereporter');
        $this->assertSame('', \report_evidencereporter\hook_callbacks::warning_html($d->cm, 0));
    }

    /**
     * Separate groups: assessors see only learners in their groups.
     */
    public function test_groups(): void {
        $d = $this->setup_data();
        $gen = $this->getDataGenerator();
        $other = $gen->create_and_enrol($d->course, 'student');
        $g1 = $gen->create_group(['courseid' => $d->course->id]);
        $g2 = $gen->create_group(['courseid' => $d->course->id]);
        $gen->create_group_member(['groupid' => $g1->id, 'userid' => $d->assessor->id]);
        $gen->create_group_member(['groupid' => $g1->id, 'userid' => $d->learner->id]);
        $gen->create_group_member(['groupid' => $g2->id, 'userid' => $other->id]);
        set_coursemodule_groupmode($d->cm->id, SEPARATEGROUPS);
        $cm = get_fast_modinfo($d->course)->get_cm($d->cm->id);
        $this->setUser($d->assessor);
        $this->assertTrue(declarations::can_see_learner($cm, (int) $d->learner->id));
        $this->assertFalse(declarations::can_see_learner($cm, (int) $other->id));
        $this->setUser($d->editor);
        $this->assertTrue(declarations::can_see_learner($cm, (int) $other->id), 'Editing teachers access all groups');
    }

    /**
     * Privacy: the notification and the preference.
     */
    public function test_privacy(): void {
        global $DB;
        $d = $this->setup_data();
        $this->setUser($d->assessor);
        before_marking::notify_tool($d->cm);
        before_marking::set(true);
        $provider = \report_evidencereporter\privacy\provider::class;
        $context = \context_module::instance($d->cm->id);
        $this->assertContains((int) $context->id, array_map(
            'intval',
            $provider::get_contexts_for_userid((int) $d->assessor->id)->get_contextids()
        ));
        $provider::export_user_preferences((int) $d->assessor->id);
        $prefs = \core_privacy\local\request\writer::with_context(\context_system::instance())
            ->get_user_preferences('report_evidencereporter');
        $this->assertObjectHasProperty(before_marking::PREFERENCE, $prefs);
        $provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            \core_user::get_user($d->assessor->id),
            'report_evidencereporter',
            [$context->id]
        ));
        $this->assertEquals(0, $DB->get_field(before_marking::TABLE, 'userid', ['cmid' => $d->cm->id]));
    }
}
