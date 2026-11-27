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

namespace report_evidencereporter;

use report_evidencereporter\local\feedback_surveys;
use report_evidencereporter\local\tool_details;

#[\PHPUnit\Framework\Attributes\CoversClass(\backup_report_evidencereporter_plugin::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_report_evidencereporter_plugin::class)]
/**
 * Backup and restore of Evidence Reporter's activity settings.
 *
 * @package    report_evidencereporter
 * @category   test
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_report_evidencereporter_plugin
 * @covers     \restore_report_evidencereporter_plugin
 */
final class backup_restore_test extends \advanced_testcase {
    /**
     * Back up a course and restore it as a new course.
     *
     * @param \stdClass $course
     * @return int The new course's id
     */
    private function backup_and_restore(\stdClass $course): int {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();
        $newcourseid = \restore_dbops::create_new_course(
            $course->fullname . ' (copy)',
            $course->shortname . '_copy',
            $course->category
        );
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();
        return (int) $newcourseid;
    }

    /**
     * Tool details and a survey's assessment come across with the activities, pointing at the restored copies.
     */
    public function test_settings_restored(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $assign = $gen->create_module('assign', ['course' => $course->id, 'name' => 'AT1 Report']);
        $survey = $gen->create_module('feedback', ['course' => $course->id, 'name' => 'AT1 feedback']);
        tool_details::save((int) $assign->cmid, (object) ['toolversion' => 'v3.1', 'reviewdate' => 1795000000,
            'reviewedby' => 'Bobby Doona', 'conditions' => 'Simulated workplace']);
        feedback_surveys::save_from_form((object) ['coursemodule' => $survey->cmid, 'modulename' => 'feedback',
            feedback_surveys::FIELD => $assign->cmid]);

        $newcourseid = $this->backup_and_restore($course);
        $modinfo = get_fast_modinfo($newcourseid);
        $newassign = array_values($modinfo->get_instances_of('assign'))[0];
        $newsurvey = array_values($modinfo->get_instances_of('feedback'))[0];
        $this->assertNotEquals($assign->cmid, $newassign->id);

        $tool = tool_details::get((int) $newassign->id);
        $this->assertSame('v3.1', $tool->toolversion);
        $this->assertEquals(1795000000, $tool->reviewdate);
        $this->assertSame('Bobby Doona', $tool->reviewedby);
        $this->assertSame('Simulated workplace', $tool->conditions);
        $this->assertSame([], tool_details::get_missing_fields((int) $newassign->id));
        $this->assertSame([(int) $newsurvey->id => (int) $newassign->id], feedback_surveys::get_links($newcourseid));
        // The original keeps its own.
        $this->assertSame([(int) $survey->cmid => (int) $assign->cmid], feedback_surveys::get_links((int) $course->id));
        $this->assertSame(2, $DB->count_records(tool_details::TABLE));
    }

    /**
     * Duplicating in the same course: an assessment brings its tool details; a survey keeps its assessment.
     */
    public function test_duplicate(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $assign = $gen->create_module('assign', ['course' => $course->id]);
        $survey = $gen->create_module('feedback', ['course' => $course->id]);
        tool_details::save((int) $assign->cmid, (object) ['toolversion' => 'v2', 'reviewdate' => 1795000000,
            'reviewedby' => 'Bobby Doona']);
        feedback_surveys::save_from_form((object) ['coursemodule' => $survey->cmid, 'modulename' => 'feedback',
            feedback_surveys::FIELD => $assign->cmid]);
        $modinfo = get_fast_modinfo($course);
        $copy = duplicate_module($course, $modinfo->get_cm($assign->cmid));
        $this->assertSame('v2', tool_details::get((int) $copy->id)->toolversion);
        $surveycopy = duplicate_module($course, get_fast_modinfo($course)->get_cm($survey->cmid));
        $this->assertSame((int) $assign->cmid, feedback_surveys::get_links((int) $course->id)[$surveycopy->id]);
    }
}
