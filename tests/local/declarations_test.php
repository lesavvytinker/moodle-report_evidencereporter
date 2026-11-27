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

#[\PHPUnit\Framework\Attributes\CoversClass(declarations::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(release_hold::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(dates::class)]
/**
 * Tests for RA and LLN records and the release hold.
 *
 * @package    report_evidencereporter
 * @category   test
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\declarations
 * @covers     \report_evidencereporter\local\release_hold
 * @covers     \report_evidencereporter\local\dates
 * @covers     \report_evidencereporter\observer
 */
final class declarations_test extends \advanced_testcase {
    /**
     * A course with a criterion, two learners, a teacher, and an assignment (out of 100) linked to the criterion.
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
        $d->cgen = $cgen;
        $d->framework = $cgen->create_framework();
        $unit = $cgen->create_competency(['competencyframeworkid' => $d->framework->get('id'), 'idnumber' => 'U1']);
        $d->unit = $unit;
        $d->pc = $cgen->create_competency(['competencyframeworkid' => $d->framework->get('id'), 'idnumber' => 'U1.PC1',
            'parentid' => $unit->get('id')]);
        $d->pc2 = $cgen->create_competency(['competencyframeworkid' => $d->framework->get('id'), 'idnumber' => 'U1.PC2',
            'parentid' => $unit->get('id')]);
        $d->course = $gen->create_course();
        $cgen->create_course_competency(['courseid' => $d->course->id, 'competencyid' => $d->pc->get('id')]);
        $d->teacher = $gen->create_and_enrol($d->course, 'editingteacher', ['firstname' => 'Tia', 'lastname' => 'Teacher']);
        $d->one = $gen->create_and_enrol($d->course, 'student', ['firstname' => 'Ari', 'lastname' => 'One']);
        $d->two = $gen->create_and_enrol($d->course, 'student', ['firstname' => 'Bo', 'lastname' => 'Two']);
        $d->assign = $this->add_assign($d, 'AT1 Project');
        return $d;
    }

    /**
     * Add an assignment linked to a criterion, and check its hold (as the hourly task would).
     *
     * @param stdClass $d
     * @param string $name
     * @param array $options
     * @param \core_competency\competency|null $pc
     * @return \cm_info
     */
    private function add_assign(stdClass $d, string $name, array $options = [], $pc = null): \cm_info {
        $assign = $this->getDataGenerator()->create_module('assign', $options + ['course' => $d->course->id, 'name' => $name,
            'grade' => 100]);
        $d->cgen->create_course_module_competency(['cmid' => $assign->cmid, 'competencyid' => ($pc ?? $d->pc)->get('id')]);
        $cm = get_fast_modinfo($d->course->id, -1)->get_cm($assign->cmid);
        release_hold::update($cm);
        return $cm;
    }

