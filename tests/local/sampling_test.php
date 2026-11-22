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

#[\PHPUnit\Framework\Attributes\CoversClass(sampling::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\report_evidencereporter\event\sample_created::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\report_evidencereporter\event\sample_item_swapped::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\report_evidencereporter\form\sample_form::class)]
/**
 * Tests for validation sampling.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\sampling
 * @covers     \report_evidencereporter\event\sample_created
 * @covers     \report_evidencereporter\event\sample_item_swapped
 * @covers     \report_evidencereporter\form\sample_form
 */
final class sampling_test extends \advanced_testcase {
    /**
     * Static caches hold IDs, which the test database reuses between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        feedback_visibility::reset_cache();
        attempts::reset_cache();
        activity_grade::reset_cache();
    }

    /**
     * Framework "WHS" with units U1 and U2 (U1 has a sub-competency). Course C1 has 12 learners rated in U1 by two
     * assessors (8 by Alex, 4 by Bo; 9 competent, 3 not yet), and 3 in U2. Course C2, in another category, has 2 in U1.
     *
     * @return array
     */
    private function setup_data(): array {
        global $DB;
        $gen = $this->getDataGenerator();
        /** @var \core_competency_generator $cgen */
        $cgen = $gen->get_plugin_generator('core_competency');
        $scale = $gen->create_scale(['scale' => 'Not yet competent,Competent']);
        $framework = $cgen->create_framework(['shortname' => 'WHS', 'idnumber' => 'WHS', 'scaleid' => $scale->id,
            'scaleconfiguration' => json_encode([['scaleid' => $scale->id], ['id' => 2, 'scaledefault' => 1,
            'proficient' => 1]])]);
        $u1 = $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'shortname' => 'Hazards',
            'idnumber' => 'U1']);
        $u1a = $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'parentid' => $u1->get('id'),
            'idnumber' => 'U1.1']);
        $u2 = $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'shortname' => 'Risks',
            'idnumber' => 'U2']);
        $cat1 = $gen->create_category();
        $cat2 = $gen->create_category();
        $c1 = $gen->create_course(['shortname' => 'C1', 'category' => $cat1->id]);
        $c2 = $gen->create_course(['shortname' => 'C2', 'category' => $cat2->id]);
        foreach ([$c1, $c2] as $course) {
            $cgen->create_course_competency(['courseid' => $course->id, 'competencyid' => $u1->get('id')]);
        }
        $alex = $gen->create_user(['firstname' => 'Alex', 'lastname' => 'Assessor']);
        $bo = $gen->create_user(['firstname' => 'Bo', 'lastname' => 'Assessor']);

        $rate = function (
            \stdClass $learner,
            \core_competency\competency $unit,
            \stdClass $course,
            int $grade,
            \stdClass $assessor,
            int $when
        ) use (
            $cgen,
            $DB
) {
            $cgen->create_user_competency_course(['userid' => $learner->id, 'competencyid' => $unit->get('id'),
                'courseid' => $course->id, 'grade' => $grade, 'proficiency' => (int) ($grade === 2)]);
            $DB->set_field('competency_usercompcourse', 'timemodified', $when, ['userid' => $learner->id,
                'competencyid' => $unit->get('id'), 'courseid' => $course->id]);
            $uc = \core_competency\user_competency::get_record(['userid' => $learner->id,
                'competencyid' => $unit->get('id')]) ?: $cgen->create_user_competency(['userid' => $learner->id,
                'competencyid' => $unit->get('id')]);
            $cgen->create_evidence(['usercompetencyid' => $uc->get('id'), 'grade' => $grade,
                'action' => \core_competency\evidence::ACTION_OVERRIDE, 'actionuserid' => $assessor->id,
                'contextid' => \context_course::instance($course->id)->id]);
        };
        $learners = [];
        for ($i = 1; $i <= 12; $i++) {
            $learner = $gen->create_user(['username' => 'l' . $i]);
            $gen->enrol_user($learner->id, $c1->id, 'student');
            $rate($learner, $u1, $c1, $i <= 9 ? 2 : 1, $i <= 8 ? $alex : $bo, $i <= 6 ? 1000000 : 2000000);
            if ($i <= 3) {
                $rate($learner, $u2, $c1, 2, $alex, 2000000);
            }
            $learners[$i] = $learner;
        }
        foreach ([1, 2] as $i) {
            $gen->enrol_user($learners[$i]->id, $c2->id, 'student');
            $rate($learners[$i], $u1, $c2, 2, $bo, 2000000);
        }
        // A sub-competency rating alone isn't a judgement of the unit, but it still counts as one in that unit.
        $extra = $gen->create_user(['username' => 'sub']);
        $cgen->create_user_competency_course(['userid' => $extra->id, 'competencyid' => $u1a->get('id'),
            'courseid' => $c1->id, 'grade' => 2, 'proficiency' => 1]);
        return compact('framework', 'u1', 'u2', 'cat1', 'cat2', 'c1', 'c2', 'alex', 'bo', 'learners', 'extra');
    }

    /**
     * The population: units and their sub-competencies, by course or category, with results, assessors and filters.
     */
    public function test_population(): void {
        $this->resetAfterTest();
        $d = $this->setup_data();
        $f = fn(array $filters) => sampling::normalise_filters($filters + ['frameworkid' => $d['framework']->get('id')]);

        $population = sampling::get_population($f(['courseid' => $d['c1']->id]));
        $this->assertCount(16, $population);
        $key = $d['learners'][1]->id . '-' . $d['u1']->get('id') . '-' . $d['c1']->id;
        $this->assertEquals('Competent', $population[$key]->result);
        $this->assertEquals($d['alex']->id, $population[$key]->assessorid);
        $this->assertEquals(
            'Not yet rated',
            $population[$d['extra']->id . '-' . $d['u1']->get('id') . '-' . $d['c1']->id]->result
        );

        $this->assertCount(13, sampling::get_population($f(['courseid' => $d['c1']->id, 'unitid' => $d['u1']->get('id')])));
        $this->assertCount(2, sampling::get_population($f(['categoryid' => $d['cat2']->id])));
        $this->assertCount(3, sampling::get_population($f(['courseid' => $d['c1']->id, 'result' => 'notproficient'])));
        // Learners 7-12, plus the sub-competency judgement made just now.
        $this->assertCount(7, sampling::get_population($f(['courseid' => $d['c1']->id, 'from' => 1500000,
            'unitid' => $d['u1']->get('id')])));
        // Without a framework: those used in the course.
        $this->assertCount(16, sampling::get_population(sampling::normalise_filters(['courseid' => $d['c1']->id])));
        $this->assertSame([], sampling::get_population(sampling::normalise_filters([])));
    }

    /**
     * A draw is recorded, reproducible from its draw code, and spread across assessors.
     */
    public function test_draw_reproducible_and_spread(): void {
        global $DB;
        $this->resetAfterTest();
        $d = $this->setup_data();
        $this->setAdminUser();
        $filters = ['courseid' => $d['c1']->id, 'unitid' => $d['u1']->get('id'), 'spread' => 'assessor', 'size' => 6];

        $sink = $this->redirectEvents();
        $id = sampling::draw('Term 3', $filters, 4242);
        $events = $sink->get_events();
        $sink->close();
        $this->assertInstanceOf(\report_evidencereporter\event\sample_created::class, $events[0]);
        $this->assertEquals(['population' => 13, 'size' => 6], $events[0]->other);

        $sample = $DB->get_record(sampling::TABLE, ['id' => $id]);
        $this->assertEquals(4242, $sample->seed);
        $this->assertEquals(13, $sample->population);
        $this->assertEquals(40, strlen($sample->populationhash));

        $keys = fn(int $sampleid) => array_map(
            fn($i) => $i->userid . '-' . $i->competencyid,
            sampling::get_items($sampleid)
        );
        $this->assertCount(6, $keys($id));
        $this->assertEquals($keys($id), $keys(sampling::draw('Again', $filters, 4242)));

        // Shared across Alex, Bo and "No assessor recorded" (the sub-competency-only judgement, which has just one):
        // its unused share goes to the others.
        $groups = array_count_values(array_map(fn($i) => $i->groupkey, sampling::get_items($id)));
        $this->assertEquals(['Alex Assessor' => 3, 'Bo Assessor' => 2, 'No assessor recorded' => 1], $groups);

        // Per group: up to N each.
        $per = sampling::draw('', ['pergroup' => 1, 'size' => 3] + $filters, 1);
        $groups = array_count_values(array_map(fn($i) => $i->groupkey, sampling::get_items($per)));
        $this->assertEquals(['Alex Assessor' => 3, 'Bo Assessor' => 3, 'No assessor recorded' => 1], $groups);

        // No spread: exactly the size asked for.
        $this->assertCount(5, sampling::get_items(sampling::draw('', ['spread' => 'none', 'size' => 5] + $filters)));
    }

    /**
     * A swap keeps the swapped item and its reason, and draws a replacement from the same group.
     */
    public function test_swap(): void {
        $this->resetAfterTest();
        $d = $this->setup_data();
        $this->setAdminUser();
        $id = sampling::draw('', ['courseid' => $d['c1']->id, 'unitid' => $d['u1']->get('id'), 'spread' => 'result',
            'size' => 2], 7);
        $items = sampling::get_items($id);
        $out = $items[0];

        $sink = $this->redirectEvents();
        $replacement = sampling::swap((int) $out->id, 'Learner withdrew');
        $events = $sink->get_events();
        $sink->close();

        $this->assertNotNull($replacement);
        $this->assertEquals($out->groupkey, $replacement->groupkey);
        $this->assertEquals('replacement', $replacement->reason);
        $this->assertEquals($out->id, $replacement->replacesid);
        $this->assertInstanceOf(\report_evidencereporter\event\sample_item_swapped::class, $events[0]);
        $this->assertEquals($out->userid, $events[0]->relateduserid);

        $all = sampling::get_items($id);
        $this->assertCount(3, $all);
        $this->assertCount(2, sampling::get_items($id, false));
        $this->assertEquals('Learner withdrew', $all[0]->removedreason);
        $this->assertNotContains(
            $all[0]->userid . '-' . $all[0]->competencyid,
            array_map(fn($i) => $i->userid . '-' . $i->competencyid, sampling::get_items($id, false))
        );

        // When the group runs dry it falls back to any other judgement, and then to none.
        $replacements = 0;
        for ($n = 0; $n < 15; $n++) {
            $active = sampling::get_items($id, false);
            if (!$active) {
                break;
            }
            $replacements += (int) (bool) sampling::swap((int) $active[0]->id, 'Test ' . $n);
        }
        $this->assertSame([], sampling::get_items($id, false));
        // 13 judgements: 2 drawn, then 11 replacements (one above, ten here).
        $this->assertEquals(10, $replacements);
    }

    /**
     * The register lists every judgement, swaps included, with validator columns; the record has the draw code.
     */
    public function test_register_and_record(): void {
        $this->resetAfterTest();
        $d = $this->setup_data();
        $this->setAdminUser();
        $id = sampling::draw('Audit', ['categoryid' => $d['cat1']->id, 'unitid' => $d['u1']->get('id'), 'size' => 3,
            'spread' => 'none'], 99);
        $first = sampling::get_items($id)[0];
        sampling::swap((int) $first->id, 'Assessor is on the panel');

        $rows = array_map(
            fn($line) => str_getcsv($line, ',', '"', ''),
            preg_split('/\r\n/', trim(sampling::register_csv($id, ['redactname' => true])))
        );
        $this->assertCount(5, $rows);
        $this->assertEquals('Validator findings', $rows[0][14]);
        $this->assertStringStartsWith('Swapped out: Assessor is on the panel', $rows[1][12]);
        $this->assertEquals('Replacement for #1', $rows[4][11]);
        $this->assertStringNotContainsString(fullname(\core_user::get_user($first->userid)), implode(',', $rows[1]));

        $record = sampling::record_text($id);
        $this->assertStringContainsString('99 (population check ', $record);
        $this->assertStringContainsString('3 of 13 eligible judgements', $record);
        $this->assertStringContainsString('#1 swapped out by Admin User', $record);
        $this->assertStringContainsString('Assessor is on the panel. Replacement: #4.', $record);
    }

    /**
     * A bulk download from a sample: one pack per sampled judgement, the register and the record.
     */
    public function test_bulk_build(): void {
        $this->resetAfterTest();
        $d = $this->setup_data();
        $this->setAdminUser();
        $id = sampling::draw('Audit', ['courseid' => $d['c1']->id, 'frameworkid' => $d['framework']->get('id'),
            'size' => 4, 'spread' => 'none'], 5);
        sampling::swap((int) sampling::get_items($id)[0]->id, 'Withdrew');

        $file = bulk::build(['sampleid' => $id, 'sourcetype' => 'course', 'sourceid' => 0, 'frameworkid' => 0,
            'mode' => bulk::MODE_SUMMARIES, 'includefeedback' => 1]);
        $path = make_request_directory() . '/s.zip';
        $file->copy_content_to($path);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $this->assertContains('Sample register.csv', $names);
        $this->assertContains('Sampling record.txt', $names);
        $this->assertCount(4, preg_grep('/\.pdf$/', $names));
        $register = $zip->getFromName('Sample register.csv');
        $this->assertEquals(4, preg_match_all('/\.pdf"/', $register));
        $this->assertStringContainsString('validation sample', $zip->getFromName('README.txt'));
        $zip->close();
        $this->assertStringStartsWith('Evidence summaries - Audit', $file->get_filename());
    }

    /**
     * The form's choices become filters.
     */
    public function test_form_filters(): void {
        $filters = \report_evidencereporter\form\sample_form::to_filters((object) ['sourcetype' => 'category',
            'courseid' => 5, 'categoryid' => 3, 'subcategories' => 0, 'covering' => 'unit-9', 'from' => 0, 'to' => 100,
            'result' => 'proficient', 'marks' => 'borderline', 'markmin' => '', 'markmax' => '', 'spread' => 'markband',
            'size' => 8, 'pergroup' => 1]);
        $this->assertEquals(0, $filters['courseid']);
        $this->assertEquals(3, $filters['categoryid']);
        $this->assertEquals(9, $filters['unitid']);
        $this->assertEquals([50, 64.999], [$filters['markmin'], $filters['markmax']]);
        $custom = \report_evidencereporter\form\sample_form::to_filters((object) ['sourcetype' => 'course',
            'courseid' => 5, 'covering' => 'framework-2', 'from' => 0, 'to' => 0, 'result' => 'any', 'marks' => 'custom',
            'markmin' => '40', 'markmax' => '', 'spread' => 'none', 'size' => 8]);
        $this->assertEquals([5, 2, 40.0, null], [$custom['courseid'], $custom['frameworkid'], $custom['markmin'],
            $custom['markmax']]);
    }
}
