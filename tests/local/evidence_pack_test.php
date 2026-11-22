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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/report/evidencereporter/tests/generator/attempts_trait.php');

#[\PHPUnit\Framework\Attributes\CoversClass(evidence_pack::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(summary::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\report_evidencereporter\event\pack_downloaded::class)]
/**
 * Tests for the evidence pack.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\evidence_pack
 * @covers     \report_evidencereporter\local\summary
 * @covers     \report_evidencereporter\event\pack_downloaded
 */
final class evidence_pack_test extends \advanced_testcase {
    use \report_evidencereporter_attempts_trait;

    /**
     * Static caches hold IDs, which the test database reuses between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        \report_evidencereporter\local\feedback_visibility::reset_cache();
        \report_evidencereporter\local\attempts::reset_cache();
        rating::reset_cache();
        \report_evidencereporter\local\activity_grade::reset_cache();
    }

    /**
     * Learner with an assignment (file + online text, NYC then competent), a quiz (failed then passed) and RPL.
     *
     * @return array
     */
    private function setup_data(): array {
        global $DB;
        $gen = $this->getDataGenerator();
        /** @var \core_competency_generator $cgen */
        $cgen = $gen->get_plugin_generator('core_competency');
        $framework = $cgen->create_framework(['shortname' => 'WHS', 'idnumber' => 'WHS101']);
        $comp = $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'shortname' => 'Identify hazards',
            'idnumber' => 'WHS101.1.1']);

        $course = $gen->create_course(['shortname' => 'WHS']);
        $student = $gen->create_user(['firstname' => 'Sam', 'lastname' => 'Student']);
        $marker = $gen->create_user(['firstname' => 'Mel', 'lastname' => 'Marker']);
        $gen->enrol_user($student->id, $course->id, 'student');
        $gen->enrol_user($marker->id, $course->id, 'editingteacher');
        $cgen->create_course_competency(['courseid' => $course->id, 'competencyid' => $comp->get('id')]);

        $assign = $gen->create_module('assign', ['course' => $course->id, 'name' => 'Risk assessment',
            'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 5,
            'assignsubmission_file_maxsizebytes' => 1048576, 'assignfeedback_comments_enabled' => 1, 'submissiondrafts' => 0,
            'attemptreopenmethod' => 'manual', 'maxattempts' => -1, 'grade' => 100]);
        $quiz = $this->create_quiz_with_question((int) $course->id, ['name' => 'Induction quiz', 'attempts' => 0]);
        foreach ([$assign->cmid, $quiz->cmid] as $cmid) {
            $cgen->create_course_module_competency(['cmid' => $cmid, 'competencyid' => $comp->get('id')]);
        }

        $this->grade_assign_attempt(
            $assign,
            (int) $student->id,
            (int) $marker->id,
            40,
            '<p>Controls missing.</p>',
            true,
            'report/evidencereporter/tests/fixtures/risk-assessment.txt'
        );
        $this->grade_assign_attempt($assign, (int) $student->id, (int) $marker->id, 80, '<p>All criteria met.</p>');
        $this->attempt_quiz($quiz, (int) $student->id, false);
        $this->attempt_quiz($quiz, (int) $student->id, true);

        // Evidence of prior learning with a file.
        $ue = $cgen->create_user_evidence(['userid' => $student->id, 'name' => 'Third-party report']);
        $cgen->create_user_evidence_competency(['userevidenceid' => $ue->get('id'), 'competencyid' => $comp->get('id')]);
        get_file_storage()->create_file_from_string(['contextid' => \context_user::instance($student->id)->id,
            'component' => 'core_competency', 'filearea' => 'userevidence', 'itemid' => $ue->get('id'), 'filepath' => '/',
            'filename' => 'supervisor-report.txt'], 'Observed working safely for 6 months.');

        return [$framework, $student, $marker];
    }

    /**
     * Open a built pack and return its entries (path => content).
     *
     * @param evidence_pack $pack
     * @return array
     */
    private function open_pack(evidence_pack $pack): array {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($pack->build()));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (substr($name, -1) !== '/') {
                $entries[$name] = $zip->getFromIndex($i);
            }
        }
        $zip->close();
        return $entries;
    }

    /**
     * Admin pack has the summary, every attempt (failed included) with submissions, RPL files and a valid manifest.
     */
    public function test_pack_contents(): void {
        $this->resetAfterTest();
        [$framework, $student] = $this->setup_data();
        $this->setAdminUser();

        $entries = $this->open_pack(new evidence_pack((int) $student->id, $framework->get('id')));
        $paths = array_keys($entries);
        $assigndir = '02 Assessments/WHS - Risk assessment';
        $quizdir = '02 Assessments/WHS - Induction quiz';

        foreach (
            [
            '01 Competency evidence summary.pdf',
            "{$assigndir}/Attempt 1/Attempt record.pdf",
            "{$assigndir}/Attempt 1/Submitted files/risk-assessment.txt",
            "{$assigndir}/Attempt 1/Online text.html",
            "{$assigndir}/Attempt 2/Attempt record.pdf",
            "{$quizdir}/Attempt 1/Attempt record.pdf",
            "{$quizdir}/Attempt 2/Attempt record.pdf",
            '03 Prior learning/Third-party report/Details.pdf',
            '03 Prior learning/Third-party report/supervisor-report.txt',
            'README.txt',
            'manifest.csv',
            ] as $expected
        ) {
            $this->assertContains($expected, $paths);
        }
        $this->assertStringStartsWith('%PDF', $entries['01 Competency evidence summary.pdf']);
        $this->assertStringContainsString(
            'Hazard: unguarded pinch point',
            $entries["{$assigndir}/Attempt 1/Submitted files/risk-assessment.txt"]
        );

        // Every file is in the manifest with the right checksum.
        $manifest = array_map(
            fn($line) => str_getcsv($line, ',', '"', ''),
            array_slice(explode("\n", trim($entries['manifest.csv'])), 1)
        );
        $this->assertCount(count($entries) - 1, $manifest);
        foreach ($manifest as [$path, $bytes, $sha]) {
            $this->assertEquals(hash('sha256', $entries[$path]), $sha, $path);
            $this->assertEquals(strlen($entries[$path]), (int) $bytes);
        }
        $this->assertStringContainsString('Include feedback: Yes', $entries['README.txt']);
    }

    /**
     * Without redaction every document carries the username and learner reference.
     */
    public function test_pack_identity(): void {
        $this->resetAfterTest();
        [$framework, $student] = $this->setup_data();
        $this->setAdminUser();

        $pack = new evidence_pack((int) $student->id, $framework->get('id'));
        $entries = $this->open_pack($pack);
        $reference = (new identity($student))->reference();
        $this->assertMatchesRegularExpression('/^L-[0-9A-F]{8}$/', $reference);
        $this->assertStringContainsString('Username: ' . $student->username, $entries['README.txt']);
        $this->assertStringContainsString('Learner reference: ' . $reference, $entries['README.txt']);
        // Summary, four attempt records and the prior learning details: each names the learner with username.
        $html = $pack->get_rendered_html();
        $this->assertCount(6, $html);
        foreach ($html as $document) {
            $this->assertStringContainsString('Sam Student', $document);
            $this->assertStringContainsString(s($student->username), $document);
            $this->assertStringContainsString($reference, $document);
        }
        $this->assertStringContainsString('Sam Student (' . $student->username . ')', $pack->get_filename());
    }

    /**
     * Redacting everything: no learner name, username, email or staff name in any generated document or file name.
     */
    public function test_pack_redacted(): void {
        global $DB;
        $this->resetAfterTest();
        [$framework, $student] = $this->setup_data();
        $this->setAdminUser();
        // Feedback that names the learner, and a prior learning file named after them.
        $DB->set_field_select(
            'assignfeedback_comments',
            'commenttext',
            '<p>Well done sam, Sam Student met all criteria.</p>',
            '1 = 1'
        );
        $ue = $DB->get_record('competency_userevidence', ['userid' => $student->id]);
        get_file_storage()->create_file_from_string(['contextid' => \context_user::instance($student->id)->id,
            'component' => 'core_competency', 'filearea' => 'userevidence', 'itemid' => $ue->id, 'filepath' => '/',
            'filename' => 'Sam_Student_logbook.txt'], 'Logbook.');
        \report_evidencereporter\local\attempts::reset_cache();

        $pack = new evidence_pack(
            (int) $student->id,
            $framework->get('id'),
            true,
            ['redactname' => 1, 'redactusername' => 1, 'redactstaff' => 1]
        );
        $entries = $this->open_pack($pack);
        $reference = (new identity($student))->reference();

        $generated = $pack->get_rendered_html();
        $generated[] = $entries['README.txt'];
        $generated[] = implode("\n", array_keys($entries));
        $generated[] = $pack->get_filename();
        $this->assertCount(9, $generated);
        foreach ($generated as $text) {
            foreach (['Sam Student', $student->username, $student->email, 'Mel Marker', 'Admin User'] as $term) {
                $this->assertStringNotContainsString($term, $text);
            }
            $this->assertDoesNotMatchRegularExpression('/\\b(sam|student|mel)\\b/i', $text);
            $this->assertStringContainsString($reference, $text === implode("\n", array_keys($entries))
                ? $pack->get_filename() : $text);
        }
        $this->assertStringContainsString('Well done [redacted], [redacted] met all criteria', implode('', $generated));
        $this->assertArrayHasKey('03 Prior learning/Third-party report/redacted_redacted_logbook.txt', $entries);
        $this->assertStringContainsString('are not redacted', $entries['README.txt']);
        $this->assertEquals(
            'Evidence pack - ' . $reference . ' - WHS101 - ' . userdate(time(), '%Y-%m-%d') . '.zip',
            $pack->get_filename()
        );
    }

    /**
     * Without feedback, the README says so. A teacher of another course gets nothing; the learner gets their own pack.
     */
    public function test_pack_feedback_and_access(): void {
        $this->resetAfterTest();
        [$framework, $student] = $this->setup_data();

        $this->setAdminUser();
        $entries = $this->open_pack(new evidence_pack((int) $student->id, $framework->get('id'), false));
        $this->assertStringContainsString('Include feedback: No', $entries['README.txt']);

        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $this->getDataGenerator()->create_course()->id, 'editingteacher');
        $this->setUser($other);
        $this->assertEmpty(summary::get_frameworks((int) $student->id));

        $this->setUser($student);
        $this->assertArrayHasKey($framework->get('id'), summary::get_frameworks((int) $student->id));
        $entries = $this->open_pack(new evidence_pack((int) $student->id, $framework->get('id')));
        $this->assertArrayHasKey('03 Prior learning/Third-party report/supervisor-report.txt', $entries);
    }

    /**
     * A learner with only failed attempts (no competency evidence) still has a framework to summarise and pack.
     */
    public function test_attempts_only_framework(): void {
        global $DB;
        $this->resetAfterTest();
        [$framework, $student] = $this->setup_data();
        $DB->delete_records('competency_evidence');
        $DB->delete_records('competency_userevidencecomp');
        $this->setAdminUser();

        $this->assertTrue(summary::has_visible_evidence((int) $student->id));
        $this->assertArrayHasKey($framework->get('id'), summary::get_frameworks((int) $student->id));
    }

    /**
     * The download event is valid.
     */
    public function test_event(): void {
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        $sink = $this->redirectEvents();
        \report_evidencereporter\event\pack_downloaded::create([
            'context' => \context_user::instance($student->id),
            'relateduserid' => $student->id,
            'other' => ['frameworkid' => 5, 'includefeedback' => 1],
        ])->trigger();
        $this->assertInstanceOf(\report_evidencereporter\event\pack_downloaded::class, $sink->get_events()[0]);
    }
}
