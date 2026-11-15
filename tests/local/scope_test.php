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

use core_competency\api;
use core_competency\plan;

/**
 * Summaries and packs per learning plan: core plan from a template plus an electives plan.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(scope::class)]
final class scope_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        attempts::reset_cache();
        feedback_visibility::reset_cache();
    }

    /**
     * A qualification framework: core unit CORE1 (element E1 with PC1.1 and PC1.2), elective ELEC1 and an elective
     * the learner doesn't take (ELEC2). Core plan from a template, electives in a separate plan.
     *
     * @return array framework, student, competencies by idnumber, core plan, electives plan, assignment cmid
     */
    private function setup_data(): array {
        $gen = $this->getDataGenerator();
        /** @var \core_competency_generator $cgen */
        $cgen = $gen->get_plugin_generator('core_competency');
        /** @var \report_evidencereporter_generator $egen */
        $egen = $gen->get_plugin_generator('report_evidencereporter');

        $framework = $cgen->create_framework(['shortname' => 'Diploma', 'idnumber' => 'DIP']);
        $fid = $framework->get('id');
        $comps = [];
        $comps['CORE1'] = $cgen->create_competency(['competencyframeworkid' => $fid, 'idnumber' => 'CORE1',
            'shortname' => 'Core unit']);
        $comps['CORE1.E1'] = $cgen->create_competency(['competencyframeworkid' => $fid, 'idnumber' => 'CORE1.E1',
            'shortname' => 'Element 1', 'parentid' => $comps['CORE1']->get('id')]);
        foreach (['CORE1.E1.PC1.1', 'CORE1.E1.PC1.2'] as $idnumber) {
            $comps[$idnumber] = $cgen->create_competency(['competencyframeworkid' => $fid, 'idnumber' => $idnumber,
                'shortname' => substr($idnumber, -6), 'parentid' => $comps['CORE1.E1']->get('id')]);
        }
        $comps['ELEC1'] = $cgen->create_competency(['competencyframeworkid' => $fid, 'idnumber' => 'ELEC1',
            'shortname' => 'Elective one']);
        $comps['ELEC2'] = $cgen->create_competency(['competencyframeworkid' => $fid, 'idnumber' => 'ELEC2',
            'shortname' => 'Elective not taken']);

        $student = $gen->create_user();
        $course = $gen->create_course();
        $gen->enrol_user($student->id, $course->id, 'student');
        $assign = $gen->create_module('assign', ['course' => $course->id, 'name' => 'Project']);
        $cgen->create_course_competency(['courseid' => $course->id, 'competencyid' => $comps['CORE1.E1.PC1.1']->get('id')]);
        $cgen->create_course_module_competency(['competencyid' => $comps['CORE1.E1.PC1.1']->get('id'),
            'cmid' => $assign->cmid]);

        $template = $cgen->create_template(['shortname' => 'Diploma core']);
        $cgen->create_template_competency(['templateid' => $template->get('id'), 'competencyid' => $comps['CORE1']->get('id')]);
        $core = $cgen->create_plan(['userid' => $student->id, 'templateid' => $template->get('id'), 'name' => 'Diploma core',
            'status' => plan::STATUS_ACTIVE]);
        $electives = $cgen->create_plan(['userid' => $student->id, 'name' => 'Diploma electives',
            'status' => plan::STATUS_ACTIVE]);
        $cgen->create_plan_competency(['planid' => $electives->get('id'), 'competencyid' => $comps['ELEC1']->get('id')]);

        // Evidence on a performance criterion, beneath the unit that's in the plan.
        $egen->create_evidence(['userid' => $student->id, 'competencyid' => $comps['CORE1.E1.PC1.1']->get('id'),
            'cmid' => $assign->cmid, 'action' => \core_competency\evidence::ACTION_LOG]);
        return [$framework, $student, $comps, $core, $electives, (int) $assign->cmid];
    }

    /**
     * The default is the learner's active plans together: each planned unit and everything beneath it, grouped by plan.
     */
    public function test_default_active_plans(): void {
        $this->resetAfterTest();
        [, $student, , $core] = $this->setup_data();
        $this->setAdminUser();

        $scope = scope::get_default((int) $student->id, summary::get_frameworks((int) $student->id));
        $this->assertEquals(scope::ACTIVE_PLANS, $scope->type);
        $comps = $scope->get_competencies();
        $this->assertEquals(['CORE1', 'CORE1.E1', 'CORE1.E1.PC1.1', 'CORE1.E1.PC1.2', 'ELEC1'], array_column($comps, 'idnumber'));
        $this->assertEquals([0, 1, 2, 2, 0], array_column($comps, 'depth'));
        $this->assertEquals(['Diploma core', '', '', '', 'Diploma electives'], array_column($comps, 'group'));
        $this->assertEquals(['scope' => 'plans'], $scope->get_params());

        $summary = summary::build((int) $student->id, $scope);
        $pc = $summary->competencies[2];
        $this->assertEquals('CORE1.E1.PC1.1', $pc->idnumber);
        $this->assertCount(1, $pc->evidence);
        $this->assertStringContainsString('Diploma core (Active, from template: Diploma core', implode(' ', $scope->describe()));

        // Options: both plans together, each plan, then the framework.
        $names = array_column($scope->get_options((int) $student->id, summary::get_frameworks((int) $student->id)), 'name');
        $this->assertEquals('Active learning plans', $names[0]);
        $this->assertContains('Learning plan: Diploma core (Active)', $names);
        $this->assertContains('Competency framework: Diploma', $names);

        // One plan chosen from the page.
        $one = scope::from_params((int) $student->id, 'plan', (int) $core->get('id'), 0, []);
        $this->assertEquals(
            ['CORE1', 'CORE1.E1', 'CORE1.E1.PC1.1', 'CORE1.E1.PC1.2'],
            array_column($one->get_competencies(), 'idnumber')
        );
        $this->assertEquals(['', '', '', ''], array_column($one->get_competencies(), 'group'));
    }

    /**
     * Someone else's plan, or a plan the viewer can't see, isn't available.
     */
    public function test_other_learners_plan(): void {
        $this->resetAfterTest();
        [, $student, , $core] = $this->setup_data();
        $other = $this->getDataGenerator()->create_user();
        $this->setAdminUser();
        $this->expectException(\moodle_exception::class);
        scope::from_params((int) $other->id, 'plan', (int) $core->get('id'), 0, []);
    }

    /**
     * Without plans: the framework when there's only one, otherwise the viewer chooses.
     */
    public function test_default_without_plans(): void {
        $this->resetAfterTest();
        [$framework] = $this->setup_data();
        $learner = $this->getDataGenerator()->create_user();
        $this->setAdminUser();
        $one = [$framework->get('id') => (object) ['id' => $framework->get('id')]];
        $this->assertEquals(scope::FRAMEWORK, scope::get_default((int) $learner->id, $one)->type);
        $this->assertNull(scope::get_default((int) $learner->id, $one + [999 => (object) ['id' => 999]]));
        $this->assertNull(scope::get_default((int) $learner->id, []));
    }

    /**
     * A completed plan shows the ratings as they were when it was completed, not later changes.
     */
    public function test_completed_plan_frozen_ratings(): void {
        $this->resetAfterTest();
        [, $student, $comps, $core, $electives] = $this->setup_data();
        $this->setAdminUser();
        $unit = (int) $comps['CORE1']->get('id');

        api::grade_competency_in_plan($core, $unit, 2, 'Signed off');
        api::complete_plan($core->get('id'));
        // Rated again later, outside the plan.
        api::grade_competency((int) $student->id, $unit, 1, 'Re-assessed');

        $core = plan::get_record(['id' => $core->get('id')]);
        $summary = summary::build((int) $student->id, scope::for_plan($core));
        $row = $summary->competencies[0];
        $this->assertEquals('CORE1', $row->idnumber);
        // The test scale is A, B, C: B when the plan was completed, A afterwards.
        $this->assertTrue($row->frozen);
        $this->assertEquals('B', $row->currentrating);
        $live = summary::build((int) $student->id, $comps['CORE1']->get('competencyframeworkid'))->competencies[0];
        $this->assertEquals('A', $live->currentrating);
        $this->assertFalse($live->frozen);
        $this->assertStringContainsString('completed', implode(' ', scope::for_plan($core)->describe()));

        // With the core plan completed, the default is the remaining active plan.
        $default = scope::get_default((int) $student->id, []);
        $this->assertEquals(scope::PLAN, $default->type);
        $this->assertEquals($electives->get('id'), $default->plans[0]->get('id'));
    }

    /**
     * The evidence pack covers the plans: file name, README and summary say so; the attempt is included.
     */
    public function test_pack_for_plans(): void {
        $this->resetAfterTest();
        [, $student] = $this->setup_data();
        $this->setAdminUser();

        $scope = scope::get_default((int) $student->id, []);
        $pack = new evidence_pack((int) $student->id, $scope);
        $this->assertStringContainsString('Active learning plans', $pack->get_filename());
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($pack->build()));
        $readme = $zip->getFromName('README.txt');
        $zip->close();
        $this->assertStringContainsString('Learning plans: Diploma core (Active, from template: Diploma core)', $readme);
        $this->assertStringContainsString('Diploma electives (Active)', $readme);
        $summaryhtml = $pack->get_rendered_html()[0];
        $this->assertStringContainsString('<strong>Diploma electives</strong>', $summaryhtml);
        $this->assertStringNotContainsString('ELEC2', $summaryhtml);
    }

    /**
     * Bulk downloads cover each learner's plans by default; learners without one are listed but not included.
     */
    public function test_bulk_plans(): void {
        global $DB;
        $this->resetAfterTest();
        [, $student] = $this->setup_data();
        $noplan = $this->getDataGenerator()->create_user(['firstname' => 'No', 'lastname' => 'Plan']);
        $cohort = $this->getDataGenerator()->create_cohort();
        cohort_add_member($cohort->id, $student->id);
        cohort_add_member($cohort->id, $noplan->id);
        $this->setAdminUser();

        $file = bulk::build(['sourcetype' => 'cohort', 'sourceid' => $cohort->id, 'frameworkid' => 0,
            'mode' => bulk::MODE_SUMMARIES, 'includefeedback' => 1]);
        $path = make_request_directory() . '/bulk.zip';
        $file->copy_content_to($path);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $index = $zip->getFromName('index.csv');
        $readme = $zip->getFromName('README.txt');
        $zip->close();
        $this->assertStringContainsString('"Not included: no learning plan"', $index);
        $this->assertStringContainsString('"Included"', $index);
        $this->assertStringContainsString('Covering: Learning plans', $readme);
        $this->assertStringContainsString('Learning plans', $file->get_filename());
        $this->assertNotEmpty($DB->get_records('competency_plan', ['userid' => $student->id]));
    }
}
