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

#[\PHPUnit\Framework\Attributes\CoversClass(marking::class)]
/**
 * Tests for the marking page's statuses, units, order and counts.
 *
 * @package    report_evidencereporter
 * @category   test
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\marking
 */
final class marking_test extends \advanced_testcase {
    /**
     * Two unit frameworks (unit > element > criterion), a course delivering both, an assignment per unit (pass mark 50),
     * a teacher and five learners.
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
        $d->course = $gen->create_course();
        $d->units = [];
        $d->pcs = [];
        foreach (['U1', 'U2'] as $code) {
            $fw = $cgen->create_framework(['shortname' => $code, 'idnumber' => $code . '-FW']);
            $unit = $cgen->create_competency(['competencyframeworkid' => $fw->get('id'), 'idnumber' => $code,
                'shortname' => 'Unit ' . $code]);
            $element = $cgen->create_competency(['competencyframeworkid' => $fw->get('id'), 'idnumber' => $code . '.E1',
                'shortname' => 'Element', 'parentid' => $unit->get('id')]);
            $pc = $cgen->create_competency(['competencyframeworkid' => $fw->get('id'), 'idnumber' => $code . '.E1.PC1.1',
                'shortname' => 'Criterion', 'parentid' => $element->get('id')]);
            $cgen->create_course_competency(['courseid' => $d->course->id, 'competencyid' => $pc->get('id')]);
            $d->units[$code] = (int) $unit->get('id');
            $d->pcs[$code] = (int) $pc->get('id');
        }
        $d->teacher = $gen->create_and_enrol($d->course, 'editingteacher');
        $d->learners = [];
        foreach (['Ann', 'Ben', 'Cal', 'Dee', 'Eve'] as $name) {
            $d->learners[$name] = $gen->create_and_enrol($d->course, 'student', ['firstname' => $name, 'lastname' => 'Learner']);
        }
        $d->assign = [];
        foreach (['U1', 'U2'] as $code) {
            $assign = $gen->create_module('assign', ['course' => $d->course->id, 'name' => 'Task ' . $code,
                'maxattempts' => 3, 'grade' => 100]);
            $cgen->create_course_module_competency(['cmid' => $assign->cmid, 'competencyid' => $d->pcs[$code]]);
            $item = \grade_item::fetch(['courseid' => $d->course->id, 'itemtype' => 'mod', 'itemmodule' => 'assign',
                'iteminstance' => $assign->id, 'itemnumber' => 0]);
            $item->gradepass = 50;
            $item->update();
            $d->assign[$code] = $assign;
        }
        return $d;
    }

    /**
     * Submit and optionally grade an assignment for a learner.
     *
     * @param stdClass $assign
     * @param stdClass $user
     * @param int $daysago When it was submitted
     * @param float|null $grade
     * @param string $feedback
     */
    private function submit(stdClass $assign, stdClass $user, int $daysago, ?float $grade = null, string $feedback = ''): void {
        global $DB;
        $DB->insert_record('assign_submission', ['assignment' => $assign->id, 'userid' => $user->id, 'status' => 'submitted',
            'latest' => 1, 'attemptnumber' => 0, 'groupid' => 0, 'timecreated' => time() - $daysago * DAYSECS,
            'timemodified' => time() - $daysago * DAYSECS]);
        if ($grade !== null) {
            $gradeid = $DB->insert_record('assign_grades', ['assignment' => $assign->id, 'userid' => $user->id,
                'attemptnumber' => 0, 'grader' => 2, 'grade' => $grade, 'timecreated' => time() - DAYSECS,
                'timemodified' => time() - DAYSECS]);
            if ($feedback !== '') {
                $DB->insert_record('assignfeedback_comments', ['assignment' => $assign->id, 'grade' => $gradeid,
                    'commenttext' => $feedback, 'commentformat' => FORMAT_HTML]);
            }
        }
    }

