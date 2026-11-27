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

namespace report_evidencereporter;

/**
 * Event observers for Evidence Reporter.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * An activity was deleted: remove its assessment tool details.
     *
     * @param \core\event\course_module_deleted $event
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        local\tool_details::delete((int) $event->objectid);
        local\reasonable_adjustments::delete_for_activity((int) $event->objectid);
        local\attempt_snapshots::delete_for_activity((int) $event->objectid);
        local\declarations::delete_for_activity((int) $event->objectid);
        local\release_hold::delete_for_activity((int) $event->objectid);
        local\before_marking::delete_for_activity((int) $event->objectid);
    }

    /**
     * Learners joined, left or changed role: re-check the course's release holds (in the background).
     *
     * @param \core\event\base $event
     */
    public static function learners_changed(\core\event\base $event): void {
        try {
            if ($event->courseid && $event->courseid != SITEID && local\release_hold::enabled()) {
                task\update_holds::queue((int) $event->courseid);
            }
        } catch (\Throwable $e) {
            debugging('Evidence Reporter release hold: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * A grade item was added or changed: hold a new item, or hide a held one again if someone showed it.
     *
     * @param \core\event\base $event grade_item_created or grade_item_updated
     */
    public static function grade_item_changed(\core\event\base $event): void {
        global $CFG, $DB;
        if (local\release_hold::is_updating() || !local\release_hold::enabled()) {
            return;
        }
        try {
            require_once($CFG->libdir . '/gradelib.php');
            $item = \grade_item::fetch(['id' => (int) $event->objectid]);
            if (!$item || $item->itemtype !== 'mod' || !local\reasonable_adjustments::supports((string) $item->itemmodule)) {
                return;
            }
            $row = $DB->get_record(local\release_hold::TABLE, ['gradeitemid' => $item->id, 'userid' => 0]);
            if ($row && ($row->state !== local\release_hold::HELD || (int) $item->hidden === 1)) {
                return;
            }
            $cm = get_coursemodule_from_instance($item->itemmodule, $item->iteminstance, $item->courseid);
            $modinfo = $cm ? get_fast_modinfo($cm->course, -1) : null;
            // An activity still being added isn't in the course yet: it's checked when it's created.
            if ($modinfo && isset($modinfo->get_cms()[$cm->id])) {
                local\release_hold::update($modinfo->get_cm($cm->id));
            }
        } catch (\Throwable $e) {
            debugging('Evidence Reporter release hold: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * An activity was added, or its settings changed (who can access it, for example): check its release hold.
     *
     * @param \core\event\base $event course_module_created or course_module_updated
     */
    public static function course_module_updated(\core\event\base $event): void {
        try {
            if (!local\release_hold::enabled()) {
                return;
            }
            $cm = get_fast_modinfo($event->courseid, -1)->get_cm((int) $event->objectid);
            if (local\reasonable_adjustments::supports($cm->modname)) {
                local\release_hold::update($cm);
            }
        } catch (\Throwable $e) {
            debugging('Evidence Reporter release hold: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * A grade was saved: snapshot a Not satisfactory result in an activity without attempts of its own.
     *
     * @param \core\event\user_graded $event
     */
    public static function user_graded(\core\event\user_graded $event): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        try {
            $itemid = (int) ($event->other['itemid'] ?? 0);
            $item = \grade_item::fetch(['id' => $itemid]);
            if (!$item || $item->itemtype !== 'mod' || !local\attempt_snapshots::tracks((string) $item->itemmodule)) {
                return;
            }
            $cm = get_coursemodule_from_instance($item->itemmodule, $item->iteminstance, $item->courseid);
            if (!$cm) {
                return;
            }
            $cminfo = get_fast_modinfo($cm->course)->get_cm($cm->id);
            $main = local\attempt_snapshots::get_grade_item($cminfo);
            if (!$main || (int) $main->id !== (int) $item->id) {
                return;
            }
            $grade = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $event->relateduserid]);
            local\attempt_snapshots::graded(
                $cminfo,
                (int) $event->relateduserid,
                $item,
                $grade && $grade->finalgrade !== null ? (float) $grade->finalgrade : null,
                (int) ($grade->usermodified ?? $event->userid),
                (int) $event->timecreated
            );
        } catch (\Throwable $e) {
            // Never stop a grade being saved.
            debugging('Evidence Reporter attempt snapshot: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * A forum post: an assessor's reply to a learner is feedback for the learner's open attempt.
     *
     * @param \core\event\base $event post_created or post_updated
     */
    public static function forum_post(\core\event\base $event): void {
        global $DB;
        try {
            $post = $DB->get_record('forum_posts', ['id' => $event->objectid], 'id, parent, userid');
            if (!$post || !$post->parent) {
                return;
            }
            $learner = (int) $DB->get_field('forum_posts', 'userid', ['id' => $post->parent]);
            if ($learner && $learner !== (int) $post->userid) {
                local\attempt_snapshots::feedback_given((int) $event->contextinstanceid, $learner);
            }
        } catch (\Throwable $e) {
            debugging('Evidence Reporter attempt snapshot: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * A comment on a database entry: feedback for the entry's author's open attempt.
     *
     * @param \core\event\base $event
     */
    public static function data_comment(\core\event\base $event): void {
        global $DB;
        try {
            $recordid = (int) ($event->other['itemid'] ?? 0);
            $learner = (int) $DB->get_field('data_records', 'userid', ['id' => $recordid]);
            if ($learner && $learner !== (int) $event->userid) {
                local\attempt_snapshots::feedback_given((int) $event->contextinstanceid, $learner);
            }
        } catch (\Throwable $e) {
            debugging('Evidence Reporter attempt snapshot: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * A teacher updated a learner's checklist (ticks and comments): feedback for the learner's open attempt.
     *
     * @param \core\event\base $event
     */
    public static function checklist_checks(\core\event\base $event): void {
        try {
            if ($event->relateduserid) {
                local\attempt_snapshots::feedback_given((int) $event->contextinstanceid, (int) $event->relateduserid);
            }
        } catch (\Throwable $e) {
            debugging('Evidence Reporter attempt snapshot: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
