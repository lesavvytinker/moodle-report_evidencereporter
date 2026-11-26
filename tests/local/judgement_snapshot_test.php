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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use report_evidencereporter\privacy\provider;
use stdClass;

#[\PHPUnit\Framework\Attributes\CoversClass(judgement_snapshot::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(staff::class)]
/**
 * Tests for judgement snapshots (wording and assessor credentials at the time) and staff chosen from a list.
 *
 * @package    report_evidencereporter
 * @category   test
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\judgement_snapshot
 * @covers     \report_evidencereporter\local\staff
 */
final class judgement_snapshot_test extends \advanced_testcase {
    /**
     * A framework with a unit, a course, a learner, an assessor with credentials in profile fields, and the setting
     * that reads them.
     *
     * @return stdClass
     */
    private function setup_data(): stdClass {
        global $DB;
        $this->resetAfterTest();
        set_config('enabled', 1, 'core_competency');
        $gen = $this->getDataGenerator();
        $cgen = $gen->get_plugin_generator('core_competency');
        $d = new stdClass();
        $d->framework = $cgen->create_framework(['shortname' => 'Detention services', 'idnumber' => 'CSC']);
        $d->unit = $cgen->create_competency(['competencyframeworkid' => $d->framework->get('id'), 'idnumber' => 'CSCSAS001',
            'shortname' => 'Maintain security', 'description' => 'Original description']);
        $d->course = $gen->create_course();
        $d->learner = $gen->create_and_enrol($d->course, 'student', ['firstname' => 'Lee', 'lastname' => 'Learner']);
        $d->assessor = $gen->create_and_enrol($d->course, 'editingteacher', ['firstname' => 'Ash', 'lastname' => 'Assessor']);
        $cgen->create_course_competency(['courseid' => $d->course->id, 'competencyid' => $d->unit->get('id')]);

        $tae = $gen->create_custom_profile_field(['datatype' => 'text', 'shortname' => 'tae',
            'name' => 'Training qualification']);
        $currency = $gen->create_custom_profile_field(['datatype' => 'datetime', 'shortname' => 'currency',
            'name' => 'Industry currency']);
        $DB->insert_record('user_info_data', ['userid' => $d->assessor->id, 'fieldid' => $tae->id, 'data' => 'TAE40122',
            'dataformat' => 0]);
        $DB->insert_record('user_info_data', ['userid' => $d->assessor->id, 'fieldid' => $currency->id,
            'data' => (string) make_timestamp(2026, 3, 1, 12), 'dataformat' => 0]);
        $d->taefieldid = (int) $tae->id;
        set_config('credentialfields', 'tae,currency', 'report_evidencereporter');
        return $d;
    }

    /**
     * Rate the learner in the unit as the assessor.
     *
     * @param stdClass $d
     */
    private function rate(stdClass $d): void {
        $this->getDataGenerator()->get_plugin_generator('report_evidencereporter')->create_course_rating([
            'userid' => $d->learner->id, 'competencyid' => $d->unit->get('id'), 'courseid' => $d->course->id,
            'grade' => 2, 'actionuserid' => $d->assessor->id]);
    }

    /**
     * A rating keeps the wording and the assessor's credentials; evidence that isn't a judgement keeps nothing.
     */
    public function test_capture(): void {
        global $DB;
        $d = $this->setup_data();
        $gen = $this->getDataGenerator()->get_plugin_generator('report_evidencereporter');
        $gen->create_evidence(['userid' => $d->learner->id, 'competencyid' => $d->unit->get('id')]);
        $this->assertEquals(0, $DB->count_records(judgement_snapshot::TABLE));

        $this->rate($d);
        $snapshots = $DB->get_records(judgement_snapshot::TABLE);
        $this->assertCount(1, $snapshots);
        $snapshot = reset($snapshots);
        $this->assertEquals($d->learner->id, $snapshot->userid);
        $this->assertEquals($d->assessor->id, $snapshot->assessorid);
        $this->assertEquals('CSCSAS001', $snapshot->compidnumber);
        $this->assertEquals('Maintain security', $snapshot->compshortname);
        $this->assertEquals('Detention services', $snapshot->fwshortname);
        $this->assertEquals(0, $snapshot->backfilled);

        $described = judgement_snapshot::describe([$snapshot]);
        $item = $described[$snapshot->evidenceid];
        $this->assertFalse($item->changed);
        $this->assertStringContainsString('Training qualification: TAE40122', $item->credentials);
        $this->assertStringContainsString('Industry currency: ', $item->credentials);

        // Capturing again doesn't duplicate.
        $this->assertNull(judgement_snapshot::capture((int) $snapshot->evidenceid));

        // Credentials changed later don't change the snapshot.
        $DB->set_field('user_info_data', 'data', 'TAE50122', ['userid' => $d->assessor->id, 'fieldid' => $d->taefieldid]);
        $described = judgement_snapshot::describe([$DB->get_record(judgement_snapshot::TABLE, ['id' => $snapshot->id])]);
        $this->assertStringContainsString('TAE40122', $described[$snapshot->evidenceid]->credentials);
        $this->assertStringNotContainsString('TAE50122', $described[$snapshot->evidenceid]->credentials);
    }