    /**
     * Units: a unit-per-framework course lists each unit; a qualification framework (four levels) lists its units.
     */
    public function test_units(): void {
        $d = $this->setup_data();
        $this->assertSame(array_values($d->units), array_keys(marking::get_units((int) $d->course->id)));

        $cgen = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $fw = $cgen->create_framework(['shortname' => 'Q', 'idnumber' => 'QUAL']);
        $qual = $cgen->create_competency(['competencyframeworkid' => $fw->get('id'), 'idnumber' => 'CERT3']);
        $unit = $cgen->create_competency(['competencyframeworkid' => $fw->get('id'), 'idnumber' => 'Q.U1',
            'parentid' => $qual->get('id')]);
        $element = $cgen->create_competency(['competencyframeworkid' => $fw->get('id'), 'idnumber' => 'Q.U1.E1',
            'parentid' => $unit->get('id')]);
        $cgen->create_competency(['competencyframeworkid' => $fw->get('id'), 'idnumber' => 'Q.U1.E1.PC1',
            'parentid' => $element->get('id')]);
        $this->assertSame([(int) $unit->get('id')], array_keys(marking::framework_units((int) $fw->get('id'))));
        $this->assertSame([$d->units['U1']], array_keys(marking::framework_units(
            (int) \core_competency\competency::get_record(['id' => $d->units['U1']])->get('competencyframeworkid')
        )));
    }

