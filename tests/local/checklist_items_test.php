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

use core_reportbuilder\tests\core_reportbuilder_testcase;

/**
 * Checklist activities: item marks and comments as feedback, the link to the learner's checklist, and the pack record.
 *
 * Skipped on sites without the Checklist activity (mod_checklist).
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\checklist_items
 */
#[\PHPUnit\Framework\Attributes\CoversClass(checklist_items::class)]
final class checklist_items_test extends core_reportbuilder_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        checklist_items::reset_cache();
        feedback_visibility::reset_cache();
        if (!checklist_items::is_installed()) {
            $this->markTestSkipped('The Checklist activity (mod_checklist) is not installed.');
        }
    }

    /**
     * A teacher-marked checklist linked to a competency, as in a skills observation.
     *
     * @return array framework, student, teacher, checklist cmid
     */
    private function setup_data(): array {
        global $DB;
        $gen = $this->getDataGenerator();
        /** @var \core_competency_generator $cgen */
        $cgen = $gen->get_plugin_generator('core_competency');
        /** @var \report_evidencereporter_generator $egen */
        $egen = $gen->get_plugin_generator('report_evidencereporter');

        $framework = $cgen->create_framework(['shortname' => 'Performing arts']);
        $comp = $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'shortname' => 'Perform',
            'idnumber' => 'PA1']);
        $course = $gen->create_course(['shortname' => 'PA101']);
        $student = $gen->create_user(['firstname' => 'Isla', 'lastname' => 'Robinson']);
        $teacher = $gen->create_user(['firstname' => 'Daniel', 'lastname' => 'Maloney']);
        $gen->enrol_user($student->id, $course->id, 'student');
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $checklist = $gen->create_module('checklist', ['course' => $course->id, 'name' => 'Talent show',
            'teacheredit' => 1]);
        $cgen->create_course_module_competency(['competencyid' => $comp->get('id'), 'cmid' => $checklist->cmid]);

        $marks = ['Dancing' => [1, 'Yes, quite the dancer!'], 'Singing' => [1, 'Oh yes, an amazing singer.'],
            'Acting' => [2, 'Not the best actor, keep trying.'], 'Juggling' => [0, '']];
        $position = 1;
        foreach ($marks as $text => [$mark, $comment]) {
            $itemid = $DB->insert_record('checklist_item', ['checklist' => $checklist->id, 'userid' => 0,
                'displaytext' => $text, 'position' => $position++]);
            if ($mark) {
                $DB->insert_record('checklist_check', ['item' => $itemid, 'userid' => $student->id, 'usertimestamp' => 0,
                    'teachermark' => $mark, 'teachertimestamp' => time(), 'teacherid' => $teacher->id]);
            }
            if ($comment !== '') {
                $DB->insert_record('checklist_comment', ['itemid' => $itemid, 'userid' => $student->id,
                    'commentby' => $teacher->id, 'text' => $comment]);
            }
        }
        // An item hidden from students is left out.
        $DB->insert_record('checklist_item', ['checklist' => $checklist->id, 'userid' => 0, 'displaytext' => 'Secret',
            'position' => $position, 'hidden' => 1]);

        $egen->create_evidence(['userid' => $student->id, 'competencyid' => $comp->get('id'), 'cmid' => $checklist->cmid,
            'action' => \core_competency\evidence::ACTION_COMPLETE]);
        return [$framework, $student, $teacher, (int) $checklist->cmid];
    }

    /**
     * Items in order with their marks and comments; hidden items left out.
     */
    public function test_get_for_user(): void {
        $this->resetAfterTest();
        [, $student, , $cmid] = $this->setup_data();

        $checklist = checklist_items::get_for_user($cmid, (int) $student->id, true);
        $this->assertEquals('Talent show', $checklist->name);
        $this->assertEquals(['Dancing', 'Singing', 'Acting', 'Juggling'], array_column($checklist->items, 'text'));
        $marks = array_map(fn($item) => checklist_items::mark($item, $checklist->marking), $checklist->items);
        $this->assertEquals(['Yes', 'Yes', 'No', ''], $marks);
        $this->assertEquals('Daniel Maloney', $checklist->items[0]->teacher);
        $this->assertEquals('Yes, quite the dancer!', $checklist->items[0]->comments[0]->text);

        $plain = checklist_items::feedback_plain($checklist);
        $this->assertEquals('Dancing: Yes (Daniel Maloney: Yes, quite the dancer!); Singing: Yes (Daniel Maloney: Oh yes, '
            . 'an amazing singer.); Acting: No (Daniel Maloney: Not the best actor, keep trying.); Juggling: Not marked', $plain);
    }

    /**
     * The evidence link opens the learner's checklist report, and the summary shows items, marks and comments.
     */
    public function test_summary_link_and_feedback(): void {
        $this->resetAfterTest();
        [$framework, $student, , $cmid] = $this->setup_data();
        $this->setAdminUser();

        $summary = summary::build((int) $student->id, $framework->get('id'));
        $row = $summary->competencies[0]->evidence[0];
        $this->assertEquals(
            (new \moodle_url('/mod/checklist/report.php', ['id' => $cmid, 'studentid' => $student->id]))->out(false),
            $row->url
        );
        $this->assertStringContainsString('Dancing: <strong>Yes</strong>', $row->feedback);
        $this->assertStringContainsString('Acting: <strong>No</strong>', $row->feedback);
        $this->assertStringContainsString('<em>Daniel Maloney</em>: Yes, quite the dancer!', $row->feedback);
        $this->assertStringContainsString('Juggling: <strong>Not marked</strong>', $row->feedback);
        $this->assertStringNotContainsString('Secret', $row->feedback);

        // Redacting staff names removes the assessor from the comments.
        $identity = new identity(\core_user::get_user($student->id), false, false, true);
        $row = summary::build((int) $student->id, $framework->get('id'), $identity)->competencies[0]->evidence[0];
        $this->assertStringNotContainsString('Daniel Maloney', $row->feedback);
        $this->assertStringContainsString('<em>[redacted]</em>: Yes, quite the dancer!', $row->feedback);
    }

    /**
     * Completion logged twice (e.g. Complete, then Complete (pass) when the grade arrives): only the latest row has
     * the checklist, the earlier one says it's superseded.
     */
    public function test_logged_twice(): void {
        $this->resetAfterTest();
        [$framework, $student, , $cmid] = $this->setup_data();
        $this->setAdminUser();
        $comp = \core_competency\competency::get_record(['idnumber' => 'PA1']);
        $this->getDataGenerator()->get_plugin_generator('report_evidencereporter')->create_evidence(['userid' => $student->id,
            'competencyid' => $comp->get('id'), 'cmid' => $cmid, 'action' => \core_competency\evidence::ACTION_LOG]);

        $rows = summary::build((int) $student->id, $framework->get('id'))->competencies[0]->evidence;
        $this->assertCount(2, $rows);
        $feedback = array_column($rows, 'feedback');
        $this->assertCount(1, array_filter($feedback, fn($f) => str_contains($f, 'Dancing: <strong>Yes</strong>')));
        $this->assertCount(1, array_filter($feedback, fn($f) => $f === 'Superseded by later evidence from this activity'));
    }

    /**
     * The report's feedback and link columns show the checklist.
     */
    public function test_report_columns(): void {
        $this->resetAfterTest();
        [, $student, , $cmid] = $this->setup_data();
        $this->setAdminUser();

        /** @var \core_reportbuilder_generator $rbgen */
        $rbgen = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgen->create_report(['name' => 'Evidence', 'default' => 0,
            'source' => \report_evidencereporter\reportbuilder\datasource\evidence::class]);
        foreach (['evidence:evidenceurl', 'evidence:feedback'] as $column) {
            $rbgen->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $column]);
        }
        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(1, $content);
        [$url, $feedback] = array_values($content[0]);
        $this->assertStringContainsString('/mod/checklist/report.php?id=' . $cmid . '&studentid=' . $student->id, $url);
        $this->assertStringContainsString('Singing: <strong>Yes</strong>', $feedback);
        $this->assertStringContainsString('Oh yes, an amazing singer.', $feedback);
    }

    /**
     * The evidence pack has a checklist record with every item, mark, assessor and comment.
     */
    public function test_pack_record(): void {
        $this->resetAfterTest();
        [$framework, $student, , $cmid] = $this->setup_data();
        $this->setAdminUser();

        $pack = new evidence_pack((int) $student->id, $framework->get('id'));
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($pack->build()));
        $this->assertNotFalse($zip->locateName('02 Assessments/PA101 - Talent show/Checklist record.pdf'));
        $zip->close();
        $record = array_values(array_filter($pack->get_rendered_html(), fn($html) => str_contains($html, 'Checklist items')));
        $this->assertCount(1, $record);
        $this->assertStringContainsString('studentid=' . $student->id, $record[0]);
        foreach (['Dancing', 'Acting', 'Daniel Maloney', 'Not the best actor, keep trying.', 'Not marked'] as $text) {
            $this->assertStringContainsString($text, $record[0]);
        }

        // Without feedback: marks stay, comments go.
        $pack = new evidence_pack((int) $student->id, $framework->get('id'), false);
        $pack->build();
        $record = array_values(array_filter($pack->get_rendered_html(), fn($html) => str_contains($html, 'Checklist items')));
        $this->assertStringContainsString('Acting', $record[0]);
        $this->assertStringNotContainsString('keep trying', $record[0]);
    }
}
