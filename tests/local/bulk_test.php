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

#[\PHPUnit\Framework\Attributes\CoversClass(bulk::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\report_evidencereporter\task\bulk_download::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\report_evidencereporter\task\cleanup_bulk_files::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\report_evidencereporter\event\bulk_created::class)]
/**
 * Tests for bulk downloads.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\bulk
 * @covers     \report_evidencereporter\task\bulk_download
 * @covers     \report_evidencereporter\task\cleanup_bulk_files
 * @covers     \report_evidencereporter\event\bulk_created
 */
final class bulk_test extends \advanced_testcase {
    /**
     * Static caches hold IDs, which the test database reuses between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        feedback_visibility::reset_cache();
        attempts::reset_cache();
        rating::reset_cache();
        activity_grade::reset_cache();
    }

    /**
     * Template "WHS Jan 25" with four learners: two with evidence in course 1, one in course 2, one with none.
     *
     * @return array
     */
    private function setup_data(): array {
        $gen = $this->getDataGenerator();
        /** @var \core_competency_generator $cgen */
        $cgen = $gen->get_plugin_generator('core_competency');
        /** @var \report_evidencereporter_generator $egen */
        $egen = $gen->get_plugin_generator('report_evidencereporter');

        $framework = $cgen->create_framework(['shortname' => 'WHS 101', 'idnumber' => 'WHS101']);
        $comp = $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'idnumber' => 'WHS101.1']);
        $template = $cgen->create_template(['shortname' => 'WHS Jan 25']);
        $cgen->create_template_competency(['templateid' => $template->get('id'), 'competencyid' => $comp->get('id')]);

        $c1 = $gen->create_course(['shortname' => 'C1']);
        $c2 = $gen->create_course(['shortname' => 'C2']);
        $a1 = $gen->create_module('assign', ['course' => $c1->id]);
        $a2 = $gen->create_module('assign', ['course' => $c2->id]);
        $teacher = $gen->create_user(['username' => 'teacher1']);
        $gen->enrol_user($teacher->id, $c1->id, 'editingteacher');

        $learners = [];
        foreach (['Ava', 'Ben', 'Cal', 'Dee'] as $i => $name) {
            $learner = $gen->create_user(['firstname' => $name, 'lastname' => 'Learner', 'username' => strtolower($name)]);
            $cgen->create_plan(['userid' => $learner->id, 'templateid' => $template->get('id')]);
            $gen->enrol_user($learner->id, $c1->id, 'student');
            $gen->enrol_user($learner->id, $c2->id, 'student');
            if ($i < 2) {
                $egen->create_evidence(['userid' => $learner->id, 'competencyid' => $comp->get('id'), 'cmid' => $a1->cmid]);
            } else if ($i === 2) {
                $egen->create_evidence(['userid' => $learner->id, 'competencyid' => $comp->get('id'), 'cmid' => $a2->cmid]);
            }
            $learners[$name] = $learner;
        }
        return [$framework, $template, $teacher, $learners];
    }

    /**
     * Open a stored ZIP and return entries (path => content).
     *
     * @param \stored_file $file
     * @return array
     */
    private function open_zip(\stored_file $file): array {
        $path = make_request_directory() . '/bulk.zip';
        $file->copy_content_to($path);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();
        return $entries;
    }

    /**
     * Index rows from index.csv, keyed by username.
     *
     * @param array $entries
     * @return array username => status
     */
    private function index(array $entries): array {
        $rows = array_map(
            fn($line) => str_getcsv($line, ',', '"', ''),
            array_slice(preg_split('/\r\n/', trim($entries['index.csv'])), 1)
        );
        return array_combine(array_column($rows, 1), array_column($rows, 4));
    }

    /**
     * Admin: a PDF per learner with evidence; the learner with none is skipped and listed.
     */
    public function test_build_summaries(): void {
        $this->resetAfterTest();
        [$framework, $template] = $this->setup_data();
        $this->setAdminUser();

        $this->assertCount(4, bulk::get_learners('template', $template->get('id')));
        $file = bulk::build(['sourcetype' => 'template', 'sourceid' => $template->get('id'),
            'frameworkid' => $framework->get('id'), 'mode' => bulk::MODE_SUMMARIES, 'includefeedback' => 1]);
        $entries = $this->open_zip($file);

        $this->assertArrayHasKey('Ava Learner (ava).pdf', $entries);
        $this->assertArrayHasKey('Cal Learner (cal).pdf', $entries);
        $this->assertStringStartsWith('%PDF', $entries['Ben Learner (ben).pdf']);
        $this->assertArrayNotHasKey('Dee Learner (dee).pdf', $entries);
        $this->assertEquals(['ava' => 'Included', 'ben' => 'Included', 'cal' => 'Included',
            'dee' => 'Skipped: no evidence you can access in this framework or unit'], $this->index($entries));
        $this->assertStringContainsString('3 of 4 learners included', $entries['README.txt']);
        $this->assertStringStartsWith('Evidence summaries - WHS Jan 25 - WHS101', $file->get_filename());
    }

    /**
     * A teacher of course 1 only gets the learners whose evidence they can see; packs mode gives a ZIP each.
     */
    public function test_build_restricted_packs(): void {
        $this->resetAfterTest();
        [$framework, $template, $teacher] = $this->setup_data();
        $this->setUser($teacher);

        $file = bulk::build(['sourcetype' => 'template', 'sourceid' => $template->get('id'),
            'frameworkid' => $framework->get('id'), 'mode' => bulk::MODE_PACKS, 'includefeedback' => 0]);
        $entries = $this->open_zip($file);
        $this->assertArrayHasKey('Ava Learner (ava).zip', $entries);
        $this->assertArrayHasKey('Ben Learner (ben).zip', $entries);
        $this->assertArrayNotHasKey('Cal Learner (cal).zip', $entries);
        $index = $this->index($entries);
        $this->assertEquals('Included', $index['ava']);
        $this->assertStringStartsWith('Skipped', $index['cal']);
        $this->assertStringContainsString('Include feedback: No', $entries['README.txt']);
        // The file is stored in the teacher's own area.
        $this->assertEquals(\context_user::instance($teacher->id)->id, $file->get_contextid());
        $this->assertCount(1, bulk::get_files((int) $teacher->id));
    }

    /**
     * Redacting name and username: files are named by learner reference and the index carries no identifiers.
     */
    public function test_build_redacted(): void {
        $this->resetAfterTest();
        [$framework, $template] = $this->setup_data();
        $this->setAdminUser();

        $file = bulk::build(['sourcetype' => 'template', 'sourceid' => $template->get('id'),
            'frameworkid' => $framework->get('id'), 'mode' => bulk::MODE_PACKS, 'includefeedback' => 1,
            'redactname' => 1, 'redactusername' => 1, 'redactstaff' => 1]);
        $entries = $this->open_zip($file);
        $ava = \core_user::get_user_by_username('ava');
        $reference = (new identity($ava))->reference();
        $this->assertArrayHasKey($reference . '.zip', $entries);
        foreach (array_keys($entries) as $name) {
            $this->assertDoesNotMatchRegularExpression('/Learner|ava|ben|cal/', $name);
        }
        $this->assertStringNotContainsString('Ava', $entries['index.csv']);
        $this->assertStringNotContainsString('"ava"', $entries['index.csv']);
        $this->assertStringContainsString($reference, $entries['index.csv']);
        $this->assertStringContainsString(
            'Redacted: learner name, learner username, ID number and email, staff names',
            $entries['README.txt']
        );
        $this->assertStringNotContainsString('Admin User', $entries['README.txt']);

        // Inside a learner's pack, the README names nobody either.
        $inner = make_request_directory() . '/inner.zip';
        file_put_contents($inner, $entries[$reference . '.zip']);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($inner));
        $readme = $zip->getFromName('README.txt');
        $zip->close();
        $this->assertStringContainsString('Learner: [redacted]', $readme);
        $this->assertStringContainsString('Username: [redacted]', $readme);
        $this->assertStringContainsString($reference, $readme);
    }

    /**
     * Cohorts work as a source too.
     */
    public function test_cohort_source(): void {
        $this->resetAfterTest();
        [, , , $learners] = $this->setup_data();
        $cohort = $this->getDataGenerator()->create_cohort(['name' => 'WHS Jan 25 cohort']);
        cohort_add_member($cohort->id, $learners['Ava']->id);
        cohort_add_member($cohort->id, $learners['Dee']->id);
        $this->assertEquals(['ava', 'dee'], array_column(bulk::get_learners('cohort', (int) $cohort->id), 'username'));
        $this->assertEquals('WHS Jan 25 cohort', bulk::get_source_name('cohort', (int) $cohort->id));
    }

    /**
     * The background task runs as the requester, stores the file and sends a notification. Without the capability,
     * it does nothing.
     */
    public function test_task(): void {
        global $CFG;
        require_once($CFG->dirroot . '/message/lib.php');
        $this->resetAfterTest();
        [$framework, $template, $teacher] = $this->setup_data();
        $admin = get_admin();
        $options = ['sourcetype' => 'template', 'sourceid' => $template->get('id'), 'frameworkid' => $framework->get('id'),
            'mode' => bulk::MODE_SUMMARIES, 'includefeedback' => 1];

        foreach ([$admin, $teacher] as $requester) {
            $task = new \report_evidencereporter\task\bulk_download();
            $task->set_userid($requester->id);
            $task->set_custom_data($options);
            \core\task\manager::queue_adhoc_task($task);
        }
        $this->assertCount(1, bulk::get_pending((int) $admin->id));

        $sink = $this->redirectMessages();
        $this->expectOutputRegex('/Built Evidence summaries/');
        $this->runAdhocTasks(\report_evidencereporter\task\bulk_download::class);
        $messages = $sink->get_messages();

        // Admin: file and notification. Teacher (no capability by default): nothing.
        $this->assertCount(1, bulk::get_files((int) $admin->id));
        $this->assertCount(0, bulk::get_files((int) $teacher->id));
        $this->assertCount(1, $messages);
        $this->assertEquals($admin->id, $messages[0]->useridto);
        $this->assertEquals('Your bulk evidence download is ready', $messages[0]->subject);
        $this->assertStringContainsString('pluginfile.php', $messages[0]->fullmessage);
        $this->assertCount(0, bulk::get_pending((int) $admin->id));
    }

    /**
     * Cleanup deletes downloads older than the keep period only.
     */
    public function test_cleanup(): void {
        global $DB;
        $this->resetAfterTest();
        [$framework, $template] = $this->setup_data();
        $this->setAdminUser();
        $options = ['sourcetype' => 'template', 'sourceid' => $template->get('id'), 'frameworkid' => $framework->get('id'),
            'mode' => bulk::MODE_SUMMARIES, 'includefeedback' => 1];
        $old = bulk::build($options);
        $this->waitForSecond();
        bulk::build($options);
        $DB->set_field('files', 'timecreated', time() - (bulk::KEEP_DAYS + 1) * DAYSECS, ['id' => $old->get_id()]);

        $this->assertEquals(1, bulk::cleanup());
        $this->assertCount(1, bulk::get_files((int) get_admin()->id));
    }

    /**
     * Learners from a course, or every course in a category (optionally with its subcategories).
     */
    public function test_course_and_category_sources(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $qual = $gen->create_category(['name' => 'Cert III']);
        $sub = $gen->create_category(['name' => 'Electives', 'parent' => $qual->id]);
        $core = $gen->create_course(['category' => $qual->id, 'fullname' => 'Core unit']);
        $elective = $gen->create_course(['category' => $sub->id, 'fullname' => 'Elective unit']);
        $ava = $gen->create_and_enrol($core, 'student', ['firstname' => 'Ava', 'lastname' => 'Core']);
        $ben = $gen->create_and_enrol($elective, 'student', ['firstname' => 'Ben', 'lastname' => 'Elective']);
        $gen->enrol_user($ava->id, $elective->id, 'student');
        $gen->create_and_enrol($core, 'editingteacher');
        $gen->create_and_enrol(
            $core,
            'student',
            ['firstname' => 'Sue', 'lastname' => 'Suspended'],
            'manual',
            0,
            0,
            ENROL_USER_SUSPENDED
        );

        $names = fn(array $users) => array_values(array_map('fullname', $users));
        $this->assertEquals(['Ava Core'], $names(bulk::get_learners('course', (int) $core->id)));
        $this->assertEquals(['Ava Core', 'Ben Elective'], $names(bulk::get_learners('category', (int) $qual->id)));
        $this->assertEquals(['Ava Core'], $names(bulk::get_learners('category', (int) $qual->id, false)));
        $this->assertEquals([], bulk::get_learners('course', (int) SITEID));

        $this->assertEquals('Core unit', bulk::get_source_name('course', (int) $core->id));
        $this->assertEquals('Cert III (category and subcategories)', bulk::get_source_name('category', (int) $qual->id));
        $this->assertEquals('Cert III (category)', bulk::get_source_name('category', (int) $qual->id, false));
    }
}