    /**
     * Each status, with the most urgent learners first and the counts.
     */
    public function test_statuses_order_and_counts(): void {
        global $DB;
        $d = $this->setup_data();
        $u1 = $d->assign['U1'];
        $this->submit($u1, $d->learners['Ann'], 3);
        $this->submit($u1, $d->learners['Ben'], 8);
        $this->submit($u1, $d->learners['Cal'], 15);
        $this->submit($u1, $d->learners['Dee'], 4, 30);
        $this->submit($u1, $d->learners['Eve'], 4, 80, 'Well done');
        // Eve has passed U2 too: ready to sign off U2.
        $this->submit($d->assign['U2'], $d->learners['Eve'], 2, 90, 'Good');

        $this->setUser($d->teacher);
        $grid = marking::build((int) $d->course->id);
        $this->assertSame(['Task U1', 'Task U2'], array_values(array_map(fn($a) => $a->cm->name, $grid->assessments)));
        $order = array_map(fn($row) => $row->user->firstname, $grid->rows);
        $this->assertSame(['Dee', 'Cal', 'Ben', 'Eve', 'Ann'], $order, 'Feedback needed, red, amber, ready, new');

        $cells = [];
        foreach ($grid->rows as $row) {
            $cells[$row->user->firstname] = $row->cells[$u1->cmid];
        }
        $this->assertSame(marking::STATUS_TOMARK, $cells['Ann']->status);
        $this->assertSame(marking::AGE_NEW, $cells['Ann']->age);
        $this->assertSame(3, $cells['Ann']->days);
        $this->assertSame(marking::AGE_AMBER, $cells['Ben']->age);
        $this->assertSame(marking::AGE_RED, $cells['Cal']->age);
        $this->assertSame(marking::STATUS_FEEDBACK, $cells['Dee']->status);
        $this->assertSame(marking::STATUS_SATISFACTORY, $cells['Eve']->status);
        $this->assertSame('Attempt 1 of 3', $cells['Eve']->attempt);
        $this->assertStringContainsString('action=grader', $cells['Eve']->url);
        $this->assertSame(marking::STATUS_NOTSUBMITTED, $grid->rows[4]->cells[$d->assign['U2']->cmid]->status);
        $eve = array_values(array_filter($grid->rows, fn($r) => $r->user->firstname === 'Eve'))[0];
        // Satisfactory in both, but RA and LLN aren't recorded yet: ready once they are.
        $this->assertSame([], $eve->ready);
        $this->assertSame([$d->units['U1'], $d->units['U2']], $eve->readypending);
        $this->assertSame(2, $eve->declmissing);
        $this->assertSame(10, $grid->counts->torecord, 'Five learners, two assessments');
        $this->assertSame(0, $grid->counts->ready);

        $modinfo = get_fast_modinfo($d->course);
        foreach (['U1', 'U2'] as $unit) {
            \report_evidencereporter\local\declarations::save($modinfo->get_cm($d->assign[$unit]->cmid), [
                $d->learners['Eve']->id => ['ra' => false, 'radetails' => '', 'lln' => false, 'llndetails' => '']]);
        }
        $grid = marking::build((int) $d->course->id);
        $eve = array_values(array_filter($grid->rows, fn($r) => $r->user->firstname === 'Eve'))[0];
        $this->assertSame([$d->units['U1'], $d->units['U2']], $eve->ready);
        $this->assertSame([], $eve->readypending);

        $counts = $grid->counts;
        $this->assertSame(3, $counts->tomark);
        $this->assertSame(1, $counts->amber);
        $this->assertSame(1, $counts->red);
        $this->assertSame(1, $counts->feedback);
        $this->assertSame(2, $counts->ready);
        $this->assertSame(8, $counts->torecord);

        // Feedback given: waiting for the learner's next attempt.
        $grade = $DB->get_record('assign_grades', ['assignment' => $u1->id, 'userid' => $d->learners['Dee']->id]);
        $DB->insert_record('assignfeedback_comments', ['assignment' => $u1->id, 'grade' => $grade->id,
            'commenttext' => 'Add your sources', 'commentformat' => FORMAT_HTML]);
        $cell = marking::get_cells(get_fast_modinfo($d->course)->get_cm($u1->cmid), [(int) $d->learners['Dee']->id]);
        $this->assertSame(marking::STATUS_NOTSATISFACTORY, reset($cell)->status);

        // Marking workflow: marked but not released.
        $DB->set_field('assign', 'markingworkflow', 1, ['id' => $u1->id]);
        $cell = marking::get_cells(get_fast_modinfo($d->course)->get_cm($u1->cmid), [(int) $d->learners['Eve']->id]);
        $this->assertSame(marking::STATUS_NOTRELEASED, reset($cell)->status);

        // One unit: its assessments only, and the unit result column.
        $grid = marking::build((int) $d->course->id, $d->units['U2']);
        $this->assertSame([(int) $d->assign['U2']->cmid], array_keys($grid->assessments));
        $eve = array_values(array_filter($grid->rows, fn($r) => $r->user->firstname === 'Eve'))[0];
        $this->assertTrue($eve->unit->ready);
    }

    /**
     * The site's thresholds; staff aren't listed as learners; the overview totals the user's courses.
     */
    public function test_thresholds_learners_and_overview(): void {
        $d = $this->setup_data();
        set_config('markingamber', 2, 'report_evidencereporter');
        set_config('markingred', 5, 'report_evidencereporter');
        $this->assertSame([2, 5], marking::get_thresholds());
        $this->assertSame(marking::AGE_AMBER, marking::age(3));
        $this->assertSame(marking::AGE_RED, marking::age(5));
        set_config('markingred', 1, 'report_evidencereporter');
        $this->assertSame([2, 2], marking::get_thresholds(), 'Red is never before amber');

        $this->submit($d->assign['U1'], $d->learners['Ann'], 3);
        $this->setUser($d->teacher);
        $this->assertNotContains((int) $d->teacher->id, array_keys(marking::get_learners((int) $d->course->id)));
        $this->assertCount(5, marking::get_learners((int) $d->course->id));
        $overview = marking::get_overview();
        $this->assertSame([(int) $d->course->id, 0], array_keys($overview));
        $this->assertSame(1, $overview[0]->counts->tomark);

        // Learners don't mark.
        $this->setUser($d->learners['Ann']);
        $this->assertSame([0], array_keys(marking::get_overview()));
    }
}
