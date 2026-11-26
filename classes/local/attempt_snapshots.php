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
use context_module;
use stdClass;

/**
 * Attempt snapshots for activities with no attempts of their own (database, forum, workshop, checklist): each time a
 * learner's work is marked Not satisfactory, the result, grade, assessor, date and feedback are kept, so the attempt
 * trail survives later marking that overwrites them (a checklist comment edited, a grade changed).
 *
 * A snapshot stays open, gathering feedback given since (gradebook feedback, the workshop's feedback for the author,
 * checklist comments, the assessor's forum replies and database comments), until the learner's next piece of work or
 * the next result; then it's frozen. A Satisfactory result isn't snapshotted: it's the live, final attempt.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_snapshots {
    /** @var string The table. */
    public const TABLE = 'report_evidencereporter_snap';

    /** @var string[] Activity types without attempts of their own. */
    public const TYPES = ['data', 'forum', 'workshop', 'checklist'];

    /** @var string Recorded as it happened. */
    public const SOURCE_LIVE = 'live';
    /** @var string Filled in from the gradebook's history, for marking before Evidence Reporter recorded it. */
    public const SOURCE_HISTORY = 'history';

    /**
     * Does this activity type get snapshots?
     *
     * @param string $modname
     * @return bool
     */
    public static function tracks(string $modname): bool {
        return in_array($modname, self::TYPES);
    }

    /**
     * Attempts allowed for these activities (site setting; 3 by default; 0 for no limit).
     *
     * @return int
     */
    public static function get_max_attempts(): int {
        $value = get_config('report_evidencereporter', 'snapattempts');
        return ($value === false || $value === '') ? 3 : max(0, (int) $value);
    }

    /**
     * The activity's grade item: for forums, whole-forum grading (item 1) before ratings (item 0).
     *
     * @param cm_info|stdClass $cm With course, modname, instance
     * @return \grade_item|null
     */
    public static function get_grade_item($cm): ?\grade_item {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        foreach ($cm->modname === 'forum' ? [1, 0] : [0] as $itemnumber) {
            $item = \grade_item::fetch(['courseid' => $cm->course, 'itemtype' => 'mod', 'itemmodule' => $cm->modname,
                'iteminstance' => $cm->instance, 'itemnumber' => $itemnumber]);
            if ($item) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Is a grade Not satisfactory (under the grade item's grade to pass)? Null when there's no pass mark.
     *
     * @param \grade_item $item
     * @param float|null $grade
     * @return bool|null
     */
    public static function is_not_satisfactory(\grade_item $item, ?float $grade): ?bool {
        if ($grade === null || (float) $item->gradepass <= 0) {
            return null;
        }
        return $grade + 0.00001 < (float) $item->gradepass;
    }

    /**
     * A learner's snapshots in an activity, oldest first.
     *
     * @param int $cmid
     * @param int $userid
     * @return stdClass[]
     */
    public static function get(int $cmid, int $userid): array {
        global $DB;
        return array_values($DB->get_records(self::TABLE, ['cmid' => $cmid, 'userid' => $userid], 'attemptno, id'));
    }

    /**
     * A grade was saved for a learner: snapshot a Not satisfactory result, or close the open snapshot.
     *
     * @param cm_info $cm
     * @param int $userid
     * @param \grade_item $item
     * @param float|null $grade The final grade
     * @param int $assessorid
     * @param int $time When it was marked
     * @param string|null $feedback Gradebook feedback at that time (null: read the current feedback)
     * @param string $source One of the SOURCE_ constants
     */
    public static function graded(
        cm_info $cm,
        int $userid,
        \grade_item $item,
        ?float $grade,
        int $assessorid,
        int $time,
        ?string $feedback = null,
        string $source = self::SOURCE_LIVE
    ): void {
        global $DB;
        if (!self::tracks($cm->modname) || $grade === null) {
            return;
        }
        $notsatisfactory = self::is_not_satisfactory($item, $grade);
        if ($notsatisfactory === null) {
            return;
        }
        $snapshots = self::get((int) $cm->id, $userid);
        $open = null;
        foreach ($snapshots as $snapshot) {
            if (!$snapshot->timefrozen) {
                $open = $snapshot;
            }
        }
        if ($open) {
            $newwork = self::first_work_after($cm, $userid, (int) $open->timemarked, $time);
            if ($newwork === null && $cm->modname === 'checklist' && $time - (int) $open->timemarked > DAYSECS) {
                // Checklists have nothing for the learner to submit: a judgement on another day is a new attempt.
                $newwork = $time;
            }
            if ($newwork !== null || !$notsatisfactory) {
                self::freeze($open, $cm, $newwork ?? $time);
            } else {
                // Marked again before the learner did anything more: the same attempt.
                $open->grade = $grade;
                $open->assessorid = $assessorid ?: $open->assessorid;
                if ($feedback !== null) {
                    $open->gradefeedback = $feedback;
                }
                self::refresh($open, $cm);
                return;
            }
        }
        if (!$notsatisfactory) {
            return;
        }
        $record = (object) [
            'cmid' => (int) $cm->id,
            'userid' => $userid,
            'attemptno' => count($snapshots) + 1,
            'gradeitemid' => (int) $item->id,
            'grade' => $grade,
            'assessorid' => $assessorid,
            'gradefeedback' => $feedback,
            'feedback' => '[]',
            'source' => $source,
            'timemarked' => $time,
            'timefrozen' => 0,
            'timemodified' => time(),
        ];
        $record->id = $DB->insert_record(self::TABLE, $record);
        self::refresh($record, $cm);
    }

    /**
     * Feedback was given (a forum reply, a database comment, checklist comments): gather it into the open snapshot.
     *
     * @param int $cmid
     * @param int $userid The learner
     */
    public static function feedback_given(int $cmid, int $userid): void {
        global $DB;
        $open = $DB->get_record(self::TABLE, ['cmid' => $cmid, 'userid' => $userid, 'timefrozen' => 0]);
        if (!$open) {
            return;
        }
        try {
            [, $cm] = get_course_and_cm_from_cmid($cmid);
        } catch (\Throwable $e) {
            return;
        }
        self::refresh($open, $cm);
    }

    /**
     * Close a snapshot: gather feedback up to the end, then never again.
     *
     * @param stdClass $snapshot
     * @param cm_info $cm
     * @param int $end
     */
    private static function freeze(stdClass $snapshot, cm_info $cm, int $end): void {
        $snapshot->timefrozen = max($end, (int) $snapshot->timemarked);
        self::refresh($snapshot, $cm);
    }

    /**
     * Gather the feedback for a snapshot's attempt and save it: what the assessor gave between the end of the
     * previous attempt and the end of this one (now, while it's open).
     *
     * @param stdClass $snapshot
     * @param cm_info $cm
     */
    private static function refresh(stdClass $snapshot, cm_info $cm): void {
        global $DB;
        $userid = (int) $snapshot->userid;
        $start = (int) $DB->get_field_sql(
            "SELECT MAX(timefrozen) FROM {" . self::TABLE . "}
                                            WHERE cmid = :cmid AND userid = :userid AND attemptno < :no",
            ['cmid' => $cm->id, 'userid' => $userid, 'no' => $snapshot->attemptno]
        );
        $end = $snapshot->timefrozen ? (int) $snapshot->timefrozen : time();
        $pieces = [];

        // Gradebook feedback (a workshop's feedback for the author arrives here too).
        $gradefeedback = $snapshot->gradefeedback ?? null;
        if (!$snapshot->timefrozen && $snapshot->source === self::SOURCE_LIVE) {
            // Open and recorded live: the gradebook feedback as it stands now (assessors often add it after the grade).
            $gradefeedback = (string) $DB->get_field('grade_grades', 'feedback', ['itemid' => $snapshot->gradeitemid,
                'userid' => $userid]);
            $snapshot->gradefeedback = $gradefeedback;
        }
        if (trim(strip_tags((string) $gradefeedback)) !== '') {
            $pieces[] = ['source' => 'gradebook', 'label' => '', 'text' => (string) $gradefeedback,
                'time' => (int) $snapshot->timemarked, 'userid' => (int) $snapshot->assessorid];
        }
        $context = context_module::instance($cm->id);
        switch ($cm->modname) {
            case 'checklist':
                // Item comments are overwritten when edited: keep them as they stand now (while the attempt is open).
                if (!$snapshot->timefrozen || $snapshot->feedback === '[]') {
                    $comments = $DB->get_records_sql("SELECT c.id, c.text, c.commentby, i.displaytext
                                                        FROM {checklist_comment} c
                                                        JOIN {checklist_item} i ON i.id = c.itemid
                                                       WHERE i.checklist = :checklist AND c.userid = :userid
                                                    ORDER BY i.position, c.id", ['checklist' => $cm->instance,
                        'userid' => $userid]);
                    foreach ($comments as $comment) {
                        if (trim((string) $comment->text) !== '') {
                            $pieces[] = ['source' => 'checklist', 'label' => (string) $comment->displaytext,
                                'text' => (string) $comment->text, 'time' => $end, 'userid' => (int) $comment->commentby];
                        }
                    }
                } else {
                    foreach (json_decode($snapshot->feedback, true) ?: [] as $piece) {
                        if ($piece['source'] === 'checklist') {
                            $pieces[] = $piece;
                        }
                    }
                }
                break;
            case 'forum':
                $replies = $DB->get_records_sql("SELECT p.id, p.message, p.userid, p.created
                                                   FROM {forum_posts} p
                                                   JOIN {forum_discussions} d ON d.id = p.discussion
                                                   JOIN {forum_posts} parent ON parent.id = p.parent
                                                  WHERE d.forum = :forum AND parent.userid = :learner AND p.userid <> :learner2
                                                    AND p.created > :start AND p.created <= :end
                                               ORDER BY p.created, p.id", ['forum' => $cm->instance, 'learner' => $userid,
                    'learner2' => $userid, 'start' => $start, 'end' => $end]);
                foreach ($replies as $reply) {
                    $pieces[] = ['source' => 'forum', 'label' => '', 'text' => (string) $reply->message,
                        'time' => (int) $reply->created, 'userid' => (int) $reply->userid];
                }
                break;
            case 'data':
                $comments = $DB->get_records_sql("SELECT c.id, c.content, c.userid, c.timecreated
                                                    FROM {comments} c
                                                    JOIN {data_records} r ON r.id = c.itemid
                                                   WHERE c.contextid = :contextid AND c.component = 'mod_data'
                                                     AND c.commentarea = 'database_entry' AND r.userid = :learner
                                                     AND c.userid <> :learner2 AND c.timecreated > :start
                                                     AND c.timecreated <= :end
                                                ORDER BY c.timecreated, c.id", ['contextid' => $context->id,
                    'learner' => $userid, 'learner2' => $userid, 'start' => $start, 'end' => $end]);
                foreach ($comments as $comment) {
                    $pieces[] = ['source' => 'data', 'label' => '', 'text' => (string) $comment->content,
                        'time' => (int) $comment->timecreated, 'userid' => (int) $comment->userid];
                }
                break;
        }
        $snapshot->feedback = json_encode($pieces);
        $snapshot->timemodified = time();
        $DB->update_record(self::TABLE, $snapshot);
    }

    /**
     * When the learner next did something in the activity after a time (posted, added or changed an entry, submitted,
     * ticked an item), up to another time.
     *
     * @param cm_info $cm
     * @param int $userid
     * @param int $after
     * @param int $until
     * @return int|null
     */
    private static function first_work_after(cm_info $cm, int $userid, int $after, int $until): ?int {
        global $DB;
        $params = ['instance' => $cm->instance, 'userid' => $userid, 'after' => $after, 'until' => $until];
        switch ($cm->modname) {
            case 'forum':
                $sql = "SELECT MIN(p.created) FROM {forum_posts} p JOIN {forum_discussions} d ON d.id = p.discussion
                         WHERE d.forum = :instance AND p.userid = :userid AND p.created > :after AND p.created <= :until";
                break;
            case 'data':
                $sql = "SELECT MIN(timemodified) FROM {data_records}
                         WHERE dataid = :instance AND userid = :userid AND timemodified > :after AND timemodified <= :until";
                break;
            case 'workshop':
                $sql = "SELECT MIN(timemodified) FROM {workshop_submissions}
                         WHERE workshopid = :instance AND authorid = :userid AND example = 0
                               AND timemodified > :after AND timemodified <= :until";
                break;
            case 'checklist':
                $sql = "SELECT MIN(c.usertimestamp) FROM {checklist_check} c JOIN {checklist_item} i ON i.id = c.item
                         WHERE i.checklist = :instance AND c.userid = :userid
                               AND c.usertimestamp > :after AND c.usertimestamp <= :until";
                break;
            default:
                return null;
        }
        $time = $DB->get_field_sql($sql, $params);
        return $time ? (int) $time : null;
    }

    /**
     * The attempt trail for display: each snapshot, then the live result as the latest attempt.
     *
     * @param cm_info $cm
     * @param int $userid
     * @return stdClass[] attemptno, grade (number), outcome (fail|pass|''), time, assessorid, feedback (pieces), live (bool)
     *               — empty when there are no snapshots
     */
    public static function get_trail(cm_info $cm, int $userid): array {
        global $DB;
        $snapshots = self::get((int) $cm->id, $userid);
        if (!$snapshots) {
            return [];
        }
        $trail = [];
        foreach ($snapshots as $snapshot) {
            $trail[] = (object) ['attemptno' => (int) $snapshot->attemptno, 'grade' => (float) $snapshot->grade,
                'gradeitemid' => (int) $snapshot->gradeitemid, 'outcome' => 'fail', 'time' => (int) $snapshot->timemarked,
                'assessorid' => (int) $snapshot->assessorid, 'feedback' => json_decode($snapshot->feedback, true) ?: [],
                'live' => false];
        }
        $last = end($snapshots);
        $item = self::get_grade_item($cm);
        $grade = $item ? $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $userid]) : null;
        if (
            $grade && $grade->finalgrade !== null && (int) $grade->timemodified > (int) $last->timemarked
                && ($last->timefrozen || !self::is_not_satisfactory($item, (float) $grade->finalgrade))
        ) {
            $pieces = trim(strip_tags((string) $grade->feedback)) !== '' ? [['source' => 'gradebook', 'label' => '',
                'text' => (string) $grade->feedback, 'time' => (int) $grade->timemodified,
                'userid' => (int) $grade->usermodified]] : [];
            $trail[] = (object) ['attemptno' => (int) $last->attemptno + 1, 'grade' => (float) $grade->finalgrade,
                'gradeitemid' => (int) $item->id,
                'outcome' => self::is_not_satisfactory($item, (float) $grade->finalgrade) ? 'fail' : 'pass',
                'time' => (int) $grade->timemodified, 'assessorid' => (int) $grade->usermodified, 'feedback' => $pieces,
                'live' => true];
        }
        return $trail;
    }

    /**
     * Attempts used so far, for the marking page: each snapshot, plus the live result if it's a later attempt.
     *
     * @param cm_info $cm
     * @param int $userid
     * @param bool $hasresult The learner has a live result
     * @return int 0 when there's nothing to count
     */
    public static function attempts_used(cm_info $cm, int $userid, bool $hasresult): int {
        $trail = self::get_trail($cm, $userid);
        if (!$trail) {
            return $hasresult ? 1 : 0;
        }
        return count($trail);
    }

    /**
     * One piece of feedback as HTML, with where it came from.
     *
     * @param array $piece source, label, text, time, userid
     * @param context_module $context
     * @return string
     */
    public static function piece_html(array $piece, context_module $context): string {
        $text = format_text($piece['text'], FORMAT_HTML, ['context' => $context]);
        $where = get_string('snapsource_' . $piece['source'], 'report_evidencereporter');
        if ($piece['label'] !== '') {
            $where .= ': ' . format_string($piece['label'], true, ['context' => $context]);
        }
        return \html_writer::div(\html_writer::span(s($where), 'small text-muted') . $text, 'er-snappiece');
    }

    /**
     * Fill in snapshots from the gradebook's history, for marking before Evidence Reporter recorded it: each Not
     * satisfactory result (and its gradebook feedback) in the history of these activities' grade items.
     *
     * @return int Snapshots made
     */
    public static function backfill(): int {
        global $DB;
        $made = 0;
        [$insql, $params] = $DB->get_in_or_equal(self::TYPES, SQL_PARAMS_NAMED, 'bfm');
        $items = $DB->get_records_select(
            'grade_items',
            "itemtype = 'mod' AND itemmodule {$insql} AND gradepass > 0",
            $params
        );
        foreach ($items as $itemrecord) {
            $item = new \grade_item($itemrecord, false);
            $cm = get_coursemodule_from_instance($item->itemmodule, $item->iteminstance, $item->courseid);
            if (
                !$cm || ($item->itemmodule === 'forum' && (int) $item->itemnumber === 0
                    && self::get_grade_item((object) ['course' => $cm->course, 'modname' => 'forum',
                        'instance' => $cm->instance])->id != $item->id)
            ) {
                continue;
            }
            $cminfo = get_fast_modinfo($cm->course)->get_cm($cm->id);
            $rs = $DB->get_recordset_select(
                'grade_grades_history',
                'itemid = :itemid AND finalgrade IS NOT NULL',
                ['itemid' => $item->id],
                'userid, timemodified, id',
                'id, userid, finalgrade, feedback, usermodified, timemodified'
            );
            $done = [];
            foreach ($rs as $row) {
                if (!isset($done[$row->userid])) {
                    $done[$row->userid] = (bool) $DB->record_exists(self::TABLE, ['cmid' => $cm->id,
                        'userid' => $row->userid]);
                }
                if ($done[$row->userid] === true) {
                    continue;
                }
                $before = $DB->count_records(self::TABLE, ['cmid' => $cm->id, 'userid' => $row->userid]);
                self::graded(
                    $cminfo,
                    (int) $row->userid,
                    $item,
                    (float) $row->finalgrade,
                    (int) $row->usermodified,
                    (int) $row->timemodified,
                    (string) $row->feedback,
                    self::SOURCE_HISTORY
                );
                $made += $DB->count_records(self::TABLE, ['cmid' => $cm->id, 'userid' => $row->userid]) - $before;
            }
            $rs->close();
            // Learners whose history ended Satisfactory: nothing stays open.
        }
        return $made;
    }

    /**
     * Remove an activity's snapshots (when it's deleted).
     *
     * @param int $cmid
     */
    public static function delete_for_activity(int $cmid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['cmid' => $cmid]);
    }
}
