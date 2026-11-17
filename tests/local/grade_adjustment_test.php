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

#[\PHPUnit\Framework\Attributes\CoversClass(grade_adjustment::class)]
/**
 * Tests for gradebook adjustments (penalties in an assessment's own category).
 *
 * @package    report_evidencereporter
 * @category   test
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\grade_adjustment
 */
final class grade_adjustment_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/gradelib.php');
        grade_adjustment::reset_cache();
        activity_grade::reset_cache();
        feedback_visibility::reset_cache();
    }

    /**
     * A course with an assignment in its own category, calculated as assignment + late + misconduct penalties.
     *
     * @return \stdClass {course, cm, user, item, catitem, late, misconduct, category}
     */
    private function setup_course(): \stdClass {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $user = $gen->create_and_enrol($course, 'student');
        $assign = $gen->create_module('assign', ['course' => $course->id, 'name' => 'AT1 Essay', 'grade' => 100]);
        $category = $gen->create_grade_category(['courseid' => $course->id, 'fullname' => 'AT1']);

        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id,
            'courseid' => $course->id]);
        $item->set_parent($category->id);
        $item->idnumber = 'AT1';
        $item->update();

        $penalty = fn($name, $idnumber) => new \grade_item($gen->create_grade_item([
            'courseid' => $course->id, 'itemtype' => 'manual', 'itemname' => $name, 'idnumber' => $idnumber,
            'categoryid' => $category->id, 'grademin' => -100, 'grademax' => 0,
        ]), false);
        $late = \grade_item::fetch(['id' => $penalty('Late penalty', 'LATE')->id]);
        $misconduct = \grade_item::fetch(['id' => $penalty('Academic misconduct penalty', 'MISC')->id]);

        $catitem = \grade_category::fetch(['id' => $category->id])->load_grade_item();
        $catitem->set_calculation('=[[AT1]]+[[LATE]]+[[MISC]]');

        grade_update(
            'mod/assign',
            $course->id,
            'mod',
            'assign',
            $assign->id,
            0,
            [$user->id => ['userid' => $user->id, 'rawgrade' => 80]]
        );
        grade_regrade_final_grades($course->id);

        return (object) ['course' => $course, 'cm' => get_coursemodule_from_instance('assign', $assign->id),
            'user' => $user, 'item' => $item, 'catitem' => $catitem, 'late' => $late, 'misconduct' => $misconduct,
            'category' => $category];
    }

    /**
     * Evidence row for the assignment.
     *
     * @param \stdClass $s
     * @return \stdClass
     */
    private function row(\stdClass $s): \stdClass {
        $grade = \grade_grade::fetch(['itemid' => $s->item->id, 'userid' => $s->user->id]);
        return (object) ['gradeitemid' => $s->item->id, 'finalgrade' => $grade->finalgrade, 'islatest' => 1,
            'cmid' => $s->cm->id, 'modname' => 'assign', 'userid' => $s->user->id, 'gradeitemhidden' => 0,
            'gradehidden' => 0];
    }

    /**
     * Penalise the learner and recalculate.
     *
     * @param \stdClass $s
     * @param \grade_item $item
     * @param float $value
     * @param string $feedback
     */
    private function penalise(\stdClass $s, \grade_item $item, float $value, string $feedback): void {
        $item->update_final_grade($s->user->id, $value, 'test', $feedback, FORMAT_PLAIN);
        $s->catitem->force_regrading();
        grade_regrade_final_grades($s->course->id);
        grade_adjustment::reset_cache();
    }

    public function test_no_penalty_no_adjustment(): void {
        $s = $this->setup_course();
        $this->assertNull(grade_adjustment::for_row($this->row($s)));
        $this->assertSame('', grade_adjustment::row_html($this->row($s)));
    }

    public function test_late_penalty(): void {
        $s = $this->setup_course();
        $this->penalise($s, $s->late, -10, 'Submitted 3 days late');

        $adj = grade_adjustment::for_row($this->row($s));
        $this->assertNotNull($adj);
        $this->assertStringContainsString('70', $adj->finalgrade);
        $this->assertCount(1, $adj->reasons);
        $this->assertEquals('Late penalty', $adj->reasons[0]->name);
        $this->assertStringContainsString('-10', $adj->reasons[0]->value);
        $this->assertStringContainsString('Submitted 3 days late', $adj->reasons[0]->feedback);
        $this->assertEquals('AT1 Essay + Late penalty + Academic misconduct penalty', $adj->calculation);
        $this->assertFalse($adj->integrity);
        $this->assertFalse($adj->overridden);

        $html = grade_adjustment::to_html($adj);
        $this->assertStringContainsString('Final result', $html);
        $this->assertStringContainsString('Adjusted', $html);
        $this->assertStringNotContainsString('integrity', $html);
    }

    public function test_misconduct_penalty_is_flagged(): void {
        $s = $this->setup_course();
        $this->penalise($s, $s->misconduct, -40, 'Plagiarism finding, see case 12');
        $adj = grade_adjustment::for_row($this->row($s));
        $this->assertTrue($adj->integrity);
        $this->assertStringContainsString('40', $adj->finalgrade);
        $this->assertStringContainsString('Academic integrity adjustment', grade_adjustment::to_html($adj));

        // Words come from settings.
        set_config('integritywords', 'contract cheating', 'report_evidencereporter');
        $this->assertFalse(grade_adjustment::for_row($this->row($s))->integrity);
    }

    public function test_detail_levels(): void {
        $s = $this->setup_course();
        $this->penalise($s, $s->misconduct, -40, 'Plagiarism finding');

        set_config('adjustmentdetail', grade_adjustment::DETAIL_FLAG, 'report_evidencereporter');
        $adj = grade_adjustment::for_row($this->row($s));
        $this->assertNotNull($adj);
        $this->assertEmpty($adj->reasons);
        $this->assertFalse($adj->integrity);
        $this->assertSame('', $adj->calculation);
        $html = grade_adjustment::to_html($adj);
        $this->assertStringContainsString('Adjusted', $html);
        $this->assertStringNotContainsString('Plagiarism', $html);
        $this->assertStringNotContainsString('misconduct', $html);

        set_config('adjustmentdetail', grade_adjustment::DETAIL_OFF, 'report_evidencereporter');
        $this->assertNull(grade_adjustment::for_row($this->row($s)));
    }

    public function test_override(): void {
        $s = $this->setup_course();
        $s->catitem->update_final_grade($s->user->id, 50, 'test', 'Academic board decision', FORMAT_PLAIN);
        grade_adjustment::reset_cache();
        $adj = grade_adjustment::for_row($this->row($s));
        $this->assertTrue($adj->overridden);
        $this->assertStringContainsString('50', $adj->finalgrade);
        $this->assertStringContainsString('Academic board decision', $adj->overridefeedback);
        $this->assertSame('', $adj->calculation);
        $this->assertStringContainsString('changed by hand', grade_adjustment::to_html($adj));
    }

    public function test_shared_category_is_ignored(): void {
        $s = $this->setup_course();
        $this->penalise($s, $s->late, -10, 'Late');
        $other = $this->getDataGenerator()->create_module('assign', ['course' => $s->course->id, 'name' => 'AT2']);
        $otheritem = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $other->id,
            'courseid' => $s->course->id]);
        $otheritem->set_parent($s->category->id);
        grade_adjustment::reset_cache();
        $this->assertNull(grade_adjustment::for_row($this->row($s)));
    }

    public function test_redaction_and_hidden(): void {
        $s = $this->setup_course();
        $this->penalise($s, $s->late, -10, 'Late for ' . fullname($s->user));
        $identity = identity::from_flags(\core_user::get_user($s->user->id), ['redactname' => 1]);
        $adj = grade_adjustment::for_row($this->row($s), $identity);
        $this->assertStringNotContainsString(fullname($s->user), $adj->reasons[0]->feedback);

        // A learner can't see a hidden penalty.
        $s->late->set_hidden(1);
        grade_adjustment::reset_cache();
        $this->setUser($s->user);
        feedback_visibility::reset_cache();
        $adj = grade_adjustment::for_row($this->row($s));
        $this->assertNotNull($adj);
        $this->assertEmpty($adj->reasons);
    }
}
