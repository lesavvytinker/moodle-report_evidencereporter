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

#[\PHPUnit\Framework\Attributes\CoversClass(source\workshop::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(source\attendance::class)]
/**
 * Tests for Workshop and Attendance records as evidence.
 *
 * @package    report_evidencereporter
 * @category   test
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\source\workshop
 * @covers     \report_evidencereporter\local\source\attendance
 */
final class workshop_attendance_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        rating::reset_cache();
        $this->resetAfterTest();
        sources::reset_cache();
        source\workshop::reset_cache();
        source\attendance::reset_cache();
    }

    /**
     * A course, learner, staff member, framework and competency, with an activity linked to it.
     *
     * @param string $modname
     * @param array $settings
     * @return \stdClass
     */
    private function setup_activity(string $modname, array $settings = []): \stdClass {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['shortname' => 'WHS101']);
        $student = $gen->create_and_enrol($course, 'student', ['firstname' => 'Ava', 'lastname' => 'Learner']);
        $peer = $gen->create_and_enrol($course, 'student', ['firstname' => 'Ben', 'lastname' => 'Peer']);
        $teacher = $gen->create_and_enrol($course, 'editingteacher', ['firstname' => 'Tess', 'lastname' => 'Trainer']);
        $this->setAdminUser();
        $activity = $gen->create_module($modname, ['course' => $course->id, 'name' => 'Practical'] + $settings);
        $cgen = $gen->get_plugin_generator('core_competency');
        $framework = $cgen->create_framework();
        $comp = $cgen->create_competency(['competencyframeworkid' => $framework->get('id')]);
        $cgen->create_course_competency(['courseid' => $course->id, 'competencyid' => $comp->get('id')]);
        $cgen->create_course_module_competency(['competencyid' => $comp->get('id'), 'cmid' => $activity->cmid]);
        return (object) ['course' => $course, 'student' => $student, 'peer' => $peer, 'teacher' => $teacher,
            'activity' => $activity, 'cmid' => (int) $activity->cmid, 'framework' => $framework, 'comp' => $comp];
    }

    public function test_workshop(): void {
        global $DB;
        $s = $this->setup_activity('workshop', ['grade' => 80, 'gradinggrade' => 20]);
        $wgen = $this->getDataGenerator()->get_plugin_generator('mod_workshop');
        $subid = $wgen->create_submission($s->activity->id, $s->student->id, ['title' => 'Risk assessment for the dock',
            'content' => 'Ava Learner found three hazards.']);
        get_file_storage()->create_file_from_string(['contextid' => \context_module::instance($s->cmid)->id,
            'component' => 'mod_workshop', 'filearea' => 'submission_attachment', 'itemid' => $subid, 'filepath' => '/',
            'filename' => 'risk-matrix.xlsx'], 'x');
        $wgen->create_assessment($subid, $s->peer->id, ['grade' => 75, 'weight' => 1,
            'feedbackauthor' => 'Good matrix, add controls.']);
        $wgen->create_assessment($subid, $s->teacher->id, ['grade' => 90, 'weight' => 1,
            'feedbackauthor' => 'Well researched.']);
        $DB->set_field('workshop_submissions', 'grade', 82.5, ['id' => $subid]);
        $DB->insert_record('workshop_aggregations', ['workshopid' => $s->activity->id, 'userid' => $s->student->id,
            'gradinggrade' => 90, 'timegraded' => time()]);
        $DB->set_field('workshop', 'phase', 50, ['id' => $s->activity->id]);
        $this->setAdminUser();

        $summary = summary::build((int) $s->student->id, (int) $s->framework->get('id'));
        $record = $summary->competencies[0]->records[0];
        $this->assertEquals('Workshop record', $record->heading);
        $this->assertEquals('Submission and 2 assessment(s) received', $record->count);
        $html = $record->html;
        $this->assertStringContainsString('Risk assessment for the dock', $html);
        $this->assertStringContainsString('Grade for submission: <strong>66.00 / 80.00</strong>', $html);
        $expected = 'Grade for assessing others (0 assessment(s) given): <strong>18.00 / 20.00</strong>';
        $this->assertStringContainsString($expected, $html);
        // Peers anonymised, staff named.
        $this->assertStringContainsString('Peer reviewer: <strong>60.00 / 80.00</strong>', $html);
        $this->assertStringNotContainsString('Ben Peer', $html);
        $this->assertStringContainsString('Tess Trainer: <strong>72.00 / 80.00</strong>', $html);
        $this->assertStringContainsString('Good matrix, add controls.', $html);
        $this->assertStringContainsString('risk-matrix.xlsx</a>', $html);

        // Learners don't see grades until the workshop is closed.
        $DB->set_field('workshop', 'phase', 40, ['id' => $s->activity->id]);
        source\workshop::reset_cache();
        $this->setUser($s->student);
        $html = summary::build((int) $s->student->id, (int) $s->framework->get('id'))->competencies[0]->records[0]->html;
        $this->assertStringContainsString('Grades are shown once the workshop is closed.', $html);
        $this->assertStringNotContainsString('66.00', $html);

        // Pack: a record PDF and the attachment.
        $DB->set_field('workshop', 'phase', 50, ['id' => $s->activity->id]);
        source\workshop::reset_cache();
        $this->setAdminUser();
        $pack = new evidence_pack((int) $s->student->id, (int) $s->framework->get('id'));
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($pack->build()));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        $this->assertContains('02 Assessments/WHS101 - Practical/Workshop record.pdf', $names);
        $this->assertContains('02 Assessments/WHS101 - Practical/Submitted files/risk-matrix.xlsx', $names);
    }

    public function test_attendance(): void {
        global $DB;
        if (!\core_component::get_component_directory('mod_attendance')) {
            $this->markTestSkipped('Attendance plugin not installed');
        }
        $s = $this->setup_activity('attendance');
        $conditions = ['attendanceid' => $s->activity->id, 'deleted' => 0];
        $statuses = $DB->get_records_menu('attendance_statuses', $conditions, 'grade DESC', 'acronym, id');
        $base = strtotime('2026-11-02 09:00');
        $sessions = [['P', 'Workshop 1: manual handling'], ['P', 'Workshop 2: hazards'], ['A', 'Workshop 3: controls']];
        foreach ($sessions as $i => [$acronym, $description]) {
            $sessionid = $DB->insert_record('attendance_sessions', ['attendanceid' => $s->activity->id, 'groupid' => 0,
                'sessdate' => $base + $i * 7 * DAYSECS, 'duration' => 3 * HOURSECS, 'lasttaken' => time(),
                'lasttakenby' => $s->teacher->id, 'timemodified' => time(), 'description' => $description,
                'descriptionformat' => FORMAT_HTML, 'statusset' => 0]);
            $DB->insert_record('attendance_log', ['sessionid' => $sessionid, 'studentid' => $s->student->id,
                'statusid' => $statuses[$acronym], 'statusset' => implode(',', $statuses), 'timetaken' => time(),
                'takenby' => $s->teacher->id, 'remarks' => $acronym === 'A' ? 'Sick, certificate provided' : '']);
        }
        $this->setAdminUser();

        $this->assertContains((int) $s->comp->get('id'), summary::get_assessed_competencyids((int) $s->student->id));
        $record = summary::build((int) $s->student->id, (int) $s->framework->get('id'))->competencies[0]->records[0];
        $this->assertEquals('Attendance record', $record->heading);
        $this->assertStringContainsString('3 session(s) recorded, 67% of points', $record->count);
        $this->assertStringContainsString('Workshop 2: hazards', $record->html);
        $this->assertStringContainsString('Sick, certificate provided', $record->html);
        $this->assertStringContainsString('Tess Trainer', $record->html);
        $this->assertStringContainsString('3 hours', $record->html);

        $pack = new evidence_pack((int) $s->student->id, (int) $s->framework->get('id'));
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($pack->build()));
        $this->assertNotFalse($zip->locateName('02 Assessments/WHS101 - Practical/Attendance record.pdf'));
        $zip->close();
    }
}
