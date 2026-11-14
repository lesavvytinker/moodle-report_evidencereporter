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

namespace report_evidencereporter\reportbuilder\datasource;

use core_competency\api;
use core_competency\evidence as evidence_persistent;
use core_reportbuilder_generator;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use report_evidencereporter\local\feedback_visibility;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');

/**
 * Unit tests for the competency evidence data source.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(evidence::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\report_evidencereporter\reportbuilder\local\entities\evidence::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\report_evidencereporter\local\access::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(feedback_visibility::class)]
final class evidence_test extends core_reportbuilder_testcase {
    /**
     * Static caches hold IDs, which the test database reuses between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        \report_evidencereporter\local\feedback_visibility::reset_cache();
        \report_evidencereporter\local\attempts::reset_cache();
        \report_evidencereporter\local\activity_grade::reset_cache();
    }
    /**
     * Build two courses sharing one competency, with an assignment in each, and a student in both.
     *
     * @return array
     */
    private function setup_data(): array {
        $gen = $this->getDataGenerator();
        /** @var \core_competency_generator $cgen */
        $cgen = $gen->get_plugin_generator('core_competency');

        $framework = $cgen->create_framework(['shortname' => 'FW']);
        $comp = $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'shortname' => 'Comp A']);
        $comp2 = $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'shortname' => 'Comp B']);

        $student = $gen->create_user(['firstname' => 'Sam', 'lastname' => 'Student']);
        $teacher1 = $gen->create_user(['firstname' => 'Tess', 'lastname' => 'One']);
        $teacher2 = $gen->create_user(['firstname' => 'Toby', 'lastname' => 'Two']);

        $courses = [];
        $assigns = [];
        foreach ([1, 2] as $i) {
            $courses[$i] = $gen->create_course(['fullname' => "Course {$i}"]);
            $cgen->create_course_competency(['courseid' => $courses[$i]->id, 'competencyid' => $comp->get('id')]);
            $gen->enrol_user($student->id, $courses[$i]->id, 'student');
            $assigns[$i] = $gen->create_module('assign', ['course' => $courses[$i]->id, 'name' => "Task {$i}",
                'markingworkflow' => $i == 2 ? 1 : 0]);
            // Like core on activity completion: one row per linked competency, with the view page as the URL.
            $viewurl = new \moodle_url('/mod/assign/view.php', ['id' => $assigns[$i]->cmid]);
            $linked = $i == 1 ? [$comp, $comp2] : [$comp];
            if ($i == 1) {
                $cgen->create_course_competency(['courseid' => $courses[1]->id, 'competencyid' => $comp2->get('id')]);
            }
            foreach ($linked as $linkedcomp) {
                api::add_evidence(
                    $student->id,
                    $linkedcomp,
                    \context_module::instance($assigns[$i]->cmid),
                    evidence_persistent::ACTION_LOG,
                    'evidence_coursemodulecompleted',
                    'core_competency',
                    "Task {$i}",
                    false,
                    $viewurl
                );
            }
            $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assigns[$i]->id,
                'itemnumber' => 0, 'courseid' => $courses[$i]->id]);
            $item->update_final_grade(
                $student->id,
                80,
                'test',
                "<p>Feedback {$i}</p>",
                FORMAT_HTML,
                $i == 1 ? $teacher1->id : $teacher2->id
            );
        }
        $gen->enrol_user($teacher1->id, $courses[1]->id, 'editingteacher');
        $gen->enrol_user($teacher2->id, $courses[2]->id, 'editingteacher');

        return [$student, $teacher1, $teacher2, $courses, $assigns, $comp];
    }

    /**
     * Create a report on this source with the given columns only.
     *
     * @param string[] $columns
     * @return int
     */
    private function create_report(array $columns): int {
        /** @var core_reportbuilder_generator $rbgen */
        $rbgen = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgen->create_report(['name' => 'Evidence', 'source' => evidence::class, 'default' => 0]);
        foreach ($columns as $i => $column) {
            $rbgen->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $column,
                'sortenabled' => $i === 0 ? 1 : 0]);
        }
        return $report->get('id');
    }

    /**
     * Default report shows one row per evidence item, with link and feedback.
     */
    public function test_datasource_default(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->setup_data();

        /** @var core_reportbuilder_generator $rbgen */
        $rbgen = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgen->create_report(['name' => 'Evidence', 'source' => evidence::class, 'default' => 1]);

        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(3, $content);

        $row = array_values($content[0]);
        $this->assertStringContainsString('Sam Student', $row[0]);
        $this->assertEquals('Comp A', $row[1]);
        $this->assertStringContainsString('action=grader', $row[6]);
    }

    /**
     * A teacher only sees evidence from their own course; the learner sees their own; admin sees all.
     */
    public function test_row_access(): void {
        $this->resetAfterTest();
        [$student, $teacher1, $teacher2] = $this->setup_data();

        $this->setAdminUser();
        $reportid = $this->create_report(['course:fullname']);

        $this->assertCount(3, $this->get_custom_report_content($reportid));

        $this->setUser($teacher1);
        $content = $this->get_custom_report_content($reportid);
        $this->assertEquals([['Course 1'], ['Course 1']], array_map('array_values', $content));

        $this->setUser($teacher2);
        $content = $this->get_custom_report_content($reportid);
        $this->assertEquals([['Course 2']], array_map('array_values', $content));

        $this->setUser($student);
        $this->assertCount(3, $this->get_custom_report_content($reportid));

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertCount(0, $this->get_custom_report_content($reportid));
    }

    /**
     * Unreleased feedback (marking workflow) is hidden from the learner but labelled for the teacher.
     */
    public function test_feedback_release(): void {
        global $DB;
        $this->resetAfterTest();
        [$student, , $teacher2, , $assigns] = $this->setup_data();
        $DB->insert_record('assign_user_flags', (object) ['assignment' => $assigns[2]->id, 'userid' => $student->id,
            'locked' => 0, 'mailed' => 0, 'extensionduedate' => 0, 'workflowstate' => 'inmarking', 'allocatedmarker' => 0]);

        $this->setAdminUser();
        $reportid = $this->create_report(['course:fullname', 'evidence:feedback', 'evidence:releasestatus']);

        $this->setUser($student);
        feedback_visibility::reset_cache();
        $content = array_map('array_values', $this->get_custom_report_content($reportid));
        $this->assertEquals(['Course 1', '<p>Feedback 1</p>', 'Released'], $content[0]);
        $this->assertEquals(['Course 1', '<p>Feedback 1</p>', 'Released'], $content[1]);
        $this->assertEquals(['Course 2', 'Pending release', 'Pending release'], $content[2]);

        $this->setUser($teacher2);
        feedback_visibility::reset_cache();
        $content = array_map('array_values', $this->get_custom_report_content($reportid));
        $this->assertEquals('<p>Feedback 2</p>', $content[0][1]);
        $this->assertStringContainsString('Not released to student', $content[0][2]);
    }

    /**
     * Only the latest evidence from an activity, per competency, shows the (current) gradebook feedback.
     */
    public function test_feedback_superseded(): void {
        $this->resetAfterTest();
        [$student, , , , $assigns, $comp] = $this->setup_data();
        api::add_evidence(
            $student->id,
            $comp,
            \context_module::instance($assigns[1]->cmid),
            evidence_persistent::ACTION_LOG,
            'evidence_coursemodulecompleted',
            'core_competency',
            'Task 1'
        );

        $this->setAdminUser();
        $reportid = $this->create_report(['course:fullname', 'evidence:feedback']);
        $content = array_map('array_values', $this->get_custom_report_content($reportid));
        $course1 = array_values(array_filter($content, fn($r) => $r[0] === 'Course 1'));

        // Comp A: two rows, the older superseded. Comp B: one row from the same completion, still current.
        $this->assertCount(3, $course1);
        $feedback = array_column($course1, 1);
        sort($feedback);
        $this->assertEquals(['<p>Feedback 1</p>', '<p>Feedback 1</p>', 'Superseded by a later attempt'], $feedback);
    }

    /**
     * Completion evidence has no user recorded, so the assessor falls back to the activity's grader.
     */
    public function test_assessor_falls_back_to_grader(): void {
        global $DB;
        $this->resetAfterTest();
        [$student, , $teacher2, , $assigns, $comp] = $this->setup_data();
        $DB->insert_record('assign_user_flags', (object) ['assignment' => $assigns[2]->id, 'userid' => $student->id,
            'locked' => 0, 'mailed' => 0, 'extensionduedate' => 0, 'workflowstate' => 'inmarking', 'allocatedmarker' => 0]);

        $this->setAdminUser();
        $reportid = $this->create_report(['course:fullname', 'evidence:assessor']);
        $content = array_map('array_values', $this->get_custom_report_content($reportid));
        $this->assertEquals([['Course 1', 'Tess One'], ['Course 1', 'Tess One'], ['Course 2', 'Toby Two']], $content);

        // The learner can't see who marked an unreleased grade.
        $this->setUser($student);
        feedback_visibility::reset_cache();
        $content = array_map('array_values', $this->get_custom_report_content($reportid));
        $this->assertEquals(['Course 2', ''], $content[2]);

        // A manual rating records its own assessor, which takes priority.
        $this->setUser($teacher2);
        api::add_evidence(
            $student->id,
            $comp,
            \context_module::instance($assigns[2]->cmid),
            evidence_persistent::ACTION_OVERRIDE,
            'evidence_manualoverride',
            'core_competency',
            null,
            false,
            null,
            2,
            $this->getDataGenerator()->create_user(['firstname' => 'Rae', 'lastname' => 'Rater'])->id
        );
        $this->setAdminUser();
        $content = array_map('array_values', $this->get_custom_report_content($reportid));
        $this->assertContains(['Course 2', 'Rae Rater'], $content);
    }

    /**
     * Current rating shows the learner's overall rating on every row, and outcome rows can be hidden with a condition.
     */
    public function test_current_rating_and_hiding_outcome_rows(): void {
        $this->resetAfterTest();
        [$student, , , $courses, , $comp] = $this->setup_data();

        // Course completion marks Comp A complete (core sets the default rating).
        api::add_evidence(
            $student->id,
            $comp,
            \context_course::instance($courses[2]->id),
            evidence_persistent::ACTION_COMPLETE,
            'evidence_coursecompleted',
            'core_competency',
            'Course 2'
        );

        $this->setAdminUser();
        /** @var core_reportbuilder_generator $rbgen */
        $rbgen = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $reportid = $this->create_report(['competency:name', 'evidence:action', 'evidence:rating',
            'evidence:currentrating']);

        $content = array_map('array_values', $this->get_custom_report_content($reportid));
        $compa = array_values(array_filter($content, fn($r) => $r[0] === 'Comp A'));
        $this->assertCount(3, $compa);
        // Every Comp A row shows the current rating; only the completion row has a rating given.
        $this->assertCount(1, array_filter($compa, fn($r) => $r[2] !== ''));
        $this->assertCount(1, array_unique(array_column($compa, 3)));
        $this->assertNotEquals('', $compa[0][3]);

        // Condition: evidence type is not "Competency completed" leaves only the activity rows.
        $rbgen->create_condition(['reportid' => $reportid, 'uniqueidentifier' => 'evidence:action']);
        \core_reportbuilder\manager::get_report_from_id($reportid)->set_condition_values([
            'evidence:action_operator' => \core_reportbuilder\local\filters\select::NOT_EQUAL_TO,
            'evidence:action_value' => evidence_persistent::ACTION_COMPLETE,
        ]);
        $content = array_map('array_values', $this->get_custom_report_content($reportid));
        $this->assertCount(3, $content);
        $this->assertNotContains('Competency completed', array_column($content, 1));
    }

    /**
     * Activity grade shows the gradebook grade, with the same release rules as feedback.
     */
    public function test_activity_grade(): void {
        global $DB;
        $this->resetAfterTest();
        [$student, , $teacher2, , $assigns] = $this->setup_data();
        $DB->set_field('grade_items', 'gradepass', 50, ['itemmodule' => 'assign', 'iteminstance' => $assigns[1]->id]);
        $DB->insert_record('assign_user_flags', (object) ['assignment' => $assigns[2]->id, 'userid' => $student->id,
            'locked' => 0, 'mailed' => 0, 'extensionduedate' => 0, 'workflowstate' => 'inmarking', 'allocatedmarker' => 0]);

        $this->setAdminUser();
        $reportid = $this->create_report(['course:fullname', 'evidence:activitygrade']);
        \report_evidencereporter\local\activity_grade::reset_cache();
        $content = array_map('array_values', $this->get_custom_report_content($reportid));
        $this->assertEquals(['Course 1', '80.00 / 100.00 (Pass)'], $content[0]);
        $this->assertEquals(['Course 2', '80.00 / 100.00'], $content[2]);

        $this->setUser($student);
        feedback_visibility::reset_cache();
        $content = array_map('array_values', $this->get_custom_report_content($reportid));
        $this->assertEquals(['Course 1', '80.00 / 100.00 (Pass)'], $content[0]);
        $this->assertEquals(['Course 2', 'Pending release'], $content[2]);
    }

    /**
     * Database evidence links to the single view of the learner's latest entry.
     */
    public function test_database_link_is_latest_entry(): void {
        $this->resetAfterTest();
        [$student, , , $courses, , $comp] = $this->setup_data();
        $gen = $this->getDataGenerator();
        /** @var \mod_data_generator $datagen */
        $datagen = $gen->get_plugin_generator('mod_data');
        $data = $gen->create_module('data', ['course' => $courses[1]->id]);
        $field = $datagen->create_field((object) ['name' => 'Notes', 'type' => 'text'], $data);
        $this->setUser($student);
        $datagen->create_entry($data, [$field->field->id => 'First entry']);
        $latest = $datagen->create_entry($data, [$field->field->id => 'Second entry']);
        $this->setAdminUser();
        api::add_evidence(
            $student->id,
            $comp,
            \context_module::instance($data->cmid),
            evidence_persistent::ACTION_LOG,
            'evidence_coursemodulecompleted',
            'core_competency',
            'Log',
            false,
            new \moodle_url('/mod/data/view.php', ['id' => $data->cmid])
        );

        $reportid = $this->create_report(['evidence:activitytype', 'evidence:evidenceurl']);
        $rows = array_filter(
            array_map('array_values', $this->get_custom_report_content($reportid)),
            fn($r) => $r[0] === 'Database'
        );
        $this->assertCount(1, $rows);
        $url = reset($rows)[1];
        $this->assertStringContainsString('/mod/data/view.php', $url);
        $this->assertStringContainsString('rid=' . $latest, $url);
        $this->assertStringContainsString('mode=single', $url);
    }

    /**
     * Completion evidence stores the activity view URL; the link should still go to the learner's submission.
     */
    public function test_evidence_link_prefers_submission(): void {
        $this->resetAfterTest();
        [$student] = $this->setup_data();

        $this->setAdminUser();
        $reportid = $this->create_report(['course:fullname', 'evidence:evidenceurl']);
        $content = array_map('array_values', $this->get_custom_report_content($reportid));
        $this->assertCount(3, $content);
        foreach ($content as $row) {
            $this->assertStringContainsString('action=grader', $row[1]);
            $this->assertStringContainsString('userid=' . $student->id, $row[1]);
        }
    }

    /**
     * Stress test every column, filter and condition of the data source.
     */
    public function test_stress_datasource(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->setup_data();

        $this->datasource_stress_test_columns(evidence::class);
        $this->datasource_stress_test_columns_aggregation(evidence::class);
        $this->datasource_stress_test_conditions(evidence::class, 'competency:name');
    }
}
