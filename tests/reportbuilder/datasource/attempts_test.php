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

use core_reportbuilder_generator;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use report_evidencereporter\local\{activity_grade, attempts as attempts_helper, feedback_visibility};

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/report/evidencereporter/tests/generator/attempts_trait.php');

/**
 * Tests for the Assessment attempts data source.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\reportbuilder\datasource\attempts
 * @covers     \report_evidencereporter\reportbuilder\local\entities\attempt
 * @covers     \report_evidencereporter\local\attempts
 */
#[\PHPUnit\Framework\Attributes\CoversClass(attempts::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\report_evidencereporter\reportbuilder\local\entities\attempt::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\report_evidencereporter\local\attempts::class)]
final class attempts_test extends core_reportbuilder_testcase {
    use \report_evidencereporter_attempts_trait;

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
     * Learner with an assignment (NYC, reopened, then competent) and a quiz (failed then passed) linked to a competency.
     *
     * @return array
     */
    private function setup_data(): array {
        global $DB;
        $gen = $this->getDataGenerator();
        /** @var \core_competency_generator $cgen */
        $cgen = $gen->get_plugin_generator('core_competency');
        $framework = $cgen->create_framework();
        $comp = $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'shortname' => 'Comp A']);

        $course = $gen->create_course(['fullname' => 'Course 1']);
        $other = $gen->create_course(['fullname' => 'Course 2']);
        $student = $gen->create_user(['firstname' => 'Sam', 'lastname' => 'Student']);
        $marker = $gen->create_user(['firstname' => 'Mel', 'lastname' => 'Marker']);
        $otherteacher = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $gen->enrol_user($marker->id, $course->id, 'editingteacher');
        $gen->enrol_user($otherteacher->id, $other->id, 'editingteacher');
        $cgen->create_course_competency(['courseid' => $course->id, 'competencyid' => $comp->get('id')]);

        $assign = $gen->create_module('assign', ['course' => $course->id, 'name' => 'Task',
            'assignsubmission_onlinetext_enabled' => 1, 'assignfeedback_comments_enabled' => 1,
            'attemptreopenmethod' => 'manual', 'maxattempts' => -1, 'grade' => 100]);
        $quiz = $this->create_quiz_with_question((int) $course->id, ['name' => 'Quiz', 'attempts' => 0]);
        foreach ([$assign->cmid, $quiz->cmid] as $cmid) {
            $cgen->create_course_module_competency(['cmid' => $cmid, 'competencyid' => $comp->get('id')]);
        }
        $DB->set_field('grade_items', 'gradepass', 50, ['itemmodule' => 'assign', 'iteminstance' => $assign->id]);
        $DB->set_field('grade_items', 'gradepass', 8, ['itemmodule' => 'quiz', 'iteminstance' => $quiz->id]);

        $this->grade_assign_attempt(
            $assign,
            (int) $student->id,
            (int) $marker->id,
            40,
            '<p>Controls missing for two hazards.</p>',
            true
        );
        $this->grade_assign_attempt($assign, (int) $student->id, (int) $marker->id, 80, '<p>All criteria met.</p>');
        $this->attempt_quiz($quiz, (int) $student->id, false);
        $this->attempt_quiz($quiz, (int) $student->id, true);

        return [$student, $marker, $otherteacher, $assign, $quiz];
    }

    /**
     * Create a report on this source with the given columns.
     *
     * @param string[] $columns
     * @return int
     */
    private function create_report(array $columns): int {
        /** @var core_reportbuilder_generator $rbgen */
        $rbgen = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgen->create_report(['name' => 'Attempts', 'source' => attempts::class, 'default' => 0]);
        foreach ($columns as $column) {
            $rbgen->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $column]);
        }
        return $report->get('id');
    }

    /**
     * Reset static caches between users.
     */
    private function reset_caches(): void {
        feedback_visibility::reset_cache();
        attempts_helper::reset_cache();
        activity_grade::reset_cache();
    }

    /**
     * Every attempt shows with its own grade, outcome, marker and feedback, failed ones included.
     */
    public function test_attempt_rows(): void {
        $this->resetAfterTest();
        $this->setup_data();
        $this->setAdminUser();

        $reportid = $this->create_report(['attempt:activitytype', 'attempt:attempt', 'attempt:grade', 'attempt:outcome',
            'attempt:marker', 'attempt:feedback', 'attempt:islatest']);
        $content = array_map('array_values', $this->get_custom_report_content($reportid));
        usort($content, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        // Quiz feedback is a link to the attempt review with all questions on one page.
        foreach ([2, 3] as $i) {
            $this->assertStringContainsString('review.php?attempt=', $content[$i][5]);
            $this->assertStringContainsString('showall=1', $content[$i][5]);
            $this->assertStringContainsString('View answers and feedback', $content[$i][5]);
            $content[$i][5] = 'link';
        }
        $this->assertEquals([
            ['Assignment', '1', '40.00 / 100.00 (Fail)', 'Fail', 'Mel Marker', '<p>Controls missing for two hazards.</p>', 'No'],
            ['Assignment', '2', '80.00 / 100.00 (Pass)', 'Pass', 'Mel Marker', '<p>All criteria met.</p>', 'Yes'],
            ['Quiz', '1', '0.00 / 10.00 (Fail)', 'Fail', '', 'link', 'No'],
            ['Quiz', '2', '10.00 / 10.00 (Pass)', 'Pass', '', 'link', 'Yes'],
        ], $content);
    }

    /**
     * Outcome and latest-attempt filters work as conditions.
     */
    public function test_conditions(): void {
        $this->resetAfterTest();
        $this->setup_data();
        $this->setAdminUser();
        /** @var core_reportbuilder_generator $rbgen */
        $rbgen = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $reportid = $this->create_report(['attempt:activitytype', 'attempt:attempt']);
        $rbgen->create_condition(['reportid' => $reportid, 'uniqueidentifier' => 'attempt:outcome']);
        \core_reportbuilder\manager::get_report_from_id($reportid)->set_condition_values([
            'attempt:outcome_operator' => \core_reportbuilder\local\filters\select::EQUAL_TO,
            'attempt:outcome_value' => 'fail',
        ]);
        $this->assertCount(2, $this->get_custom_report_content($reportid));

        $reportid = $this->create_report(['attempt:activitytype', 'attempt:attempt']);
        $rbgen->create_condition(['reportid' => $reportid, 'uniqueidentifier' => 'attempt:islatest']);
        \core_reportbuilder\manager::get_report_from_id($reportid)->set_condition_values([
            'attempt:islatest_operator' => \core_reportbuilder\local\filters\boolean_select::CHECKED,
        ]);
        $content = array_map('array_values', $this->get_custom_report_content($reportid));
        $this->assertEqualsCanonicalizing([['Assignment', '2'], ['Quiz', '2']], $content);
    }

    /**
     * Row access: teacher of another course sees nothing; the learner sees their own; unreleased work is pending.
     */
    public function test_access_and_release(): void {
        global $DB;
        $this->resetAfterTest();
        [$student, $marker, $otherteacher, $assign] = $this->setup_data();
        $this->setAdminUser();
        $reportid = $this->create_report(['attempt:activitytype', 'attempt:attempt', 'attempt:grade', 'attempt:feedback']);

        $this->setUser($otherteacher);
        $this->reset_caches();
        $this->assertCount(0, $this->get_custom_report_content($reportid));

        // Marking workflow on, latest attempt still in marking: the learner sees attempt 1 but not attempt 2's result.
        $DB->set_field('assign', 'markingworkflow', 1, ['id' => $assign->id]);
        $DB->set_field('assign_user_flags', 'workflowstate', 'inmarking', ['assignment' => $assign->id]);
        if (!$DB->record_exists('assign_user_flags', ['assignment' => $assign->id, 'userid' => $student->id])) {
            $DB->insert_record('assign_user_flags', (object) ['assignment' => $assign->id, 'userid' => $student->id,
                'locked' => 0, 'mailed' => 0, 'extensionduedate' => 0, 'workflowstate' => 'inmarking', 'allocatedmarker' => 0]);
        }
        $this->setUser($student);
        $this->reset_caches();
        $content = array_map('array_values', $this->get_custom_report_content($reportid));
        $assignrows = array_values(array_filter($content, fn($r) => $r[0] === 'Assignment'));
        usort($assignrows, fn($a, $b) => $a[1] <=> $b[1]);
        $this->assertEquals('40.00 / 100.00 (Fail)', $assignrows[0][2]);
        $this->assertEquals('Pending release', $assignrows[1][2]);
        $this->assertEquals('Pending release', $assignrows[1][3]);

        // The marker still sees it.
        $this->setUser($marker);
        $this->reset_caches();
        $content = array_map('array_values', $this->get_custom_report_content($reportid));
        $this->assertContains(['Assignment', '2', '80.00 / 100.00 (Pass)', '<p>All criteria met.</p>'], $content);
    }

    /**
     * The evidence report's Attempt history column lists every attempt at the activity, failed ones included.
     */
    public function test_attempt_history_column(): void {
        $this->resetAfterTest();
        [$student, , , $assign] = $this->setup_data();
        /** @var \report_evidencereporter_generator $egen */
        $egen = $this->getDataGenerator()->get_plugin_generator('report_evidencereporter');
        $compid = (int) \core_competency\course_module_competency::get_record(['cmid' => $assign->cmid])->get('competencyid');
        $egen->create_evidence(['userid' => $student->id, 'competencyid' => $compid, 'cmid' => $assign->cmid]);

        $this->setAdminUser();
        /** @var core_reportbuilder_generator $rbgen */
        $rbgen = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgen->create_report(['name' => 'Evidence', 'source' => evidence::class, 'default' => 0]);
        $rbgen->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'evidence:attempthistory']);
        $this->reset_caches();
        [$row] = array_map('array_values', $this->get_custom_report_content($report->get('id')));

        $this->assertStringContainsString('Attempt 1 · 40.00 / 100.00 (Fail) · Mel Marker', $row[0]);
        $this->assertStringContainsString('Controls missing for two hazards.', $row[0]);
        $this->assertStringContainsString('Attempt 2 · 80.00 / 100.00 (Pass) · Mel Marker', $row[0]);
    }

    /**
     * The printable summary lists attempts per competency, even where no competency evidence was ever logged.
     */
    public function test_summary_attempts(): void {
        $this->resetAfterTest();
        [$student] = $this->setup_data();
        $this->setAdminUser();
        $this->reset_caches();

        $frameworkid = (int) \core_competency\competency_framework::get_record([])->get('id');
        $summary = \report_evidencereporter\local\summary::build((int) $student->id, $frameworkid);
        [$comp] = $summary->competencies;
        $this->assertCount(0, $comp->evidence);
        $this->assertCount(4, $comp->attempts);
        $grades = array_column($comp->attempts, 'grade');
        $this->assertContains('40.00 / 100.00 (Fail)', $grades);
        $this->assertContains('0.00 / 10.00 (Fail)', $grades);
    }

    /**
     * Database: every rated entry is an assessment, whatever the rating, with all its comments as feedback.
     * Unrated entries are left out, and the evidence report links to the latest rated entry.
     */
    public function test_database_entries(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/data/lib.php');
        $this->resetAfterTest();
        [$student, $marker] = $this->setup_data();
        $gen = $this->getDataGenerator();
        $course = $DB->get_record('course', ['fullname' => 'Course 1']);
        $compid = (int) $DB->get_field('competency', 'id', ['shortname' => 'Comp A']);

        $scale = $gen->create_scale(['scale' => 'Not satisfactory,Satisfactory']);
        $data = $gen->create_module('data', ['course' => $course->id, 'name' => 'Pre-start log', 'assessed' => 3,
            'scale' => -$scale->id]);
        $gen->get_plugin_generator('core_competency')->create_course_module_competency(['cmid' => $data->cmid,
            'competencyid' => $compid]);
        $DB->set_field('grade_items', 'gradepass', 2, ['itemmodule' => 'data', 'iteminstance' => $data->id]);
        /** @var \mod_data_generator $datagen */
        $datagen = $gen->get_plugin_generator('mod_data');
        $field = $datagen->create_field((object) ['name' => 'Checks', 'type' => 'text'], $data);
        $this->setUser($student);
        $entry1 = $datagen->create_entry($data, [$field->field->id => 'Tyres checked']);
        $entry2 = $datagen->create_entry($data, [$field->field->id => 'Tyres, brakes, lights checked']);
        $entry3 = $datagen->create_entry($data, [$field->field->id => 'Not yet looked at']);
        $this->setAdminUser();

        $context = \context_module::instance($data->cmid);
        $other = $gen->create_user(['firstname' => 'Ollie', 'lastname' => 'Observer']);
        $now = time();
        foreach ([[$entry1, 1, $now - 300], [$entry2, 2, $now - 100]] as [$itemid, $rating, $time]) {
            $DB->insert_record('rating', (object) ['contextid' => $context->id, 'component' => 'mod_data', 'ratingarea' => 'entry',
                'itemid' => $itemid, 'scaleid' => -$scale->id, 'rating' => $rating, 'userid' => $marker->id,
                'timecreated' => $time, 'timemodified' => $time]);
        }
        foreach (
            [[$entry1, $marker, 'Brakes and lights missing.', $now - 290], [$entry2, $marker, 'All checks done.', $now - 90],
                [$entry2, $other, 'Seen on site, agreed.', $now - 80]] as [$itemid, $author, $text, $time]
        ) {
            $DB->insert_record('comments', (object) ['contextid' => $context->id, 'component' => 'mod_data',
                'commentarea' => 'database_entry', 'itemid' => $itemid, 'content' => $text, 'format' => FORMAT_PLAIN,
                'userid' => $author->id, 'timecreated' => $time]);
        }
        $datarecord = $DB->get_record('data', ['id' => $data->id]);
        $datarecord->cmidnumber = '';
        data_update_grades($datarecord, $student->id);

        $reportid = $this->create_report(['attempt:activitytype', 'attempt:attempt', 'attempt:grade', 'attempt:outcome',
            'attempt:marker', 'attempt:feedback', 'attempt:url', 'attempt:islatest']);
        $rows = array_values(array_filter(
            array_map('array_values', $this->get_custom_report_content($reportid)),
            fn($r) => $r[0] === 'Database'
        ));
        usort($rows, fn($a, $b) => $a[1] <=> $b[1]);

        $this->assertCount(2, $rows, 'Unrated entry 3 is left out');
        [$first, $second] = $rows;
        $this->assertEquals(['1', 'Not satisfactory (Fail)', 'Fail', 'Mel Marker'], array_slice($first, 1, 4));
        $this->assertStringContainsString('Brakes and lights missing.', $first[5]);
        $this->assertStringContainsString('Mel Marker', $first[5]);
        $this->assertStringContainsString("rid={$entry1}", $first[6]);
        $this->assertEquals('No', $first[7]);
        $this->assertEquals(['2', 'Satisfactory (Pass)', 'Pass', 'Mel Marker'], array_slice($second, 1, 4));
        $this->assertStringContainsString('All checks done.', $second[5]);
        $this->assertStringContainsString('Ollie Observer', $second[5]);
        $this->assertLessThan(strpos($second[5], 'Ollie Observer'), strpos($second[5], 'All checks done.'), 'Oldest first');
        $this->assertStringContainsString('mode=single', $second[6]);
        $this->assertEquals('Yes', $second[7]);

        // The evidence report links database evidence to the latest rated entry (2), not the newer unrated one (3),
        // and shows that entry's comments as the feedback.
        $gen->get_plugin_generator('report_evidencereporter')->create_evidence(['userid' => $student->id,
            'competencyid' => $compid, 'cmid' => $data->cmid]);
        /** @var core_reportbuilder_generator $rbgen */
        $rbgen = $gen->get_plugin_generator('core_reportbuilder');
        $evidencereport = $rbgen->create_report(['name' => 'Evidence', 'source' => evidence::class, 'default' => 0]);
        foreach (['evidence:activitytype', 'evidence:evidenceurl', 'evidence:feedback', 'evidence:attempthistory'] as $col) {
            $rbgen->create_column(['reportid' => $evidencereport->get('id'), 'uniqueidentifier' => $col]);
        }
        $this->reset_caches();
        [$evidence] = array_values(array_filter(array_map(
            'array_values',
            $this->get_custom_report_content($evidencereport->get('id'))
        ), fn($r) => $r[0] === 'Database'));
        $this->assertStringContainsString("rid={$entry2}", $evidence[1]);
        $this->assertStringNotContainsString("rid={$entry3}", $evidence[1]);
        $this->assertStringContainsString('Seen on site, agreed.', $evidence[2]);
        $this->assertStringContainsString('Entry 1 · Not satisfactory (Fail)', $evidence[3]);
        $this->assertStringContainsString('Entry 2 · Satisfactory (Pass)', $evidence[3]);

        // Completion logged a second time (e.g. Complete, then Complete (pass) once rated): the earlier row says it's
        // superseded rather than repeating the comments.
        $gen->get_plugin_generator('report_evidencereporter')->create_evidence(['userid' => $student->id,
            'competencyid' => $compid, 'cmid' => $data->cmid]);
        $this->reset_caches();
        $rows = array_values(array_filter(array_map(
            'array_values',
            $this->get_custom_report_content($evidencereport->get('id'))
        ), fn($r) => $r[0] === 'Database'));
        $this->assertCount(2, $rows);
        $feedback = array_column($rows, 2);
        $this->assertCount(1, array_filter($feedback, fn($f) => str_contains($f, 'Seen on site, agreed.')));
        $this->assertContains('Superseded by later evidence from this activity', $feedback);

        // The evidence pack has a record for each rated entry.
        $frameworkid = (int) $DB->get_field('competency', 'competencyframeworkid', ['id' => $compid]);
        $pack = new \report_evidencereporter\local\evidence_pack((int) $student->id, $frameworkid);
        $pack->build();
        $paths = $pack->get_paths();
        $this->assertContains('02 Assessments/' . $course->shortname . ' - Pre-start log/Entry 1/Attempt record.pdf', $paths);
        $this->assertContains('02 Assessments/' . $course->shortname . ' - Pre-start log/Entry 2/Attempt record.pdf', $paths);
        $this->assertNotContains('02 Assessments/' . $course->shortname . ' - Pre-start log/Entry 3/Attempt record.pdf', $paths);
    }

    /**
     * Stress test every column, filter and condition of the data source.
     */
    public function test_stress_datasource(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->setup_data();

        $this->datasource_stress_test_columns(attempts::class);
        $this->datasource_stress_test_columns_aggregation(attempts::class);
        $this->datasource_stress_test_conditions(attempts::class, 'attempt:attempt');
    }
}
