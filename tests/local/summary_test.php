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

use core_competency\evidence;

#[\PHPUnit\Framework\Attributes\CoversClass(summary::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\report_evidencereporter\output\summary_page::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\report_evidencereporter\event\summary_viewed::class)]
/**
 * Tests for the printable evidence summary data.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\summary
 * @covers     \report_evidencereporter\output\summary_page
 * @covers     \report_evidencereporter\event\summary_viewed
 */
final class summary_test extends \advanced_testcase {
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
     * Framework with a parent and two children; evidence in two courses.
     *
     * @return array
     */
    private function setup_data(): array {
        $gen = $this->getDataGenerator();
        /** @var \core_competency_generator $cgen */
        $cgen = $gen->get_plugin_generator('core_competency');
        /** @var \report_evidencereporter_generator $egen */
        $egen = $gen->get_plugin_generator('report_evidencereporter');

        $framework = $cgen->create_framework(['shortname' => 'FW']);
        $parent = $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'shortname' => 'Element 1',
            'idnumber' => 'E1']);
        $child1 = $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'shortname' => 'PC 1.1',
            'idnumber' => 'E1.1', 'parentid' => $parent->get('id')]);
        $child2 = $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'shortname' => 'PC 1.2',
            'idnumber' => 'E1.2', 'parentid' => $parent->get('id')]);

        $student = $gen->create_user();
        $teacher1 = $gen->create_user();
        $c1 = $gen->create_course();
        $c2 = $gen->create_course();
        foreach ([$c1, $c2] as $course) {
            $gen->enrol_user($student->id, $course->id, 'student');
        }
        $gen->enrol_user($teacher1->id, $c1->id, 'editingteacher');
        $a1 = $gen->create_module('assign', ['course' => $c1->id]);
        $a2 = $gen->create_module('assign', ['course' => $c2->id]);

        $egen->create_evidence(['userid' => $student->id, 'competencyid' => $child1->get('id'), 'cmid' => $a1->cmid]);
        $egen->create_evidence(['userid' => $student->id, 'competencyid' => $child1->get('id'), 'cmid' => $a1->cmid,
            'action' => evidence::ACTION_OVERRIDE, 'grade' => 2, 'actionuserid' => $teacher1->id, 'note' => 'Observed']);
        $egen->create_evidence(['userid' => $student->id, 'competencyid' => $child2->get('id'), 'cmid' => $a2->cmid]);

        return [$framework, $student, $teacher1, [$parent, $child1, $child2]];
    }

    /**
     * Admin sees every competency in framework order, with gaps marked.
     */
    public function test_build_admin(): void {
        $this->resetAfterTest();
        [$framework, $student, $teacher1] = $this->setup_data();
        $this->setAdminUser();

        $summary = summary::build((int) $student->id, $framework->get('id'));
        $this->assertFalse($summary->restricted);
        $this->assertEquals(['E1', 'E1.1', 'E1.2'], array_column($summary->competencies, 'idnumber'));
        $this->assertEquals([0, 1, 1], array_column($summary->competencies, 'depth'));
        [$parent, $child1, $child2] = $summary->competencies;

        $this->assertCount(0, $parent->evidence);
        $this->assertCount(2, $child1->evidence);
        $this->assertCount(1, $child2->evidence);

        // The rating is the judgement, with its assessor and note.
        $this->assertEquals(fullname($teacher1), $child1->judgementby);
        $this->assertEquals('Observed', $child1->evidence[1]->note);
        $this->assertStringContainsString('action=grader', $child1->evidence[0]->url);
    }

    /**
     * A teacher in course 1 only sees course 1 evidence and gets the restricted notice; others see nothing.
     */
    public function test_build_access(): void {
        $this->resetAfterTest();
        [$framework, $student, $teacher1] = $this->setup_data();

        $this->setUser($teacher1);
        $summary = summary::build((int) $student->id, $framework->get('id'));
        $this->assertTrue($summary->restricted);
        $this->assertCount(2, $summary->competencies[1]->evidence);
        $this->assertCount(0, $summary->competencies[2]->evidence);

        // The learner sees all their own evidence, without the restricted notice.
        $this->setUser($student);
        $summary = summary::build((int) $student->id, $framework->get('id'));
        $this->assertFalse($summary->restricted);
        $this->assertCount(1, $summary->competencies[2]->evidence);

        // Someone unrelated has no visible evidence.
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(summary::has_visible_evidence((int) $student->id));
        $this->assertEmpty(summary::get_frameworks((int) $student->id));
    }

    /**
     * Template data renders and the viewed event is valid.
     */
    public function test_template_and_event(): void {
        global $PAGE, $DB;
        $this->resetAfterTest();
        [$framework, $student] = $this->setup_data();
        $this->setAdminUser();

        $page = new \report_evidencereporter\output\summary_page(
            $DB->get_record('user', ['id' => $student->id]),
            $framework->to_record(),
            summary::build((int) $student->id, $framework->get('id'))
        );
        $output = $PAGE->get_renderer('core');
        $html = $output->render_from_template('report_evidencereporter/summary', $page->export_for_template($output));
        $this->assertStringContainsString('E1.1', $html);
        $this->assertStringContainsString('No evidence recorded', $html);

        $sink = $this->redirectEvents();
        \report_evidencereporter\event\summary_viewed::create([
            'context' => \context_user::instance($student->id),
            'relateduserid' => $student->id,
            'other' => ['frameworkid' => $framework->get('id')],
        ])->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\report_evidencereporter\event\summary_viewed::class, $events[0]);
        $this->assertStringContainsString('print.php', $events[0]->get_url()->out(false));
    }

    /**
     * The page shows username and reference; redaction removes names (learner and assessor) and the pack link carries it.
     */
    public function test_page_identity_and_redaction(): void {
        global $PAGE, $DB;
        $this->resetAfterTest();
        [$framework, $student, $teacher] = $this->setup_data();
        $this->setAdminUser();
        $learner = $DB->get_record('user', ['id' => $student->id]);
        $output = $PAGE->get_renderer('core');
        $render = function (identity $identity) use ($learner, $framework, $output): string {
            $page = new \report_evidencereporter\output\summary_page(
                $learner,
                $framework->to_record(),
                summary::build((int) $learner->id, $framework->get('id'), $identity),
                $identity
            );
            return $output->render_from_template('report_evidencereporter/summary', $page->export_for_template($output));
        };

        $html = $render(new identity($learner));
        $this->assertStringContainsString('class="er-link" target="_blank" rel="noopener"', $html);
        $this->assertStringContainsString(fullname($learner), $html);
        $this->assertStringContainsString($learner->username, $html);
        $this->assertStringContainsString(fullname($teacher), $html);
        $this->assertStringContainsString((new identity($learner))->reference(), $html);

        $identity = new identity($learner, true, true, true);
        $html = $render($identity);
        foreach ([fullname($learner), $learner->username, fullname($teacher), 'Admin User'] as $term) {
            $this->assertStringNotContainsString($term, $html);
        }
        $this->assertStringContainsString($identity->reference(), $html);
        $this->assertStringContainsString('Redacted: learner name', $html);
        $this->assertStringContainsString('redactname=1&amp;redactusername=1&amp;redactstaff=1', $html);
        $this->assertStringContainsString('name="redactstaff" value="1" checked', $html);
    }
}