    /**
     * The assignment's grade item, fresh.
     *
     * @param \cm_info $cm
     * @return \grade_item
     */
    private function item(\cm_info $cm): \grade_item {
        return \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $cm->instance,
            'itemnumber' => 0]);
    }

    /**
     * Record answers as the teacher.
     *
     * @param stdClass $d
     * @param \cm_info $cm
     * @param array $answers userid => [ra, radetails, lln, llndetails]
     */
    private function record(stdClass $d, \cm_info $cm, array $answers): void {
        $this->setUser($d->teacher);
        $data = [];
        foreach ($answers as $userid => [$ra, $radetails, $lln, $llndetails]) {
            $data[$userid] = ['ra' => $ra, 'radetails' => $radetails, 'lln' => $lln, 'llndetails' => $llndetails];
        }
        $this->assertSame([], declarations::validate($data));
        declarations::save($cm, $data);
    }

    /**
     * Held until both learners are recorded; then released, with the hidden setting it had.
     */
    public function test_hold_until_everyone_recorded(): void {
        $d = $this->setup_data();
        $this->assertEquals(1, $this->item($d->assign)->hidden, 'Held from the start');
        $this->assertTrue(release_hold::status($d->assign)->held);
        $this->assertCount(2, declarations::get_outstanding($d->assign));

        $this->record($d, $d->assign, [$d->one->id => [true, 'Extra 20 minutes', false, '']]);
        $this->assertEquals(1, $this->item($d->assign)->hidden, 'Still held: one to go');
        $this->assertSame([(int) $d->two->id], declarations::get_outstanding($d->assign));

        $this->record($d, $d->assign, [$d->two->id => [false, 'ignored', true, 'Glossary provided']]);
        $this->assertEquals(0, $this->item($d->assign)->hidden, 'Released');
        $status = release_hold::status($d->assign);
        $this->assertFalse($status->held);
        $this->assertTrue($status->applies);
        $two = declarations::get((int) $d->assign->id, (int) $d->two->id);
        $this->assertSame('', $two->radetails, 'No: no details kept');
        $this->assertSame('Glossary provided', $two->llndetails);
    }

    /**
     * A teacher showing a held item has it hidden again, and shown when the hold lifts; an item they had hidden before
     * the hold stays hidden after.
     */
    public function test_teacher_changes_while_held(): void {
        $d = $this->setup_data();
        $item = $this->item($d->assign);
        $item->hidden = 0;
        $item->update();
        $this->assertEquals(1, $this->item($d->assign)->hidden, 'Hidden again straight away');
        $this->record($d, $d->assign, [$d->one->id => [false, '', false, ''], $d->two->id => [false, '', false, '']]);
        $this->assertEquals(0, $this->item($d->assign)->hidden);

        // Hidden by the teacher before it was held: still hidden after.
        set_config('releasehold', 0, 'report_evidencereporter');
        $other = $this->add_assign($d, 'AT2 Report');
        $item = $this->item($other);
        $item->hidden = 1;
        $item->update();
        set_config('releasehold', 1, 'report_evidencereporter');
        release_hold::update($other);
        $this->assertTrue(release_hold::status($other)->held);
        $this->record($d, $other, [$d->one->id => [false, '', false, ''], $d->two->id => [false, '', false, '']]);
        $this->assertEquals(1, $this->item($other)->hidden, 'The teacher hid it: it stays hidden');
    }

    /**
     * After release, a learner who joins is held on their own; other learners' grades stay visible.
     */
    public function test_late_learner_held_alone(): void {
        $d = $this->setup_data();
        $this->record($d, $d->assign, [$d->one->id => [false, '', false, ''], $d->two->id => [false, '', false, '']]);
        $late = $this->getDataGenerator()->create_and_enrol($d->course, 'student');
        release_hold::update($d->assign);
        $this->assertEquals(0, $this->item($d->assign)->hidden);
        $grade = \grade_grade::fetch(['itemid' => $this->item($d->assign)->id, 'userid' => $late->id]);
        $this->assertEquals(1, $grade->hidden);
        $this->assertSame(1, release_hold::status($d->assign)->learnersheld);
        $this->record($d, $d->assign, [$late->id => [false, '', false, '']]);
        $grade = \grade_grade::fetch(['itemid' => $this->item($d->assign)->id, 'userid' => $late->id]);
        $this->assertEquals(0, $grade->hidden);
        $this->assertSame(0, release_hold::status($d->assign)->learnersheld);
    }

    /**
     * Already graded when holds began: exempt; graded learners stay visible, ungraded ones are held one by one.
     */
    public function test_exempt_when_already_graded(): void {
        global $DB;
        $d = $this->setup_data();
        set_config('releasehold', 0, 'report_evidencereporter');
        $cm = $this->add_assign($d, 'AT3 Older');
        $this->assertEquals(0, $this->item($cm)->hidden, 'Holding off: not held');
        grade_update('mod/assign', $d->course->id, 'mod', 'assign', $cm->instance, 0, [$d->one->id => (object) [
            'userid' => $d->one->id, 'rawgrade' => 80]]);
        set_config('releasehold', 1, 'report_evidencereporter');
        $this->assertGreaterThanOrEqual(1, release_hold::exempt_graded());
        release_hold::update($cm);
        $this->assertEquals(0, $this->item($cm)->hidden);
        $itemid = $this->item($cm)->id;
        $this->assertEquals(0, (int) \grade_grade::fetch(['itemid' => $itemid, 'userid' => $d->one->id])->hidden);
        $this->assertEquals(1, (int) \grade_grade::fetch(['itemid' => $itemid, 'userid' => $d->two->id])->hidden);
        $this->assertTrue(release_hold::status($cm)->exempt);
        $this->assertSame(0, release_hold::exempt_graded(), 'Only once');
        // Holding turned off: every hold lifts.
        set_config('releasehold', 0, 'report_evidencereporter');
        release_hold::update($cm);
        $this->assertEquals(0, (int) \grade_grade::fetch(['itemid' => $itemid, 'userid' => $d->two->id])->hidden);
        $this->assertFalse($DB->record_exists(release_hold::TABLE, ['gradeitemid' => $itemid, 'userid' => $d->two->id]));
    }

    /**
     * Only items that count toward the final grade are held.
     */
    public function test_only_counted_items(): void {
        $d = $this->setup_data();
        $item = $this->item($d->assign);
        $this->assertTrue(release_hold::counts_toward_final($item));
        // Natural, weight set to 0.
        $item->weightoverride = 1;
        $item->aggregationcoef2 = 0;
        $item->update();
        release_hold::update($d->assign);
        $this->assertFalse(release_hold::counts_toward_final($this->item($d->assign)));
        $this->assertEquals(0, $this->item($d->assign)->hidden, 'Released: no longer counts');
        $this->assertFalse(release_hold::status($d->assign)->applies);

        // An ungraded assignment.
        $none = $this->add_assign($d, 'Practice', ['grade' => 0]);
        $this->assertSame([], array_filter(release_hold::get_grade_items($none), fn($i) => release_hold::counts_toward_final($i)));

        // Weighted mean category with weight 0.
        $category = new \grade_category(['courseid' => $d->course->id, 'fullname' => 'Weighted',
            'aggregation' => GRADE_AGGREGATE_WEIGHTED_MEAN], false);
        $category->insert();
        $other = $this->add_assign($d, 'AT4');
        $item = $this->item($other);
        $item->set_parent($category->id);
        $item = $this->item($other);
        $item->aggregationcoef = 0;
        $item->update();
        $this->assertFalse(release_hold::counts_toward_final($this->item($other)));
        $item->aggregationcoef = 1;
        $item->update();
        $this->assertTrue(release_hold::counts_toward_final($this->item($other)));
        // The category itself weighted 0 in the course: nothing in it counts.
        $catitem = $category->load_grade_item();
        $catitem->weightoverride = 1;
        $catitem->aggregationcoef2 = 0;
        $catitem->update();
        $this->assertFalse(release_hold::counts_toward_final($this->item($other)));
    }

    /**
     * Details are needed for every "yes".
     */
    public function test_validate(): void {
        $this->resetAfterTest();
        $errors = declarations::validate([5 => ['ra' => true, 'radetails' => ' ', 'lln' => true, 'llndetails' => '']]);
        $this->assertSame(['ra', 'lln'], array_keys($errors[5]));
        $this->assertSame([], declarations::validate([5 => ['ra' => false, 'radetails' => '', 'lln' => false,
            'llndetails' => '']]));
    }

    /**
     * Attempts in a Highest grade category share records: recorded once, carried over, kept again only when different.
     */
    public function test_attempts_share_records(): void {
        global $DB;
        $d = $this->setup_data();
        $category = new \grade_category(['courseid' => $d->course->id, 'fullname' => 'AT5 attempts',
            'aggregation' => GRADE_AGGREGATE_MAX], false);
        $category->insert();
        $first = $this->add_assign($d, 'AT5 Attempt 1');
        $second = $this->add_assign($d, 'AT5 Attempt 2');
        foreach ([$first, $second] as $cm) {
            $this->item($cm)->set_parent($category->id);
        }
        $this->assertSame([(int) $second->id, (int) $first->id], declarations::get_group($second));

        $this->record($d, $first, [$d->one->id => [true, 'Reader', false, ''], $d->two->id => [false, '', true, 'Glossary']]);
        $this->assertSame([], declarations::get_outstanding($second), 'Carried over: nothing to record');
        release_hold::update($second);
        $this->assertEquals(0, $this->item($second)->hidden, 'Released with the first');
        $carried = declarations::get_effective($second);
        $this->assertSame((int) $first->id, $carried[$d->one->id]->carriedfrom);
        $this->assertSame('AT5 Attempt 1', $carried[$d->one->id]->carriedname);

        // Saving the second attempt's page unchanged keeps nothing new; a change is kept for that attempt only.
        $this->record($d, $second, [$d->one->id => [true, 'Reader', false, ''], $d->two->id => [false, '', true, 'Glossary']]);
        $this->assertSame(0, $DB->count_records(declarations::TABLE, ['cmid' => $second->id]));
        $this->record($d, $second, [$d->one->id => [true, 'Reader and scribe', false, ''], $d->two->id => [false, '', true,
            'Glossary']]);
        $this->assertSame(1, $DB->count_records(declarations::TABLE, ['cmid' => $second->id]));
        $this->assertSame('Reader', declarations::get((int) $first->id, (int) $d->one->id)->radetails);
        $this->assertSame(0, declarations::get_effective($second)[$d->one->id]->carriedfrom);
    }

    /**
     * Different assessments in one category keep their own records.
     */
    public function test_separate_assessments_dont_share(): void {
        $d = $this->setup_data();
        $category = new \grade_category(['courseid' => $d->course->id, 'fullname' => 'Unit 1',
            'aggregation' => GRADE_AGGREGATE_SUM], false);
        $category->insert();
        $a = $this->add_assign($d, 'AT6', [], $d->pc);
        $b = $this->add_assign($d, 'AT7', [], $d->pc2);
        foreach ([$a, $b] as $cm) {
            $this->item($cm)->set_parent($category->id);
        }
        $this->assertSame([(int) $a->id], declarations::get_group($a));
        $this->record($d, $a, [$d->one->id => [false, '', false, ''], $d->two->id => [false, '', false, '']]);
        $this->assertCount(2, declarations::get_outstanding($b));
    }

    /**
     * Shown per assessment: LLN as N/A, details or Not recorded, and the recorded RA where there's no detailed one.
     */
    public function test_reporting(): void {
        $d = $this->setup_data();
        $this->setAdminUser();
        $this->record($d, $d->assign, [$d->one->id => [true, 'Extra 20 minutes', true, 'Read aloud']]);
        $this->setAdminUser();
        $lines = reasonable_adjustments::get_by_assessment((int) $d->one->id, [(int) $d->pc->get('id')]);
        $line = array_values(array_filter($lines, fn($l) => $l->cmid === (int) $d->assign->id))[0];
        $this->assertSame('Read aloud', $line->lln);
        $this->assertStringContainsString('Extra 20 minutes', $line->text);
        $lines = reasonable_adjustments::get_by_assessment((int) $d->two->id, [(int) $d->pc->get('id')]);
        $line = array_values(array_filter($lines, fn($l) => $l->cmid === (int) $d->assign->id))[0];
        $this->assertSame('Not recorded', $line->lln);
        $this->assertSame('N/A', $line->text);
        $judgement = declarations::for_judgement((int) $d->one->id, (int) $d->course->id, (int) $d->unit->get('id'));
        $this->assertSame('AT1 Project: Read aloud', $judgement->lln);
        $this->assertStringContainsString('Extra 20 minutes', reasonable_adjustments::for_judgement(
            (int) $d->one->id,
            (int) $d->course->id,
            (int) $d->unit->get('id')
        ));
    }

    /**
     * Privacy: exported for the learner and the teacher; deleted for the learner, anonymised for the teacher; gone with
     * the activity.
     */
    public function test_privacy(): void {
        global $DB;
        $d = $this->setup_data();
        $this->record($d, $d->assign, [$d->one->id => [false, '', true, 'Read aloud']]);
        $context = \context_module::instance($d->assign->id);
        $provider = \report_evidencereporter\privacy\provider::class;
        $this->assertContains((int) $context->id, array_map(
            'intval',
            $provider::get_contexts_for_userid((int) $d->one->id)->get_contextids()
        ));
        $provider::export_user_data(new \core_privacy\local\request\approved_contextlist(
            \core_user::get_user($d->one->id),
            'report_evidencereporter',
            [$context->id]
        ));
        $data = \core_privacy\local\request\writer::with_context($context)->get_data([get_string(
            'adjustments',
            'report_evidencereporter'
        )]);
        $this->assertSame('Read aloud', $data->raandllnforyou['llndetails']);
        $provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            \core_user::get_user($d->teacher->id),
            'report_evidencereporter',
            [$context->id]
        ));
        $this->assertEquals(0, $DB->get_field(declarations::TABLE, 'usermodified', ['cmid' => $d->assign->id]));
        $provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            \core_user::get_user($d->one->id),
            'report_evidencereporter',
            [$context->id]
        ));
        $this->assertFalse($DB->record_exists(declarations::TABLE, ['cmid' => $d->assign->id]));
        course_delete_module((int) $d->assign->id);
        $this->assertFalse($DB->record_exists(release_hold::TABLE, ['cmid' => $d->assign->id]));
    }

    /**
     * The summary flags assessments missing RA and LLN, rolled up to the unit; "complete" when the evidence is.
     */
    public function test_summary_flags(): void {
        $d = $this->setup_data();
        $egen = $this->getDataGenerator()->get_plugin_generator('report_evidencereporter');
        $this->setAdminUser();
        $byid = fn($summary) => array_column(array_map(fn($c) => (array) $c, $summary->competencies), null, 'id');

        $summary = summary::build((int) $d->one->id, (int) $d->framework->get('id'));
        $comps = $byid($summary);
        $this->assertSame('missing', $comps[$d->pc->get('id')]['declflag']);
        $this->assertSame('missing', $comps[$d->unit->get('id')]['declflag'], 'Rolled up to the unit');
        $this->assertSame(['AT1 Project'], $comps[$d->unit->get('id')]['declmissing']);
        $this->assertSame('', $comps[$d->pc2->get('id')]['declflag'], 'Nothing linked to PC2');
        $this->assertCount(1, $summary->declmissing);

        // Evidence for both criteria: the unit's evidence is complete, so the flag is the serious kind.
        foreach ([$d->pc, $d->pc2] as $pc) {
            $egen->create_evidence(['userid' => $d->one->id, 'competencyid' => $pc->get('id'), 'cmid' => $d->assign->id,
                'action' => \core_competency\evidence::ACTION_COMPLETE]);
        }
        $comps = $byid(summary::build((int) $d->one->id, (int) $d->framework->get('id')));
        $this->assertSame('complete', $comps[$d->unit->get('id')]['declflag']);
        $this->assertSame('complete', $comps[$d->pc->get('id')]['declflag']);

        // Recorded: no flags.
        $this->record($d, $d->assign, [$d->one->id => [false, '', false, '']]);
        $this->setAdminUser();
        $summary = summary::build((int) $d->one->id, (int) $d->framework->get('id'));
        $comps = $byid($summary);
        $this->assertSame('', $comps[$d->unit->get('id')]['declflag']);
        $this->assertSame([], $summary->declmissing);
        $line = array_values(array_filter($summary->assessments, fn($a) => $a->cmid === (int) $d->assign->id))[0];
        $this->assertTrue($line->recorded);
        $this->assertStringContainsString('Tia Teacher', $line->recordedtext);
    }

    /**
     * Learning plans covering the units, for the marking page.
     */
    public function test_relevant_plans(): void {
        $d = $this->setup_data();
        $this->setAdminUser();
        $plan = $d->cgen->create_plan(['userid' => $d->one->id, 'name' => 'Cert III plan',
            'status' => \core_competency\plan::STATUS_ACTIVE]);
        $d->cgen->create_plan_competency(['planid' => $plan->get('id'), 'competencyid' => $d->pc->get('id')]);
        $other = $d->cgen->create_plan(['userid' => $d->one->id, 'name' => 'Other plan',
            'status' => \core_competency\plan::STATUS_ACTIVE]);
        $plans = marking::get_relevant_plans((int) $d->one->id, [(int) $d->unit->get('id')]);
        $this->assertSame(['Cert III plan'], array_column($plans, 'name'));
        $this->assertStringContainsString('plan.php?id=' . $plan->get('id'), $plans[0]['url']);
        $this->assertSame([], marking::get_relevant_plans((int) $d->two->id, [(int) $d->unit->get('id')]));
        $this->assertNotEmpty($other);
    }

    /**
     * Date and time formats from the settings; Moodle's language formats by default.
     */
    public function test_date_formats(): void {
        $this->resetAfterTest();
        $this->setTimezone('Australia/Brisbane');
        $time = make_timestamp(2026, 11, 7, 14, 5, 0, 'Australia/Brisbane');
        $this->assertSame(userdate($time, get_string('strftimedate', 'core_langconfig')), dates::date($time));
        set_config('dateformat', 'dmy', 'report_evidencereporter');
        set_config('timeformat', '24', 'report_evidencereporter');
        $this->assertSame('07/11/2026', dates::date($time));
        $this->assertSame('07/11/2026, 14:05', dates::datetime($time));
        set_config('dateformat', 'mdy', 'report_evidencereporter');
        set_config('timeformat', '12', 'report_evidencereporter');
        $this->assertSame('11/07/2026', dates::date($time));
        $this->assertMatchesRegularExpression('/^2:05 [Pp][Mm]$/', dates::time($time));
        set_config('dateformat', 'ymd', 'report_evidencereporter');
        $this->assertSame('2026-11-07', dates::date($time));
        set_config('dateformat', 'dmonthy', 'report_evidencereporter');
        $this->assertSame('7 November 2026', dates::date($time));
        set_config('dateformat', 'monthdy', 'report_evidencereporter');
        $this->assertSame('November 7, 2026', dates::date($time));
        $this->assertSame('', dates::rb_datetime(null));
        [$datechoices, $timechoices] = dates::get_choices();
        $this->assertArrayHasKey('dmy', $datechoices);
        $this->assertSame('24-hour (14:05)', $timechoices['24']);
    }
}
