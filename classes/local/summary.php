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

use core_competency\evidence;
use moodle_url;
use stdClass;

/**
 * Gathers one learner's competency evidence for one framework, for the printable evidence summary.
 *
 * Applies the same row access rules as the report ({@see access}), so a viewer only ever sees evidence they could
 * see in the report.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class summary {
    /**
     * Frameworks the learner has evidence in that the current user may see, keyed by ID.
     *
     * @param int $userid
     * @return stdClass[] id, shortname, idnumber, evidencecount
     */
    public static function get_frameworks(int $userid): array {
        global $DB;
        [$from, $where, $params] = self::get_base_sql($userid);
        $sql = "SELECT cf.id, cf.shortname, cf.idnumber, COUNT(ev.id) AS evidencecount
                  {$from}
                  JOIN {competency_framework} cf ON cf.id = c.competencyframeworkid
                 WHERE {$where}
              GROUP BY cf.id, cf.shortname, cf.idnumber
              ORDER BY cf.shortname";
        $frameworks = $DB->get_records_sql($sql, $params);

        // Frameworks where the learner only has assessment attempts (e.g. failed attempts never logged as evidence).
        $attemptframeworks = array_unique(array_column(self::get_linked_activities($userid), 'frameworkid'));
        foreach (array_diff($attemptframeworks, array_keys($frameworks)) as $frameworkid) {
            $framework = $DB->get_record('competency_framework', ['id' => $frameworkid], 'id, shortname, idnumber');
            $framework->evidencecount = 0;
            $frameworks[$framework->id] = $framework;
        }
        uasort($frameworks, fn($a, $b) => strcmp($a->shortname, $b->shortname));
        return $frameworks;
    }

    /**
     * Competencies the learner has evidence in, or assessed attempts on activities linked to them, that the current
     * user may see.
     *
     * @param int $userid
     * @return int[]
     */
    public static function get_assessed_competencyids(int $userid): array {
        global $DB;
        [$from, $where, $params] = self::get_base_sql($userid);
        $ids = $DB->get_fieldset_sql("SELECT DISTINCT c.id {$from} WHERE {$where}", $params);
        $ids = array_merge(
            $ids,
            array_column(self::get_linked_activities($userid), 'competencyid'),
            question_evidence::get_competencyids($userid)
        );
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Assignments and quizzes linked to competencies where the learner has assessed attempts, in courses the current
     * user may see.
     *
     * @param int $userid
     * @param int|null $frameworkid Limit to one framework
     * @param int[]|null $competencyids Limit to these competencies
     * @return stdClass[] id (module-competency link), competencyid, cmid, course, frameworkid
     */
    public static function get_linked_activities(int $userid, ?int $frameworkid = null, ?array $competencyids = null): array {
        global $DB;
        $sql = "SELECT mc.id, mc.competencyid, mc.cmid, cm.course, c.competencyframeworkid AS frameworkid, m.name AS modname
                  FROM {competency_modulecomp} mc
                  JOIN {competency} c ON c.id = mc.competencyid
                  JOIN {course_modules} cm ON cm.id = mc.cmid
                  JOIN {modules} m ON m.id = cm.module
                 WHERE ((m.name = 'assign' AND EXISTS (SELECT 1 FROM {assign_grades} lag
                                                        WHERE lag.assignment = cm.instance AND lag.userid = :u1
                                                          AND lag.grade >= 0))
                     OR (m.name = 'quiz' AND EXISTS (SELECT 1 FROM {quiz_attempts} lqa
                                                      WHERE lqa.quiz = cm.instance AND lqa.userid = :u2
                                                        AND lqa.state = 'finished' AND lqa.preview = 0))
                     OR (m.name = 'data' AND EXISTS (SELECT 1 FROM {data_records} ldr
                                                      WHERE ldr.dataid = cm.instance AND ldr.userid = :u3
                                                        AND " . data_entries::rated_sql('ldr.id', 'ldrt') . "))
                     OR (m.name = 'h5pactivity' AND EXISTS (SELECT 1 FROM {h5pactivity_attempts} lha
                                                             WHERE lha.h5pactivityid = cm.instance AND lha.userid = :u6))
                     OR (m.name = 'scorm' AND EXISTS (SELECT 1 FROM {scorm_attempt} lsa
                                                        JOIN {scorm_scoes_value} lsv ON lsv.attemptid = lsa.id
                                                       WHERE lsa.scormid = cm.instance AND lsa.userid = :u7))
                     OR (m.name = 'forum' AND (EXISTS (SELECT 1 FROM {forum_posts} lfp
                                                         JOIN {forum_discussions} lfd ON lfd.id = lfp.discussion
                                                        WHERE lfd.forum = cm.instance AND lfp.userid = :u4
                                                          AND lfp.deleted = 0)
                                            OR EXISTS (SELECT 1 FROM {forum_grades} lfg
                                                        WHERE lfg.forum = cm.instance AND lfg.userid = :u5
                                                          AND lfg.grade IS NOT NULL)))";
        // Activity types whose tables exist only when their plugin is installed (e.g. Attendance) add their own test.
        foreach (sources::all() as $source) {
            $condition = $source->get_activity_done_sql('cm.instance', $userid);
            if ($condition !== null && preg_match('/^[a-z0-9_]+$/', $source->get_modname())) {
                $sql .= "\n                     OR (m.name = '{$source->get_modname()}' AND ({$condition}))";
            }
        }
        $sql .= ")";
        $params = ['u1' => $userid, 'u2' => $userid, 'u3' => $userid, 'u4' => $userid, 'u5' => $userid, 'u6' => $userid,
            'u7' => $userid];
        if ($frameworkid) {
            $sql .= ' AND c.competencyframeworkid = :frameworkid';
            $params['frameworkid'] = $frameworkid;
        }
        if ($competencyids !== null) {
            if (!$competencyids) {
                return [];
            }
            [$insql, $inparams] = $DB->get_in_or_equal($competencyids, SQL_PARAMS_NAMED, 'lc');
            $sql .= " AND c.id {$insql}";
            $params += $inparams;
        }
        if ($access = access::get_sql((string) $userid, 'cm.course')) {
            $sql .= ' AND ' . $access[0];
            $params += $access[1];
        }
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Does the current user have access to any of this learner's evidence?
     *
     * @param int $userid
     * @return bool
     */
    public static function has_visible_evidence(int $userid): bool {
        global $DB;
        [$from, $where, $params] = self::get_base_sql($userid);
        return $DB->record_exists_sql("SELECT 1 {$from} WHERE {$where}", $params)
            || !empty(self::get_linked_activities($userid))
            || !empty(checklist_items::get_linked($userid))
            || !empty(question_evidence::get_competencyids($userid));
    }

    /**
     * Shared FROM / WHERE: evidence for the learner, restricted by row access.
     *
     * @param int $userid
     * @return array [$from, $where, $params]
     */
    private static function get_base_sql(int $userid): array {
        $modulelevel = CONTEXT_MODULE;
        $courselevel = CONTEXT_COURSE;
        $from = "FROM {competency_evidence} ev
                  JOIN {competency_usercomp} uc ON uc.id = ev.usercompetencyid
                  JOIN {competency} c ON c.id = uc.competencyid
             LEFT JOIN {context} ctx ON ctx.id = ev.contextid
             LEFT JOIN {course_modules} cm ON ctx.contextlevel = {$modulelevel} AND cm.id = ctx.instanceid
             LEFT JOIN {modules} m ON m.id = cm.module";
        $where = 'uc.userid = :learnerid';
        $params = ['learnerid' => $userid];
        $courseidsql = "COALESCE(cm.course, CASE WHEN ctx.contextlevel = {$courselevel} THEN ctx.instanceid ELSE NULL END)";
        if ($access = access::get_sql('uc.userid', $courseidsql)) {
            $where .= ' AND ' . $access[0];
            $params += $access[1];
        }
        return [$from, $where, $params];
    }

    /**
     * Build the summary for a learner: a framework, a learning plan, or their active plans.
     *
     * @param int $userid
     * @param scope|int $scope What to cover (a framework ID is short for that framework)
     * @param identity|null $identity Redaction for names and generated text
     * @return stdClass competencies (in order, each with ->evidence rows, ->attempts, ->records and ->questions: supporting
     *                  evidence from quiz questions, see {@see question_evidence::get_for_user()}), restricted (bool), scope
     */
    public static function build(int $userid, scope|int $scope, ?identity $identity = null): stdClass {
        global $DB, $USER;
        if (is_int($scope)) {
            $scope = scope::for_framework($userid, $scope);
        }
        $ordered = $scope->get_competencies();
        $competencyids = array_map(fn($comp) => (int) $comp->id, $ordered);
        if (!$competencyids) {
            return (object) ['competencies' => [], 'scope' => $scope,
                'restricted' => (int) $USER->id !== $userid && access::get_sql('uc.userid', 'NULL') !== null];
        }
        [$compinsql, $compparams] = $DB->get_in_or_equal($competencyids, SQL_PARAMS_NAMED, 'sc');
        // Fields the evidence sources need (e.g. the learner's latest quiz attempt or database entry).
        $sourcefields = implode(",\n", array_map(
            fn($name, $sql) => "{$sql} AS {$name}",
            array_keys(sources::row_fields_sql('m.name', 'cm.instance', 'uc.userid')),
            sources::row_fields_sql('m.name', 'cm.instance', 'uc.userid')
        )) ?: '1 AS nosourcefields';

        [$from, $where, $params] = self::get_base_sql($userid);
        $courselevel = CONTEXT_COURSE;
        $namefields = \core_user\fields::get_name_fields();
        $assessorfields = implode(', ', array_map(fn($f) => "au.{$f} AS a_{$f}", $namefields));
        $graderfields = implode(', ', array_map(fn($f) => "gu.{$f} AS g_{$f}", $namefields));

        $sql = "SELECT ev.id, ev.action, ev.timecreated, ev.descidentifier, ev.desccomponent, ev.desca, ev.url,
                       ev.grade, ev.note, uc.userid, uc.competencyid, uc.grade AS currentgrade, uc.proficiency,
                       cm.id AS cmid, m.name AS modname,
                       COALESCE(cm.course, CASE WHEN ctx.contextlevel = {$courselevel} THEN ctx.instanceid ELSE NULL END)
                           AS courseid,
                       gg.id AS gradeid, gi.id AS gradeitemid, gg.finalgrade, gg.feedback, gg.feedbackformat,
                       gg.hidden AS gradehidden, gi.hidden AS gradeitemhidden, asg.markingworkflow, auf.workflowstate,
                       au.id AS assessorid, {$assessorfields}, gu.id AS graderid, {$graderfields},
                       CASE WHEN ev.id = (SELECT MAX(ev2.id) FROM {competency_evidence} ev2
                                           WHERE ev2.usercompetencyid = ev.usercompetencyid
                                             AND ev2.contextid = ev.contextid) THEN 1 ELSE 0 END AS islatest,
                       {$sourcefields}
                  {$from}
             LEFT JOIN {grade_items} gi ON gi.courseid = cm.course AND gi.itemtype = 'mod' AND gi.itemmodule = m.name
                                       AND gi.iteminstance = cm.instance
                                       AND gi.itemnumber = " . activity_grade::itemnumber_sql('m.name', 'cm.instance', 'sgi') . "
             LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = uc.userid
             LEFT JOIN {assign} asg ON m.name = 'assign' AND asg.id = cm.instance
             LEFT JOIN {assign_user_flags} auf ON auf.assignment = asg.id AND auf.userid = uc.userid
             LEFT JOIN {user} au ON au.id = ev.actionuserid
             LEFT JOIN {user} gu ON gu.id = gg.usermodified AND gg.usermodified <> uc.userid AND gg.finalgrade IS NOT NULL
                 WHERE {$where} AND c.id {$compinsql}
              ORDER BY ev.timecreated, ev.id";
        $params += $compparams;
        $rows = $DB->get_records_sql($sql, $params);

        $viewfullnames = has_capability('moodle/site:viewfullnames', \context_system::instance());
        $evidencebycomp = [];
        $current = [];
        foreach ($rows as $row) {
            $evidencebycomp[$row->competencyid][] = self::format_row($row, $viewfullnames, $identity);
            $current[$row->competencyid] = $row;
        }

        // Assessed attempts (failed ones included) at activities linked to each competency, in courses the viewer may see.
        $attemptsbycomp = self::get_attempts($userid, $competencyids, $viewfullnames, $identity);

        // Records from activities that keep them instead of attempts: forum posts, attendance, workshop submissions.
        $recordsbycomp = self::get_records($userid, $competencyids, $viewfullnames, $identity);

        // Supporting evidence: results on quiz questions mapped to the competencies (Question competencies plugin).
        $questionsbycomp = question_evidence::get_for_user($userid, $competencyids, $viewfullnames);

        // Ratings frozen when a plan was completed take the place of current ones.
        $frozen = $scope->get_frozen_ratings();

        // Every competency covered, in order, so gaps show up.
        $competencies = [];
        foreach ($ordered as $comp) {
            $evidence = $evidencebycomp[$comp->id] ?? [];
            $judgement = null;
            foreach ($evidence as $item) {
                if ($item->isjudgement) {
                    $judgement = $item;
                }
            }
            $latest = $current[$comp->id] ?? null;
            if (isset($frozen[$comp->id])) {
                $currentrating = rating::name((int) $comp->id, $frozen[$comp->id]->grade);
                $isproficient = $frozen[$comp->id]->proficiency === 1;
            } else {
                $currentrating = $latest ? rating::name((int) $comp->id, $latest->currentgrade === null ? null
                    : (int) $latest->currentgrade) : '';
                $isproficient = $latest && (int) $latest->proficiency === 1;
            }
            $competencies[] = (object) [
                'id' => $comp->id,
                'idnumber' => $comp->idnumber,
                'name' => format_string($comp->shortname, true, ['context' => \context_system::instance()]),
                'depth' => $comp->depth,
                'group' => $comp->group ?? '',
                'frozen' => isset($frozen[$comp->id]),
                'currentrating' => $currentrating,
                'proficient' => $isproficient,
                'judgementdate' => $judgement ? $judgement->timecreated : null,
                'judgementby' => $judgement ? $judgement->assessor : '',
                'judgementrating' => $judgement ? $judgement->ratinggiven : '',
                'evidence' => $evidence,
                'attempts' => $attemptsbycomp[$comp->id] ?? [],
                'records' => $recordsbycomp[$comp->id] ?? [],
                'questions' => $questionsbycomp[$comp->id] ?? [],
            ];
        }

        return (object) [
            'competencies' => $competencies,
            'scope' => $scope,
            // Restricted: the viewer isn't the learner and doesn't have site-wide access.
            'restricted' => (int) $USER->id !== $userid && access::get_sql('uc.userid', 'NULL') !== null,
        ];
    }

    /**
     * Activity records (forum contributions, attendance, workshop submissions...) at activities linked to the
     * competencies, from each evidence source that keeps records rather than attempts, grouped by competency ID.
     *
     * @param int $userid
     * @param int[] $competencyids
     * @param bool $viewfullnames
     * @param identity|null $identity
     * @return array competencyid => stdClass[] (heading, activity, cmid, count, html)
     */
    private static function get_records(int $userid, array $competencyids, bool $viewfullnames, ?identity $identity): array {
        $linksbymod = [];
        foreach (self::get_linked_activities($userid, null, $competencyids) as $link) {
            $linksbymod[$link->modname][] = $link;
        }
        $result = [];
        foreach ($linksbymod as $modname => $links) {
            $source = sources::get((string) $modname);
            if (!$source) {
                continue;
            }
            foreach ($source->get_records($userid, $links, $viewfullnames, $identity) as $record) {
                $record->heading = $source->get_records_heading();
                $result[$record->competencyid][] = $record;
            }
        }
        return $result;
    }

    /**
     * Attempts at activities linked to the competencies, grouped by competency ID.
     *
     * @param int $userid
     * @param int[] $competencyids
     * @param bool $viewfullnames
     * @param identity|null $identity
     * @return array competencyid => stdClass[] (activity, attemptno, submitted, graded, grade, marker, feedback, url)
     */
    private static function get_attempts(int $userid, array $competencyids, bool $viewfullnames, ?identity $identity): array {
        $links = self::get_linked_activities($userid, null, $competencyids);
        if (!$links) {
            return [];
        }
        $byactivity = attempts::get_for_user($userid, array_column($links, 'cmid'));
        $datefmt = get_string('strftimedatetimeshort', 'core_langconfig');

        $result = [];
        foreach ($links as $link) {
            foreach ($byactivity[(int) $link->cmid] ?? [] as $raw) {
                $attempt = attempts::format($raw, $viewfullnames);
                try {
                    $cminfo = get_fast_modinfo((int) $link->course)->get_cm((int) $link->cmid);
                    $activity = $cminfo->get_formatted_name();
                } catch (\Throwable $e) {
                    $activity = '';
                }
                $result[$link->competencyid][] = (object) [
                    'activity' => $activity,
                    'label' => get_string(
                        $attempt->isentry ? 'entrysummary' : 'attemptsummary',
                        'report_evidencereporter',
                        $attempt->attemptno
                    ),
                    'attemptno' => $attempt->attemptno,
                    'submitted' => $attempt->submitted ? userdate($attempt->submitted, $datefmt) : '',
                    'graded' => $attempt->graded ? userdate($attempt->graded, $datefmt) : '',
                    'grade' => $attempt->grade,
                    'marker' => $identity ? attempts::marker($attempt, $identity) : $attempt->marker,
                    'feedback' => evidence_link::new_tab_html(!$identity ? $attempt->feedback : ($attempt->isentry
                        ? data_entries::comments_html($attempt->comments, $identity) : $identity->text($attempt->feedback))),
                    'url' => $attempt->url ? $attempt->url->out(false) : '',
                ];
            }
        }
        return $result;
    }

    /**
     * Prepare one evidence row for display.
     *
     * @param stdClass $row
     * @param bool $viewfullnames
     * @param identity|null $identity
     * @return stdClass
     */
    private static function format_row(stdClass $row, bool $viewfullnames, ?identity $identity = null): stdClass {
        $types = [
            evidence::ACTION_LOG => get_string('actionlog', 'report_evidencereporter'),
            evidence::ACTION_COMPLETE => get_string('actioncomplete', 'report_evidencereporter'),
            evidence::ACTION_OVERRIDE => get_string('actionoverride', 'report_evidencereporter'),
        ];

        // Where the evidence came from.
        $source = '';
        if (!empty($row->cmid) && !empty($row->courseid)) {
            try {
                $cminfo = get_fast_modinfo((int) $row->courseid)->get_cm((int) $row->cmid);
                $source = $cminfo->get_formatted_name() . ' (' . get_string('modulename', 'mod_' . $cminfo->modname) . ')';
            } catch (\Throwable $e) {
                $source = '';
            }
        } else if (!empty($row->courseid)) {
            $course = get_course((int) $row->courseid);
            $source = format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]);
        }

        // Description string from core.
        $description = '';
        if (!empty($row->descidentifier) && get_string_manager()->string_exists($row->descidentifier, $row->desccomponent)) {
            $a = $row->desca === null || $row->desca === '' ? null : json_decode($row->desca);
            $description = get_string($row->descidentifier, $row->desccomponent, $a);
        }

        // Feedback, with the same release and superseded rules as the report (sources supply their own: database
        // entry comments, checklist marks).
        $fb = sources::feedback($row, $viewfullnames, $identity);
        $feedback = $fb->html;
        $releasestatus = in_array($fb->status, ['released', 'notreleased', 'pendingrelease'])
            ? get_string($fb->status, 'report_evidencereporter') : '';

        $url = evidence_link::get_url($row);
        $action = (int) $row->action;
        $assessor = assessor::name($row, $viewfullnames);
        if ($identity && $description !== '') {
            $description = $identity->text($description);
        }
        return (object) [
            'timecreated' => (int) $row->timecreated,
            'date' => userdate($row->timecreated, get_string('strftimedatetimeshort', 'core_langconfig')),
            'type' => $types[$action] ?? (string) $action,
            'isjudgement' => in_array($action, [evidence::ACTION_COMPLETE, evidence::ACTION_OVERRIDE]),
            'source' => $source,
            'description' => $description,
            'url' => $url ? $url->out(false) : '',
            'grade' => activity_grade::format($row),
            'gradeadjustment' => grade_adjustment::row_html($row, $identity),
            'similarity' => similarity::row_html($row),
            'similaritytext' => similarity::pack_text($row),
            'ratinggiven' => rating::name((int) $row->competencyid, $row->grade === null ? null : (int) $row->grade),
            'assessor' => $identity ? $identity->staff($assessor) : $assessor,
            'note' => $row->note === null ? '' : s($identity ? $identity->text($row->note) : $row->note),
            'feedback' => evidence_link::new_tab_html((string) $feedback),
            'releasestatus' => $releasestatus,
        ];
    }
}
