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

#[\PHPUnit\Framework\Attributes\CoversClass(similarity::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(source\assign::class)]
/**
 * Tests for similarity check results.
 *
 * @package    report_evidencereporter
 * @category   test
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\similarity
 * @covers     \report_evidencereporter\local\source\assign
 */
final class similarity_test extends \advanced_testcase {
    /** @var array Link arrays the fake similarity plugin was asked about. */
    private array $asked = [];

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        sources::reset_cache();
        similarity::set_link_source(null);
    }

    #[\Override]
    protected function tearDown(): void {
        similarity::set_link_source(null);
        parent::tearDown();
    }

    /**
     * A fake similarity plugin: 42% for files, 17% for online text.
     */
    private function fake_plugin(): void {
        similarity::set_link_source(function (array $linkarray): string {
            $this->asked[] = $linkarray;
            $score = isset($linkarray['file']) ? '42%' : '17%';
            return '<span class="fakesim"><a href="https://similarity.example.com/report">' . $score . '</a></span>';
        });
    }

    /**
     * An assignment with a learner's file and online text submission.
     *
     * @return \stdClass {course, cm, student, teacher, row}
     */
    private function setup_submission(): \stdClass {
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $assign = $gen->create_module('assign', ['course' => $course->id, 'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 1, 'assignsubmission_file_maxsizebytes' => 1000000,
            'assignsubmission_onlinetext_enabled' => 1]);
        $gen->get_plugin_generator('mod_assign')->create_submission(['userid' => $student->id, 'cmid' => $assign->cmid,
            'file' => 'lib/tests/fixtures/empty.txt', 'onlinetext' => 'My essay on Hamlet']);
        $row = (object) ['cmid' => $assign->cmid, 'modname' => 'assign', 'userid' => $student->id, 'islatest' => 1];
        return (object) ['course' => $course, 'cmid' => $assign->cmid, 'student' => $student, 'teacher' => $teacher,
            'row' => $row];
    }

    public function test_nothing_without_a_similarity_plugin(): void {
        $s = $this->setup_submission();
        $this->setUser($s->teacher);
        $this->assertFalse(similarity::is_available());
        $this->assertSame('', similarity::row_html($s->row));
    }

    public function test_staff_see_results_for_files_and_text(): void {
        $s = $this->setup_submission();
        $this->fake_plugin();
        $this->setUser($s->teacher);

        $html = similarity::row_html($s->row);
        $this->assertStringContainsString('empty.txt: <span class="fakesim">', $html);
        $this->assertStringContainsString('42%', $html);
        $this->assertStringContainsString('17%', $html);

        // Moodle's link array: the learner, the activity and course, and the file or text.
        $this->assertCount(2, $this->asked);
        foreach ($this->asked as $linkarray) {
            $this->assertEquals($s->student->id, $linkarray['userid']);
            $this->assertEquals($s->cmid, $linkarray['cmid']);
            $this->assertEquals($s->course->id, $linkarray['course']);
        }
        $this->assertInstanceOf(\stored_file::class, $this->asked[0]['file']);
        $this->assertEquals('My essay on Hamlet', $this->asked[1]['content']);

        // Older evidence from the same activity doesn't repeat it.
        $this->assertSame('', similarity::row_html((object) (['islatest' => 0] + (array) $s->row)));
    }

    public function test_learners_do_not_see_results(): void {
        $s = $this->setup_submission();
        $this->fake_plugin();
        $this->setUser($s->student);
        $this->assertSame('', similarity::row_html($s->row));
    }

    public function test_settings(): void {
        $s = $this->setup_submission();
        $this->fake_plugin();
        $this->setUser($s->teacher);

        // Default: summary only, not packs.
        $this->assertEquals(similarity::SHOW_SUMMARY, similarity::get_mode());
        $this->assertNotSame('', similarity::row_html($s->row));
        $this->assertSame('', similarity::pack_text($s->row));

        // Packs get plain text.
        set_config('similarity', similarity::SHOW_PACKS, 'report_evidencereporter');
        $text = similarity::pack_text($s->row);
        $this->assertStringContainsString('empty.txt: 42%', $text);
        $this->assertStringContainsString('17%', $text);
        $this->assertStringNotContainsString('<', $text);

        set_config('similarity', similarity::SHOW_OFF, 'report_evidencereporter');
        similarity::reset_cache();
        $this->assertSame('', similarity::row_html($s->row));
        $this->assertSame('', similarity::pack_text($s->row));
    }

    public function test_no_submission(): void {
        $s = $this->setup_submission();
        $this->fake_plugin();
        $this->setUser($s->teacher);
        $other = $this->getDataGenerator()->create_and_enrol($s->course, 'student');
        $this->assertSame('', similarity::row_html((object) (['userid' => $other->id] + (array) $s->row)));
    }
}
