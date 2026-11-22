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

#[\PHPUnit\Framework\Attributes\CoversClass(source\h5pactivity::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(source\scorm::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(attempts::class)]
/**
 * Tests for H5P and SCORM attempts as evidence.
 *
 * @package    report_evidencereporter
 * @category   test
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\source\h5pactivity
 * @covers     \report_evidencereporter\local\source\scorm
 * @covers     \report_evidencereporter\local\attempts
 */
final class h5p_scorm_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        attempts::reset_cache();
        rating::reset_cache();
        sources::reset_cache();
        activity_grade::reset_cache();
        feedback_visibility::reset_cache();
    }

    /**
     * A course, learner, framework and competency, with an activity linked to it.
     *
     * @param string $modname
     * @param array $settings
     * @return \stdClass
     */
    private function setup_activity(string $modname, array $settings = []): \stdClass {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['shortname' => 'WHS101']);
        $student = $gen->create_and_enrol($course, 'student');
        $this->setAdminUser();
        $activity = $gen->create_module($modname, ['course' => $course->id, 'name' => 'Hazard check'] + $settings);
        $cgen = $gen->get_plugin_generator('core_competency');
        $framework = $cgen->create_framework();
        $comp = $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'shortname' => 'Identify hazards']);
        $cgen->create_course_competency(['courseid' => $course->id, 'competencyid' => $comp->get('id')]);
        $cgen->create_course_module_competency(['competencyid' => $comp->get('id'), 'cmid' => $activity->cmid]);
        return (object) ['course' => $course, 'student' => $student, 'activity' => $activity, 'cmid' => (int) $activity->cmid,
            'framework' => $framework, 'comp' => $comp];
    }

    public function test_h5p_attempts(): void {
        global $DB;
        $s = $this->setup_activity('h5pactivity', ['grade' => 100, 'enabletracking' => 1]);
        $now = time();
        foreach ([[1, 3, 5, 0.6, 0], [2, 9, 10, 0.9, 1]] as [$no, $raw, $max, $scaled, $success]) {
            $attemptid = $DB->insert_record('h5pactivity_attempts', ['h5pactivityid' => $s->activity->id,
                'userid' => $s->student->id, 'attempt' => $no, 'rawscore' => $raw, 'maxscore' => $max, 'scaled' => $scaled,
                'duration' => 95, 'completion' => 1, 'success' => $success, 'timecreated' => $now + $no,
                'timemodified' => $now + $no]);
        }
        $DB->insert_record('h5pactivity_attempts_results', ['attemptid' => $attemptid, 'subcontent' => '',
            'timecreated' => $now, 'interactiontype' => 'choice', 'description' => '<p>Which is a slip hazard?</p>',
            'correctpattern' => '', 'response' => '0', 'additionals' => '', 'rawscore' => 1, 'maxscore' => 1,
            'completion' => 1, 'success' => 1]);
        $this->setAdminUser();

        $rows = attempts::get_for_user((int) $s->student->id, [$s->cmid])[$s->cmid];
        $this->assertCount(2, $rows);
        $this->assertEquals([0, 1], array_map(fn($r) => (int) $r->islatest, $rows));
        $latest = attempts::format($rows[1], true);
        $this->assertEquals(2, $latest->attemptno);
        $this->assertEquals('90.00 / 100.00', $latest->grade);
        $this->assertStringContainsString('View attempt report', $latest->feedback);
        $this->assertStringContainsString('mod/h5pactivity/report.php', $latest->url->out(false));

        // Counts for the summary, and in the pack.
        $summary = summary::build((int) $s->student->id, (int) $s->framework->get('id'));
        $this->assertCount(2, $summary->competencies[0]->attempts);
        $pack = new evidence_pack((int) $s->student->id, (int) $s->framework->get('id'));
        $pack->build();
        $html = implode("\n", $pack->get_rendered_html());
        $this->assertStringContainsString('9 / 10 (90%)', $html);
        $this->assertStringContainsString('Which is a slip hazard?', $html);
        $this->assertStringContainsString('Passed', $html);
        $this->assertStringContainsString('1 min 35 secs', $html);
    }

    public function test_h5p_ungraded_shows_raw_score(): void {
        global $DB;
        $s = $this->setup_activity('h5pactivity', ['grade' => 0, 'enabletracking' => 1]);
        $DB->insert_record('h5pactivity_attempts', ['h5pactivityid' => $s->activity->id, 'userid' => $s->student->id,
            'attempt' => 1, 'rawscore' => 4, 'maxscore' => 5, 'scaled' => 0.8, 'duration' => 10, 'completion' => 1,
            'success' => null, 'timecreated' => time(), 'timemodified' => time()]);
        $this->setAdminUser();
        $rows = attempts::get_for_user((int) $s->student->id, [$s->cmid])[$s->cmid];
        $this->assertEquals('4 / 5', attempts::format($rows[0], true)->grade);
    }

    public function test_scorm_attempts(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');
        $s = $this->setup_activity('scorm', ['maxgrade' => 100, 'grademethod' => GRADEHIGHEST]);
        $sco = $DB->get_record('scorm_scoes', ['scorm' => $s->activity->id, 'scormtype' => 'sco'], '*', IGNORE_MULTIPLE);
        foreach ([1 => ['failed', 40], 2 => ['passed', 85]] as $attempt => [$status, $score]) {
            scorm_insert_track($s->student->id, $s->activity->id, $sco->id, $attempt, 'cmi.core.lesson_status', $status);
            scorm_insert_track($s->student->id, $s->activity->id, $sco->id, $attempt, 'cmi.core.score.raw', $score);
            scorm_insert_track($s->student->id, $s->activity->id, $sco->id, $attempt, 'cmi.core.total_time', '00:12:30');
        }
        // An attempt the package never wrote to doesn't count.
        $DB->insert_record('scorm_attempt', ['userid' => $s->student->id, 'scormid' => $s->activity->id, 'attempt' => 3]);
        $this->setAdminUser();

        $rows = attempts::get_for_user((int) $s->student->id, [$s->cmid])[$s->cmid];
        $this->assertCount(2, $rows);
        $this->assertEquals([0, 1], array_map(fn($r) => (int) $r->islatest, $rows));
        $first = attempts::format($rows[0], true);
        $latest = attempts::format($rows[1], true);
        $this->assertEquals('40.00 / 100.00', $first->grade);
        $this->assertEquals('85.00 / 100.00', $latest->grade);
        $this->assertStringContainsString('mod/scorm/report/userreport.php', $latest->url->out(false));
        $this->assertStringContainsString('View attempt report', $latest->feedback);

        $pack = new evidence_pack((int) $s->student->id, (int) $s->framework->get('id'));
        $pack->build();
        $html = implode("\n", $pack->get_rendered_html());
        $this->assertStringContainsString(get_string('passed', 'scorm'), $html);
        $this->assertStringContainsString('00:12:30', $html);
        $this->assertStringContainsString('85', $html);
    }

    public function test_scorm_learner_link(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');
        $s = $this->setup_activity('scorm');
        $sco = $GLOBALS['DB']->get_record(
            'scorm_scoes',
            ['scorm' => $s->activity->id, 'scormtype' => 'sco'],
            '*',
            IGNORE_MULTIPLE
        );
        scorm_insert_track($s->student->id, $s->activity->id, $sco->id, 1, 'cmi.core.lesson_status', 'completed');
        $this->setUser($s->student);
        $rows = attempts::get_for_user((int) $s->student->id, [$s->cmid])[$s->cmid];
        $formatted = attempts::format($rows[0], true);
        // Learners can't open SCORM reports, so they're sent to the activity.
        $this->assertStringContainsString('mod/scorm/view.php', $formatted->url->out(false));
    }
}
