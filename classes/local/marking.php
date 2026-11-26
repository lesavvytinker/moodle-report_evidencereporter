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
use context_course;
use context_module;
use moodle_url;
use stdClass;

/**
 * What needs marking in a course, per learner and assessment: for the assessor's marking page and the Dashboard
 * nudge. Assessments are the course's activities linked to the chosen unit (or all units); a cell's status is one of
 * the STATUS_ constants. Waiting submissions turn amber, then red, after the site's thresholds (7 and 14 days by
 * default); a Not satisfactory result without feedback is flagged.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class marking {
    /** @var string Nothing submitted yet. */
    public const STATUS_NOTSUBMITTED = 'notsubmitted';
    /** @var string Started but not finished (quiz in progress). */
    public const STATUS_INPROGRESS = 'inprogress';
    /** @var string Submitted, waiting to be marked. */
    public const STATUS_TOMARK = 'tomark';
    /** @var string Marked but not released to the learner (marking workflow). */
    public const STATUS_NOTRELEASED = 'notreleased';
    /** @var string Not satisfactory and no feedback given. */
    public const STATUS_FEEDBACK = 'feedback';
    /** @var string Not satisfactory, with feedback: waiting for the learner's next attempt. */
    public const STATUS_NOTSATISFACTORY = 'notsatisfactory';
    /** @var string Satisfactory. */
    public const STATUS_SATISFACTORY = 'satisfactory';
    /** @var string Submitted, in an activity with no grade to mark it with. */
    public const STATUS_SUBMITTED = 'submitted';
    /** @var string Marked, with no pass mark to judge by. */
    public const STATUS_MARKED = 'marked';
    /** @var string Nothing to go on: marked by ticking or completing, not by submitting (e.g. a checklist). */
    public const STATUS_NOTMARKED = 'notmarked';

    /** @var string Waiting, under the amber threshold. */
    public const AGE_NEW = 'new';
    /** @var string Waiting past the amber threshold. */
    public const AGE_AMBER = 'amber';
    /** @var string Waiting past the red threshold. */
    public const AGE_RED = 'red';

    /** @var string[] Activity types whose submissions can be tracked. */
    public const SUBMITTED_TYPES = ['assign', 'quiz', 'workshop', 'forum', 'data'];

    /**
     * Days before a waiting submission turns amber and red (site settings, 7 and 14 by default).
     *
     * @return int[] [amber, red]
     */
    public static function get_thresholds(): array {
        $amber = (int) get_config('report_evidencereporter', 'markingamber');
        $red = (int) get_config('report_evidencereporter', 'markingred');
        $amber = $amber > 0 ? $amber : 7;
        $red = $red > 0 ? max($red, $amber) : max(14, $amber);
        return [$amber, $red];
    }

    /**
     * How long-waiting a submission is.
     *
     * @param int $days
     * @return string One of the AGE_ constants
     */
    public static function age(int $days): string {
        [$amber, $red] = self::get_thresholds();
        return $days >= $red ? self::AGE_RED : ($days >= $amber ? self::AGE_AMBER : self::AGE_NEW);
    }

    /**
     * Courses where the current user marks: they have the marking page's capability there.
     *
     * @return stdClass[] courseid => course
     */
    public static function get_my_courses(): array {
        $courses = [];
        foreach (enrol_get_my_courses(['id', 'shortname', 'fullname', 'visible'], 'fullname ASC') as $course) {
            if (has_capability('report/evidencereporter:marking', context_course::instance($course->id))) {
                $courses[(int) $course->id] = $course;
            }
        }
        return $courses;
    }

    /**
     * The units the course delivers: framework units with at least one course competency at or under them.
     *
     * @param int $courseid
     * @return stdClass[] unitid => (id, label, frameworkid, path)
     */
    public static function get_units(int $courseid): array {
        global $DB;
        $comps = $DB->get_records_sql("SELECT c.id, c.path, c.competencyframeworkid
                                         FROM {competency_coursecomp} cc
                                         JOIN {competency} c ON c.id = cc.competencyid
                                        WHERE cc.courseid = :courseid", ['courseid' => $courseid]);
        $units = [];
        $frameworks = array_unique(array_map(fn($c) => (int) $c->competencyframeworkid, $comps));
        foreach ($frameworks as $frameworkid) {
            foreach (self::framework_units($frameworkid) as $unit) {
                foreach ($comps as $comp) {
                    if ((int) $comp->id === (int) $unit->id || strpos($comp->path, '/' . $unit->id . '/') !== false) {
                        $units[(int) $unit->id] = (object) ['id' => (int) $unit->id, 'label' => scope::unit_label($unit),
                            'frameworkid' => $frameworkid];
                        break;
                    }
                }
            }
        }
        return $units;
    }

    /**
     * A framework's units, for marking: its top-level competencies; but when it has one top-level competency with four
     * levels under it (qualification, unit, element, criterion), that competency's children. A framework holding one
     * unit (unit, element, criterion) is that unit.
     *
     * @param int $frameworkid
     * @return stdClass[] id, shortname, idnumber, competencyframeworkid
     */
    public static function framework_units(int $frameworkid): array {
        global $DB;
        $fields = 'id, shortname, idnumber, competencyframeworkid, sortorder';
        $top = $DB->get_records(
            'competency',
            ['competencyframeworkid' => $frameworkid, 'parentid' => 0],
            'sortorder, id',
            $fields
        );
        if (count($top) !== 1) {
            return $top;
        }
        $root = reset($top);
        $depth = $DB->sql_length('path') . ' - ' . $DB->sql_length("REPLACE(path, '/', '')");
        $deepest = (int) $DB->get_field_sql(
            "SELECT MAX({$depth}) FROM {competency} WHERE competencyframeworkid = :fid",
            ['fid' => $frameworkid]
        );
        // A top-level competency's path is "/0/" (2 slashes); its great-grandchildren's have 5.
        if ($deepest < 5) {
            return $top;
        }
        return $DB->get_records('competency', ['parentid' => $root->id], 'sortorder, id', $fields) ?: $top;
    }

    /**
     * The course's assessments for some units: activities linked to competencies at or under them.
     *
     * @param int $courseid
     * @param int[] $unitids
     * @return array cmid => stdClass (cm cm_info, unitids int[])
     */
    public static function get_assessments(int $courseid, array $unitids): array {
        global $DB;
        if (!$unitids) {
            return [];
        }
        $rows = $DB->get_records_sql("SELECT mc.id, mc.cmid, c.id AS competencyid, c.path
                                        FROM {competency_modulecomp} mc
                                        JOIN {course_modules} cm ON cm.id = mc.cmid
                                        JOIN {competency} c ON c.id = mc.competencyid
                                       WHERE cm.course = :courseid AND cm.deletioninprogress = 0", ['courseid' => $courseid]);
        $byunit = [];
        foreach ($rows as $row) {
            foreach ($unitids as $unitid) {
                if ((int) $row->competencyid === (int) $unitid || strpos($row->path, '/' . $unitid . '/') !== false) {
                    $byunit[(int) $row->cmid][(int) $unitid] = (int) $unitid;
                }
            }
        }
        $modinfo = get_fast_modinfo($courseid);
        $result = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (isset($byunit[$cm->id]) && $cm->uservisible && in_array($cm->modname, tool_details::get_modnames())) {
                $result[(int) $cm->id] = (object) ['cm' => $cm, 'unitids' => array_values($byunit[$cm->id])];
            }
        }
        return $result;
    }

    /**
     * The course's learners the current user may mark: active, competency-gradable, in their groups when the course
     * uses separate groups.
     *
     * @param int $courseid
     * @param int $groupid A group to limit to (0: all the user may see)
     * @return stdClass[] userid => user
     */
    public static function get_learners(int $courseid, int $groupid = 0): array {
        global $USER;
        $context = context_course::instance($courseid);
        $course = get_course($courseid);
        $groupids = [];
        if ($groupid) {
            $groupids = [$groupid];
        } else if (
            groups_get_course_groupmode($course) == SEPARATEGROUPS
                && !has_capability('moodle/site:accessallgroups', $context)
        ) {
            $groupids = array_keys(groups_get_all_groups($courseid, $USER->id));
            if (!$groupids) {
                return [];
            }
        }
        $fields = 'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,'
            . ' u.email, u.picture, u.imagealt';
        $learners = [];
        foreach ($groupids ?: [0] as $gid) {
            $learners += get_enrolled_users(
                $context,
                'moodle/competency:coursecompetencygradable',
                $gid,
                $fields,
                'u.lastname, u.firstname',
                0,
                0,
                true
            );
        }
        unset($learners[$USER->id]);
        return $learners;
    }

    /**
     * Every learner's cell for one assessment.
     *
     * @param cm_info $cm
     * @param int[] $userids
     * @return array userid => stdClass: status, days (waiting, or null), age, submitted (time or null), graded (time or
     *               null), grade (text), attempt (e.g. "Attempt 2 of 3", or ''), noattemptsleft (bool: Not satisfactory
     *               on the last attempt), url, adjustments (count), toconfirm
     *               (count), adjustmentsurl (null when the activity doesn't record them)
     */
    public static function get_cells(cm_info $cm, array $userids): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        if (!$userids) {
            return [];
        }
        $userids = array_map('intval', $userids);
        // Forums keep whole-forum grading in item 1 (ratings are item 0): prefer it.
        $item = null;
        foreach ($cm->modname === 'forum' ? [1, 0] : [0] as $itemnumber) {
            $item = \grade_item::fetch(['courseid' => $cm->course, 'itemtype' => 'mod', 'itemmodule' => $cm->modname,
                'iteminstance' => $cm->instance, 'itemnumber' => $itemnumber]) ?: null;
            if ($item) {
                break;
            }
        }
        $grades = [];
        if ($item) {
            [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'mku');
            $params['itemid'] = $item->id;
            $grades = $DB->get_records_sql("SELECT userid, finalgrade, feedback, timemodified, overridden
                                              FROM {grade_grades}
                                             WHERE itemid = :itemid AND userid {$insql}", $params);
        }
        $facts = self::get_facts($cm, $userids);
        $raavailable = reasonable_adjustments::available($cm);
        $recorded = $raavailable ? reasonable_adjustments::get_for_activity((int) $cm->id) : [];
        $suggested = $raavailable && reasonable_adjustments::can_manage(context_module::instance($cm->id))
            ? reasonable_adjustments::get_suggestions($cm) : [];
        $now = time();
        $result = [];
        foreach ($userids as $userid) {
            $fact = $facts[$userid] ?? (object) [];
            $grade = $grades[$userid] ?? null;
            $gradevalue = ($fact->gradevalue ?? null) !== null ? (float) $fact->gradevalue
                : ($grade && $grade->finalgrade !== null ? (float) $grade->finalgrade : null);
            $gradedtime = $fact->gradedtime ?? ($gradevalue !== null && $grade ? (int) $grade->timemodified : null);
            $submitted = $fact->submitted ?? null;
            $hasfeedback = !empty($fact->feedback) || ($grade && trim(strip_tags((string) $grade->feedback)) !== '');
            $cell = (object) ['status' => self::STATUS_NOTSUBMITTED, 'days' => null, 'age' => null,
                'submitted' => $submitted, 'graded' => $gradedtime, 'grade' => '',
                'attempt' => $fact->attempt ?? '', 'url' => self::marking_url($cm, $userid, $fact)->out(false),
                'adjustments' => count($recorded[$userid] ?? []), 'toconfirm' => count($suggested[$userid] ?? []),
                'adjustmentsurl' => $raavailable
                    ? (new moodle_url('/report/evidencereporter/adjustments.php', ['cmid' => $cm->id]))->out(false) : null];
            if ($gradevalue !== null && $item) {
                $cell->grade = grade_format_gradevalue($gradevalue, $item);
            }
            if ($submitted && !$item) {
                $cell->status = self::STATUS_SUBMITTED;
            } else if ($submitted && ($gradedtime === null || $gradedtime < $submitted || !empty($fact->needsgrading))) {
                $cell->status = self::STATUS_TOMARK;
                $cell->days = max(0, (int) floor(($now - $submitted) / DAYSECS));
                $cell->age = self::age($cell->days);
            } else if ($gradevalue !== null) {
                $pass = $item && (float) $item->gradepass > 0 ? $gradevalue + 0.00001 >= (float) $item->gradepass : null;
                if ($pass === false && !$hasfeedback) {
                    $cell->status = self::STATUS_FEEDBACK;
                } else if (!empty($fact->notreleased)) {
                    $cell->status = self::STATUS_NOTRELEASED;
                } else if ($pass === true) {
                    $cell->status = self::STATUS_SATISFACTORY;
                } else if ($pass === false) {
                    $cell->status = self::STATUS_NOTSATISFACTORY;
                } else {
                    $cell->status = self::STATUS_MARKED;
                }
            } else if (!empty($fact->inprogress)) {
                $cell->status = self::STATUS_INPROGRESS;
            } else if (!in_array($cm->modname, self::SUBMITTED_TYPES)) {
                $cell->status = self::STATUS_NOTMARKED;
            }
            // Attempts: the activity's own, or kept by Evidence Reporter for those without (attempt snapshots).
            $attemptno = $fact->attemptno ?? 0;
            $max = $fact->maxattempts ?? 0;
            if (attempt_snapshots::tracks($cm->modname)) {
                $attemptno = attempt_snapshots::attempts_used($cm, $userid, $gradevalue !== null);
                $max = attempt_snapshots::get_max_attempts();
                if ($attemptno) {
                    $cell->attempt = get_string(
                        'markingattempt',
                        'report_evidencereporter',
                        (object) ['no' => $attemptno, 'max' => $max ?: '∞']
                    );
                }
            }
            $cell->noattemptsleft = $max > 0 && $attemptno >= $max
                && in_array($cell->status, [self::STATUS_FEEDBACK, self::STATUS_NOTSATISFACTORY]);
            $result[$userid] = $cell;
        }
        return $result;
    }

    /**
     * What each activity type knows about learners' submissions and marking.
     *
     * @param cm_info $cm
     * @param int[] $userids
     * @return array userid => stdClass: submitted (time), needsgrading, inprogress, gradevalue, gradedtime, feedback
     *               (bool), notreleased, attempt (text), attemptid
     */
    private static function get_facts(cm_info $cm, array $userids): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'mkf');
        $params['instance'] = $cm->instance;
        $facts = [];
        switch ($cm->modname) {
            case 'assign':
                $assign = $DB->get_record('assign', ['id' => $cm->instance], 'id, markingworkflow, maxattempts, teamsubmission');
                $rows = $DB->get_records_sql("SELECT s.userid, s.status, s.timemodified, s.attemptnumber,
                                                     g.id AS gradeid, g.grade, g.timemodified AS gradedtime,
                                                     f.workflowstate
                                                FROM {assign_submission} s
                                           LEFT JOIN {assign_grades} g ON g.assignment = s.assignment
                                                     AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber
                                           LEFT JOIN {assign_user_flags} f ON f.assignment = s.assignment
                                                     AND f.userid = s.userid
                                               WHERE s.assignment = :instance AND s.latest = 1 AND s.groupid = 0
                                                     AND s.userid {$insql}", $params);
                $gradeids = array_filter(array_map(fn($r) => (int) $r->gradeid, $rows));
                $comments = [];
                if ($gradeids && $DB->get_manager()->table_exists('assignfeedback_comments')) {
                    [$gsql, $gparams] = $DB->get_in_or_equal($gradeids, SQL_PARAMS_NAMED, 'mkg');
                    foreach (
                        $DB->get_records_select(
                            'assignfeedback_comments',
                            "grade {$gsql}",
                            $gparams,
                            '',
                            'grade, commenttext'
                        ) as $comment
                    ) {
                        if (trim(strip_tags((string) $comment->commenttext)) !== '') {
                            $comments[(int) $comment->grade] = true;
                        }
                    }
                }
                foreach ($rows as $row) {
                    $graded = $row->grade !== null && (float) $row->grade >= 0;
                    $max = (int) $assign->maxattempts;
                    $facts[(int) $row->userid] = (object) [
                        'submitted' => $row->status === 'submitted' ? (int) $row->timemodified : null,
                        'gradevalue' => $graded ? (float) $row->grade : null,
                        'gradedtime' => $graded ? (int) $row->gradedtime : null,
                        'feedback' => isset($comments[(int) $row->gradeid]),
                        'notreleased' => $assign->markingworkflow && $graded
                            && $row->workflowstate !== 'released',
                        'attempt' => get_string('markingattempt', 'report_evidencereporter', (object) [
                            'no' => (int) $row->attemptnumber + 1, 'max' => $max > 0 ? $max : '∞']),
                        'attemptno' => (int) $row->attemptnumber + 1,
                        'maxattempts' => $max > 0 ? $max : 0,
                    ];
                }
                break;
            case 'quiz':
                $rows = $DB->get_records_sql("SELECT qa.id, qa.userid, qa.attempt, qa.state, qa.timefinish, qa.sumgrades,
                                                     qa.timemodified, qa.uniqueid
                                                FROM {quiz_attempts} qa
                                               WHERE qa.quiz = :instance AND qa.preview = 0 AND qa.userid {$insql}
                                            ORDER BY qa.userid, qa.attempt", $params);
                $quiz = $DB->get_record('quiz', ['id' => $cm->instance], 'id, attempts');
                $latest = [];
                foreach ($rows as $row) {
                    $latest[(int) $row->userid] = $row;
                }
                foreach ($latest as $userid => $row) {
                    $finished = $row->state === 'finished';
                    $facts[$userid] = (object) [
                        'submitted' => $finished && $row->sumgrades === null ? (int) $row->timefinish : null,
                        'needsgrading' => $finished && $row->sumgrades === null,
                        'inprogress' => in_array($row->state, ['inprogress', 'overdue']),
                        'feedback' => $finished && self::quiz_has_comment((int) $row->uniqueid),
                        'attemptid' => (int) $row->id,
                        'attempt' => get_string('markingattempt', 'report_evidencereporter', (object) [
                            'no' => (int) $row->attempt, 'max' => (int) $quiz->attempts > 0 ? (int) $quiz->attempts : '∞']),
                        'attemptno' => (int) $row->attempt,
                        'maxattempts' => max(0, (int) $quiz->attempts),
                    ];
                }
                break;
            case 'workshop':
                foreach (
                    $DB->get_records_sql("SELECT authorid AS userid, MAX(timemodified) AS submitted
                                                 FROM {workshop_submissions}
                                                WHERE workshopid = :instance AND example = 0 AND authorid {$insql}
                                             GROUP BY authorid", $params) as $row
                ) {
                    $facts[(int) $row->userid] = (object) ['submitted' => (int) $row->submitted];
                }
                break;
            case 'forum':
                foreach (
                    $DB->get_records_sql("SELECT p.userid, MAX(p.created) AS submitted
                                                 FROM {forum_posts} p
                                                 JOIN {forum_discussions} d ON d.id = p.discussion
                                                WHERE d.forum = :instance AND p.userid {$insql}
                                             GROUP BY p.userid", $params) as $row
                ) {
                    $facts[(int) $row->userid] = (object) ['submitted' => (int) $row->submitted];
                }
                break;
            case 'data':
                foreach (
                    $DB->get_records_sql("SELECT userid, MAX(timemodified) AS submitted
                                                 FROM {data_records}
                                                WHERE dataid = :instance AND userid {$insql}
                                             GROUP BY userid", $params) as $row
                ) {
                    $facts[(int) $row->userid] = (object) ['submitted' => (int) $row->submitted];
                }
                break;
        }
        return $facts;
    }

    /**
     * Has a marker commented on any question in a quiz attempt?
     *
     * @param int $usageid
     * @return bool
     */
    private static function quiz_has_comment(int $usageid): bool {
        global $DB;
        $comments = $DB->get_fieldset_sql("SELECT d.value
                                             FROM {question_attempts} qa
                                             JOIN {question_attempt_steps} s ON s.questionattemptid = qa.id
                                             JOIN {question_attempt_step_data} d ON d.attemptstepid = s.id
                                            WHERE qa.questionusageid = :usage AND d.name = '-comment'", ['usage' => $usageid]);
        foreach ($comments as $comment) {
            if (trim(strip_tags((string) $comment)) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Where to mark one learner's work in an activity.
     *
     * @param cm_info $cm
     * @param int $userid
     * @param stdClass $fact From {@see get_facts()}
     * @return moodle_url
     */
    public static function marking_url(cm_info $cm, int $userid, stdClass $fact): moodle_url {
        switch ($cm->modname) {
            case 'assign':
                return new moodle_url('/mod/assign/view.php', ['id' => $cm->id, 'action' => 'grader', 'userid' => $userid]);
            case 'quiz':
                if (!empty($fact->needsgrading)) {
                    return new moodle_url('/mod/quiz/report.php', ['id' => $cm->id, 'mode' => 'grading']);
                }
                return !empty($fact->attemptid) ? new moodle_url('/mod/quiz/review.php', ['attempt' => $fact->attemptid])
                    : new moodle_url('/mod/quiz/view.php', ['id' => $cm->id]);
            default:
                return new moodle_url('/grade/report/singleview/index.php', ['id' => $cm->course, 'item' => 'user',
                    'itemid' => $userid]);
        }
    }

    /**
     * The marking grid for a course: assessments as columns, learners as rows, most urgent first.
     *
     * @param int $courseid
     * @param int|null $unitid One unit, or null for every unit the course delivers
     * @param int $groupid
     * @return stdClass units, unitid, assessments (cmid => stdClass cm, unitids), rows (stdClass userid, user, cells
     *                  cmid => cell, priority, maxdays, unit (for one unit: rated text, ready bool, url), ready (units
     *                  ready to sign off), planurl, summaryurl), counts (see {@see count_cells()})
     */
    public static function build(int $courseid, ?int $unitid = null, int $groupid = 0): stdClass {
        global $DB;
        $units = self::get_units($courseid);
        if ($unitid !== null && !isset($units[$unitid])) {
            $unitid = null;
        }
        $unitids = $unitid !== null ? [$unitid] : array_keys($units);
        $assessments = self::get_assessments($courseid, $unitids);
        $learners = self::get_learners($courseid, $groupid);
        $cells = [];
        foreach ($assessments as $cmid => $assessment) {
            $cells[$cmid] = self::get_cells($assessment->cm, array_keys($learners));
        }
        $ratings = [];
        if ($learners && $unitids) {
            [$usql, $uparams] = $DB->get_in_or_equal(array_keys($learners), SQL_PARAMS_NAMED, 'mbu');
            [$csql, $cparams] = $DB->get_in_or_equal($unitids, SQL_PARAMS_NAMED, 'mbc');
            foreach (
                $DB->get_recordset_sql(
                    "SELECT id, userid, competencyid, grade, proficiency
                                               FROM {competency_usercompcourse}
                                              WHERE courseid = :courseid AND userid {$usql} AND competencyid {$csql}",
                    $uparams + $cparams + ['courseid' => $courseid]
                ) as $rating
            ) {
                $ratings[(int) $rating->userid][(int) $rating->competencyid] = $rating;
            }
        }
        $priority = [self::STATUS_FEEDBACK => 50, self::STATUS_NOTRELEASED => 20];
        $agepriority = [self::AGE_RED => 40, self::AGE_AMBER => 30, self::AGE_NEW => 10];
        $rows = [];
        foreach ($learners as $userid => $learner) {
            $row = (object) ['userid' => (int) $userid, 'user' => $learner, 'cells' => [], 'priority' => 0, 'maxdays' => 0,
                'ready' => [], 'unit' => null];
            foreach ($assessments as $cmid => $assessment) {
                $cell = $cells[$cmid][$userid];
                $row->cells[$cmid] = $cell;
                $p = $cell->status === self::STATUS_TOMARK ? $agepriority[$cell->age] : ($priority[$cell->status] ?? 0);
                $row->priority = max($row->priority, $p);
                $row->maxdays = max($row->maxdays, (int) $cell->days);
            }
            foreach ($unitids as $uid) {
                $unitcells = array_filter(
                    $row->cells,
                    fn($cell, $cmid) => in_array($uid, $assessments[$cmid]->unitids),
                    ARRAY_FILTER_USE_BOTH
                );
                $rating = $ratings[$userid][$uid] ?? null;
                $rated = $rating && $rating->grade !== null;
                $allsat = $unitcells && !array_filter($unitcells, fn($c) => $c->status !== self::STATUS_SATISFACTORY);
                if ($allsat && !$rated) {
                    $row->ready[] = $uid;
                    $row->priority = max($row->priority, 15);
                }
                if ($unitid !== null) {
                    $row->unit = (object) [
                        'rated' => $rated ? self::rating_text($rating, (int) $uid) : '',
                        'ready' => $allsat && !$rated,
                        'url' => (new moodle_url('/admin/tool/lp/user_competency_in_course.php', ['courseid' => $courseid,
                            'competencyid' => $uid, 'userid' => $userid]))->out(false),
                    ];
                }
            }
            $rows[] = $row;
        }
        usort($rows, fn($a, $b) => [$b->priority, $b->maxdays, $a->user->lastname, $a->user->firstname]
            <=> [$a->priority, $a->maxdays, $b->user->lastname, $b->user->firstname]);
        return (object) ['units' => $units, 'unitid' => $unitid, 'assessments' => $assessments, 'rows' => $rows,
            'counts' => self::count_cells($rows)];
    }

    /**
     * A unit's rating in the course, as text.
     *
     * @param stdClass $rating competency_usercompcourse record
     * @param int $competencyid
     * @return string
     */
    private static function rating_text(stdClass $rating, int $competencyid): string {
        $competency = new \core_competency\competency($competencyid);
        $scale = $competency->get_scale();
        $items = $scale ? $scale->load_items() : [];
        return $items[(int) $rating->grade - 1] ?? (string) $rating->grade;
    }

    /**
     * Totals for a grid's rows.
     *
     * @param stdClass[] $rows
     * @return stdClass tomark, amber, red (waiting past the thresholds), feedback, notreleased, toconfirm (Moodle
     *                  adjustments not yet recorded), ready (units ready to sign off)
     */
    public static function count_cells(array $rows): stdClass {
        $counts = (object) ['tomark' => 0, 'amber' => 0, 'red' => 0, 'feedback' => 0, 'notreleased' => 0,
            'toconfirm' => 0, 'ready' => 0];
        foreach ($rows as $row) {
            foreach ($row->cells as $cell) {
                if ($cell->status === self::STATUS_TOMARK) {
                    $counts->tomark++;
                    if ($cell->age === self::AGE_AMBER) {
                        $counts->amber++;
                    } else if ($cell->age === self::AGE_RED) {
                        $counts->red++;
                    }
                } else if ($cell->status === self::STATUS_FEEDBACK) {
                    $counts->feedback++;
                } else if ($cell->status === self::STATUS_NOTRELEASED) {
                    $counts->notreleased++;
                }
                $counts->toconfirm += (int) $cell->toconfirm;
            }
            $counts->ready += count($row->ready);
        }
        return $counts;
    }

    /**
     * Totals for every course the current user marks in, for the overview and the Dashboard nudge.
     *
     * @return stdClass[] courseid => stdClass (course, counts); and the total under key 0 (counts only)
     */
    public static function get_overview(): array {
        $result = [];
        $total = self::count_cells([]);
        foreach (self::get_my_courses() as $courseid => $course) {
            $counts = self::build($courseid)->counts;
            $result[$courseid] = (object) ['course' => $course, 'counts' => $counts];
            foreach ((array) $counts as $key => $value) {
                $total->$key += $value;
            }
        }
        $result[0] = (object) ['course' => null, 'counts' => $total];
        return $result;
    }
}