    /**
     * Editing the unit after it was judged: the summary keeps the wording at the time and says it changed.
     */
    public function test_summary_after_edit(): void {
        $d = $this->setup_data();
        $this->rate($d);

        $this->setAdminUser();
        $summary = summary::build((int) $d->learner->id, (int) $d->framework->get('id'));
        $this->assertFalse($summary->changedsincejudged);
        $this->assertNull($summary->competencies[0]->judgedagainst);
        $this->assertStringContainsString('TAE40122', $summary->competencies[0]->judgementcredentials);
        $judgements = array_filter($summary->competencies[0]->evidence, fn($e) => $e->isjudgement);
        $this->assertStringContainsString('TAE40122', reset($judgements)->credentials);

        $unit = new \core_competency\competency($d->unit->get('id'));
        $unit->set('shortname', 'Maintain security (revised)');
        $unit->set('description', 'New description');
        $unit->update();

        $summary = summary::build((int) $d->learner->id, (int) $d->framework->get('id'));
        $this->assertTrue($summary->changedsincejudged);
        $then = $summary->competencies[0]->judgedagainst;
        $this->assertEquals('CSCSAS001 Maintain security', $then->wording);
        $this->assertEquals('Original description', $then->description);
        $this->assertFalse($then->backfilled);
        $this->assertStringContainsString('revised', $summary->competencies[0]->name);

        // The evidence pack's summary PDF says so too.
        $pack = new evidence_pack((int) $d->learner->id, (int) $d->framework->get('id'));
        $html = $this->call_summary_html($pack, (int) $d->learner->id, (int) $d->framework->get('id'));
        $this->assertStringContainsString(s(get_string('judgedagainst', 'report_evidencereporter',
            'CSCSAS001 Maintain security')), $html);
        $this->assertStringContainsString('TAE40122', $html);
    }

    /**
     * Learners see their assessors' credentials only when the site allows it.
     */
    public function test_learner_view(): void {
        $d = $this->setup_data();
        $this->rate($d);
        $this->setUser($d->learner);
        $this->assertFalse(judgement_snapshot::can_view_credentials((int) $d->learner->id));
        $this->setUser($d->assessor);
        $this->assertTrue(judgement_snapshot::can_view_credentials((int) $d->learner->id));
        set_config('credentialslearner', 1, 'report_evidencereporter');
        $this->setUser($d->learner);
        $this->assertTrue(judgement_snapshot::can_view_credentials((int) $d->learner->id));
    }

    /**
     * Judgements made before snapshots were kept get today's wording, marked as backfilled, with no credentials.
     */
    public function test_backfill(): void {
        global $DB;
        $d = $this->setup_data();
        $this->rate($d);
        $DB->delete_records(judgement_snapshot::TABLE);

        $this->assertEquals(1, judgement_snapshot::backfill());
        $this->assertEquals(0, judgement_snapshot::backfill());
        $snapshot = $DB->get_record(judgement_snapshot::TABLE, []);
        $this->assertEquals(1, $snapshot->backfilled);
        $this->assertNull($snapshot->credentials);
        $this->assertEquals('', judgement_snapshot::describe([$snapshot])[$snapshot->evidenceid]->credentials);
    }

