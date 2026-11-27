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

use cm_info;
use grade_grade;
use grade_item;
use stdClass;

/**
 * Holds an assessment's grades from release until RA and LLN are recorded for every learner (see
 * {@see declarations}), for grade items that count toward the course's final grade.
 *
 * Held means hidden in the gradebook, which also hides assignment feedback. Until every learner is recorded, the whole
 * grade item is hidden; the hidden setting it had is kept and put back when the hold is lifted (if a teacher shows the
 * item while it's held, it's hidden again, and shown when the hold lifts). After that, a learner who joins later and
 * isn't recorded has only their own grade held, so other learners' released grades stay visible.
 *
 * Assessments that already had grades when holds began are exempt as a whole (their grades stay as learners see them),
 * but learners without a grade in them are still held one by one.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class release_hold {
    /** @var string The table. */
    public const TABLE = 'report_evidencereporter_hold';

    /** @var string Held. */
    public const HELD = 'held';
    /** @var string Every learner was recorded once; later learners are held one by one. */
    public const CLEARED = 'cleared';
    /** @var string Had grades before holds began; learners without a grade are held one by one. */
    public const EXEMPT = 'exempt';

    /** @var bool True while this class changes grade items (so its own changes aren't taken as a teacher's). */
    private static bool $updating = false;

    /**
     * Is holding turned on (site setting, on by default)?
     *
     * @return bool
     */
    public static function enabled(): bool {
        $value = get_config('report_evidencereporter', 'releasehold');
        return $value === false || $value === null || (bool) $value;
    }

    /**
     * Is this class changing grade items right now?
     *
     * @return bool
     */
    public static function is_updating(): bool {
        return self::$updating;
    }

    /**
     * An activity's grade items.
     *
     * @param cm_info $cm
     * @return grade_item[]
     */
    public static function get_grade_items(cm_info $cm): array {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $items = grade_item::fetch_all(['itemtype' => 'mod', 'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance, 'courseid' => $cm->course]) ?: [];
        return array_values(array_filter($items, fn($item) => !$item->is_outcome_item()));
    }

    /**
     * Does a grade item count toward the course's final grade? Not when it has no grade (none or text), has no weight in
     * a Natural or Weighted mean category, or sits in a category that itself doesn't count.
     *
     * @param grade_item $item
     * @return bool
     */
    public static function counts_toward_final(grade_item $item): bool {
        if ((int) $item->gradetype === GRADE_TYPE_NONE || (int) $item->gradetype === GRADE_TYPE_TEXT) {
            return false;
        }
        $current = $item;
        $category = $item->get_parent_category();
        for ($depth = 0; $depth < 50 && $category; $depth++) {
            $aggregation = (int) $category->aggregation;
            if ($aggregation === GRADE_AGGREGATE_SUM) {
                $extracredit = (float) $current->aggregationcoef > 0;
                if (!empty($current->weightoverride) && (float) $current->aggregationcoef2 <= 0 && !$extracredit) {
                    return false;
                }
                if (empty($current->weightoverride) && (float) $current->grademax <= 0 && !$extracredit) {
                    return false;
                }
            } else if ($aggregation === GRADE_AGGREGATE_WEIGHTED_MEAN && (float) $current->aggregationcoef <= 0) {
                return false;
            }
            if (empty($category->parent)) {
                return true;
            }
            // A category's own grade item counts toward the category above it (its "parent category" is itself).
            $current = $category->load_grade_item();
            $category = $category->get_parent_category();
            if (!$current) {
                return true;
            }
        }
        return true;
    }

    /**
     * Where things stand for an activity, for the RA and LLN page and the marking page.
     *
     * @param cm_info $cm
     * @return stdClass applies (bool: holding is on and a grade item counts), held (bool: grades are held now),
     *                  learnersheld (int: learners held one by one), exempt (bool)
     */
    public static function status(cm_info $cm): stdClass {
        global $DB;
        $result = (object) ['applies' => false, 'held' => false, 'learnersheld' => 0, 'exempt' => false];
        if (!self::enabled() || !declarations::available($cm)) {
            return $result;
        }
        foreach (self::get_grade_items($cm) as $item) {
            if (self::counts_toward_final($item)) {
                $result->applies = true;
            }
        }
        foreach ($DB->get_records(self::TABLE, ['cmid' => $cm->id]) as $row) {
            if ((int) $row->userid === 0) {
                $result->held = $result->held || $row->state === self::HELD;
                $result->exempt = $result->exempt || $row->state === self::EXEMPT;
            } else {
                $result->learnersheld++;
            }
        }
        return $result;
    }

    /**
     * Hold or release an activity's grades, as its RA and LLN records now stand.
     *
     * @param cm_info $cm
     */
    public static function update(cm_info $cm): void {
        global $DB;
        $rows = [];
        foreach ($DB->get_records(self::TABLE, ['cmid' => $cm->id]) as $row) {
            $rows[(int) $row->gradeitemid][(int) $row->userid] = $row;
        }
        $items = self::get_grade_items($cm);
        $active = self::enabled() && declarations::available($cm)
            && array_filter($items, fn($item) => self::counts_toward_final($item));
        $outstanding = $active ? declarations::get_outstanding($cm) : [];
        $seen = [];
        self::$updating = true;
        try {
            foreach ($items as $item) {
                $itemid = (int) $item->id;
                $seen[$itemid] = true;
                $itemrows = $rows[$itemid] ?? [];
                if (!$active || !self::counts_toward_final($item)) {
                    self::lift_all($item, $itemrows);
                    continue;
                }
                $itemrow = $itemrows[0] ?? null;
                unset($itemrows[0]);
                if (!$itemrow) {
                    $itemrow = self::add_row($cm, $item, 0, $outstanding ? self::HELD : self::CLEARED, (int) $item->hidden);
                    if ($outstanding) {
                        self::set_item_hidden($item, 1);
                    }
                } else if ($itemrow->state === self::HELD) {
                    if ($outstanding) {
                        self::keep_item_hidden($item, $itemrow);
                    } else {
                        self::set_item_hidden($item, (int) $itemrow->prevhidden);
                        $itemrow->state = self::CLEARED;
                        $itemrow->timemodified = time();
                        $DB->update_record(self::TABLE, $itemrow);
                    }
                }
                if ($itemrow->state === self::HELD) {
                    // The whole item is held: no one-by-one holds needed.
                    self::lift_learners($item, $itemrows);
                    continue;
                }
                // Cleared or exempt: hold learners still to record, one by one (only those without a grade yet).
                foreach ($itemrows as $userid => $row) {
                    if (!in_array($userid, $outstanding)) {
                        self::lift_learners($item, [$userid => $row]);
                    } else {
                        self::keep_grade_hidden($item, $row);
                    }
                }
                foreach ($outstanding as $userid) {
                    if (isset($itemrows[$userid])) {
                        continue;
                    }
                    $grade = grade_grade::fetch(['itemid' => $itemid, 'userid' => $userid]);
                    if ($grade && $grade->finalgrade !== null) {
                        continue;
                    }
                    self::add_row($cm, $item, $userid, self::HELD, $grade ? (int) $grade->hidden : 0);
                    self::set_grade_hidden($item, $userid, 1);
                }
            }
        } finally {
            self::$updating = false;
        }
        foreach (array_diff_key($rows, $seen) as $itemid => $gone) {
            $DB->delete_records(self::TABLE, ['gradeitemid' => $itemid]);
        }
    }

    /**
     * Update every activity in a course where RA and LLN are recorded.
     *
     * @param int $courseid
     */
    public static function update_course(int $courseid): void {
        $modinfo = get_fast_modinfo($courseid, -1);
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->deletioninprogress || !reasonable_adjustments::supports($cm->modname)) {
                continue;
            }
            self::update($cm);
        }
    }

    /**
     * Lift every hold on an activity (when it's deleted) and forget it.
     *
     * @param int $cmid
     */
    public static function delete_for_activity(int $cmid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['cmid' => $cmid]);
    }

    /**
     * When holds begin (install or upgrade): mark assessments that already have grades exempt, so grades learners can
     * see now stay visible.
     *
     * @return int How many grade items were exempted
     */
    public static function exempt_graded(): int {
        global $DB;
        $modnames = tool_details::get_modnames();
        [$insql, $params] = $DB->get_in_or_equal($modnames, SQL_PARAMS_NAMED, 'rhm');
        $items = $DB->get_recordset_sql("SELECT gi.id, gi.hidden, cm.id AS cmid
                                           FROM {grade_items} gi
                                           JOIN {modules} m ON m.name = gi.itemmodule
                                           JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = gi.iteminstance
                                                AND cm.course = gi.courseid
                                          WHERE gi.itemtype = 'mod' AND gi.itemmodule {$insql}
                                            AND EXISTS (SELECT 1 FROM {grade_grades} gg
                                                         WHERE gg.itemid = gi.id AND gg.finalgrade IS NOT NULL)
                                            AND NOT EXISTS (SELECT 1 FROM {" . self::TABLE . "} h
                                                             WHERE h.gradeitemid = gi.id AND h.userid = 0)", $params);
        $count = 0;
        $now = time();
        foreach ($items as $item) {
            $DB->insert_record(self::TABLE, ['cmid' => (int) $item->cmid, 'gradeitemid' => (int) $item->id, 'userid' => 0,
                'state' => self::EXEMPT, 'prevhidden' => (int) $item->hidden, 'timecreated' => $now, 'timemodified' => $now]);
            $count++;
        }
        $items->close();
        return $count;
    }

    /**
     * Add a hold record.
     *
     * @param cm_info $cm
     * @param grade_item $item
     * @param int $userid
     * @param string $state
     * @param int $prevhidden
     * @return stdClass
     */
    private static function add_row(cm_info $cm, grade_item $item, int $userid, string $state, int $prevhidden): stdClass {
        global $DB;
        $row = (object) ['cmid' => (int) $cm->id, 'gradeitemid' => (int) $item->id, 'userid' => $userid,
            'state' => $state, 'prevhidden' => $prevhidden, 'timecreated' => time(), 'timemodified' => time()];
        $row->id = $DB->insert_record(self::TABLE, $row);
        return $row;
    }

    /**
     * Lift every hold on a grade item and forget it (it no longer counts, or holding is off).
     *
     * @param grade_item $item
     * @param stdClass[] $rows userid => row
     */
    private static function lift_all(grade_item $item, array $rows): void {
        global $DB;
        if (isset($rows[0]) && $rows[0]->state === self::HELD) {
            self::set_item_hidden($item, (int) $rows[0]->prevhidden);
            $DB->delete_records(self::TABLE, ['id' => $rows[0]->id]);
        }
        unset($rows[0]);
        self::lift_learners($item, $rows);
    }

    /**
     * Lift one-by-one holds.
     *
     * @param grade_item $item
     * @param stdClass[] $rows userid => row
     */
    private static function lift_learners(grade_item $item, array $rows): void {
        global $DB;
        foreach ($rows as $userid => $row) {
            self::set_grade_hidden($item, (int) $userid, (int) $row->prevhidden);
            $DB->delete_records(self::TABLE, ['id' => $row->id]);
        }
    }

    /**
     * The item is held: if someone showed it (or set a hidden-until date), keep that to put back later, and hide it.
     *
     * @param grade_item $item
     * @param stdClass $row
     */
    private static function keep_item_hidden(grade_item $item, stdClass $row): void {
        global $DB;
        if ((int) $item->hidden === 1) {
            return;
        }
        $row->prevhidden = (int) $item->hidden;
        $row->timemodified = time();
        $DB->update_record(self::TABLE, $row);
        self::set_item_hidden($item, 1);
    }

    /**
     * A learner's grade is held: likewise.
     *
     * @param grade_item $item
     * @param stdClass $row
     */
    private static function keep_grade_hidden(grade_item $item, stdClass $row): void {
        global $DB;
        $grade = grade_grade::fetch(['itemid' => $item->id, 'userid' => $row->userid]);
        if ($grade && (int) $grade->hidden === 1) {
            return;
        }
        $row->prevhidden = $grade ? (int) $grade->hidden : 0;
        $row->timemodified = time();
        $DB->update_record(self::TABLE, $row);
        self::set_grade_hidden($item, (int) $row->userid, 1);
    }

    /**
     * Set a grade item's hidden value (directly: Moodle's set_hidden() would also show its category).
     *
     * @param grade_item $item
     * @param int $hidden
     */
    private static function set_item_hidden(grade_item $item, int $hidden): void {
        if ((int) $item->hidden === $hidden) {
            return;
        }
        $item->hidden = $hidden;
        $item->update('report_evidencereporter');
    }

    /**
     * Set one learner's grade hidden value, creating an empty grade to hold if they have none yet.
     *
     * @param grade_item $item
     * @param int $userid
     * @param int $hidden
     */
    private static function set_grade_hidden(grade_item $item, int $userid, int $hidden): void {
        $grade = grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
        if (!$grade) {
            if (!$hidden) {
                return;
            }
            $grade = new grade_grade(['itemid' => $item->id, 'userid' => $userid], false);
            $grade->grade_item = $item;
            $grade->hidden = $hidden;
            $grade->insert('report_evidencereporter');
            return;
        }
        if ((int) $grade->hidden === $hidden) {
            return;
        }
        $grade->grade_item = $item;
        $grade->hidden = $hidden;
        $grade->update('report_evidencereporter');
    }
}