    /**
     * Privacy: learners' snapshots are exported and deleted; assessors are exported and anonymised.
     */
    public function test_privacy(): void {
        global $DB;
        $d = $this->setup_data();
        $this->rate($d);
        $system = \context_system::instance();
        foreach ([$d->learner, $d->assessor] as $user) {
            $this->assertContainsEquals($system->id, provider::get_contexts_for_userid((int) $user->id)->get_contextids());
        }

        $folder = [get_string('privacy:metadata:jsnap', 'report_evidencereporter')];
        provider::export_user_data(new approved_contextlist($d->assessor, 'report_evidencereporter', [$system->id]));
        $data = writer::with_context($system)->get_data($folder);
        $this->assertCount(1, $data->judgedbyyou);
        $this->assertStringContainsString('TAE40122', $data->judgedbyyou[0]['credentials']);

        provider::delete_data_for_user(new approved_contextlist($d->assessor, 'report_evidencereporter', [$system->id]));
        $snapshot = $DB->get_record(judgement_snapshot::TABLE, []);
        $this->assertEquals(0, $snapshot->assessorid);
        $this->assertNull($snapshot->credentials);
        $this->assertEquals('Maintain security', $snapshot->compshortname);

        provider::delete_data_for_user(new approved_contextlist($d->learner, 'report_evidencereporter', [$system->id]));
        $this->assertEquals(0, $DB->count_records(judgement_snapshot::TABLE));
    }

    /**
     * Staff lists: who's listed, names shown, and choosing and typing both is refused.
     */
    public function test_staff(): void {
        $d = $this->setup_data();
        $context = \context_course::instance($d->course->id);
        $this->setUser($d->assessor);
        $options = staff::options($context);
        $this->assertArrayHasKey((int) $d->assessor->id, $options);
        $this->assertArrayNotHasKey((int) $d->learner->id, $options);
        $this->assertTrue(staff::is_valid_choice((int) $d->assessor->id, $context));
        $this->assertFalse(staff::is_valid_choice((int) $d->learner->id, $context));
        $this->assertTrue(staff::is_valid_choice((int) $d->learner->id, $context, (int) $d->learner->id));

        $this->assertEquals('Ash Assessor', staff::name((int) $d->assessor->id, 'Typed'));
        $this->assertEquals('Industry rep', staff::name(0, 'Industry rep'));
        $this->assertNotEmpty(staff::validate(['approvedbyid' => $d->assessor->id, 'approvedby' => 'X'], 'approvedby'));
        $this->assertEmpty(staff::validate(['approvedbyid' => $d->assessor->id, 'approvedby' => ''], 'approvedby'));
    }

    /**
     * A reasonable adjustment approved by a chosen staff member shows their name; tool details likewise.
     */
    public function test_chosen_names(): void {
        $d = $this->setup_data();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $d->course->id]);
        $this->setUser($d->assessor);
        $id = reasonable_adjustments::save((object) ['cmid' => $quiz->cmid, 'userid' => $d->learner->id,
            'adjtype' => 'reader', 'details' => 'Read aloud', 'approvedbyid' => $d->assessor->id]);
        $this->assertEquals('Ash Assessor', reasonable_adjustments::approver(reasonable_adjustments::get($id)));

        tool_details::save((int) $quiz->cmid, (object) ['toolversion' => '1.0', 'reviewedbyid' => $d->assessor->id]);
        $this->assertEquals('Ash Assessor', tool_details::reviewer(tool_details::get((int) $quiz->cmid)));
        tool_details::save((int) $quiz->cmid, (object) ['toolversion' => '1.0', 'reviewedby' => 'Industry rep']);
        $this->assertEquals('Industry rep', tool_details::reviewer(tool_details::get((int) $quiz->cmid)));
    }

    /**
     * The evidence pack's summary PDF HTML.
     *
     * @param evidence_pack $pack
     * @param int $userid
     * @param int $frameworkid
     * @return string
     */
    private function call_summary_html(evidence_pack $pack, int $userid, int $frameworkid): string {
        $method = new \ReflectionMethod($pack, 'summary_html');
        return (string) $method->invoke($pack, summary::build($userid, $frameworkid));
    }
}
