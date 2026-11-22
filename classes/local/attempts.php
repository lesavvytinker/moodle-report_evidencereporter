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

use moodle_url;
use stdClass;

/**
 * Every assessed attempt a learner made at an assignment or quiz, with that attempt's own grade, marker and feedback.
 *
 * Competency evidence only records an activity once, when its completion first changes to complete, so failed and
 * earlier attempts never become evidence. This reads them from the activities themselves:
 *  - assignments: one row per graded attempt (assign_grades), with its feedback comments and marker;
 *  - database activities: one row per rated entry (whatever the rating), with all the entry's comments as feedback;
 *  - quizzes: one row per finished attempt, with its scaled grade and a link to the attempt review, where the
 *    learner's answers and all feedback (specific, general, overall and marker comments) are on one page.
 *
 * Release rules match the rest of the plugin. Learners (and anyone without access to unreleased grades) only see what
 * the activity itself would show them: assignment grades once released (earlier attempts count as released, since
 * reopening an attempt releases the previous one); quiz marks and feedback according to the quiz's review options;
 * nothing while the gradebook item or grade is hidden.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempts {
    /** @var array Raw attempts cached by learner and activities. */
    private static array $byuser = [];

    /** @var stdClass[] Formatted attempts cached by viewer, module and attempt ID. */
    private static array $formatted = [];

    /**
     * SQL fragments shared by the report source and the PHP queries.
     *
     * Aliases used: cm (course_modules), m (modules). Adds: a, ag, asub, afc, q, qa, gi, gg, auf, gu.
     *
     * @param string $cm Alias of course_modules
     * @param string $m Alias of modules
     * @param array $aliases Map of logical name => alias to use
     * @return array [joins string[], learner id SQL, fields (alias => SQL)]
     */
    public static function get_sql_parts(string $cm, string $m, array $aliases): array {
        ['a' => $a, 'ag' => $ag, 'asub' => $asub, 'afc' => $afc, 'q' => $q, 'qa' => $qa, 'gi' => $gi, 'gg' => $gg,
            'auf' => $auf, 'gu' => $gu, 'd' => $d, 'dr' => $dr, 'h' => $h, 'ha' => $ha, 's' => $s, 'sa' => $sa] = $aliases;
        $learner = "COALESCE({$ag}.userid, {$qa}.userid, {$dr}.userid, {$ha}.userid, {$sa}.userid)";
        $joins = [
            "LEFT JOIN {assign} {$a} ON {$m}.name = 'assign' AND {$a}.id = {$cm}.instance",
            "LEFT JOIN {assign_grades} {$ag} ON {$ag}.assignment = {$a}.id AND {$ag}.grade >= 0",
            "LEFT JOIN {assign_submission} {$asub} ON {$asub}.assignment = {$ag}.assignment AND {$asub}.userid = {$ag}.userid
                                                AND {$asub}.attemptnumber = {$ag}.attemptnumber AND {$asub}.groupid = 0",
            "LEFT JOIN {assignfeedback_comments} {$afc} ON {$afc}.grade = {$ag}.id",
            "LEFT JOIN {quiz} {$q} ON {$m}.name = 'quiz' AND {$q}.id = {$cm}.instance",
            "LEFT JOIN {quiz_attempts} {$qa} ON {$qa}.quiz = {$q}.id AND {$qa}.state = 'finished' AND {$qa}.preview = 0",
            "LEFT JOIN {data} {$d} ON {$m}.name = 'data' AND {$d}.id = {$cm}.instance",
            // Every rated database entry counts as an assessment, whatever its rating.
            "LEFT JOIN {data_records} {$dr} ON {$dr}.dataid = {$d}.id AND " . data_entries::rated_sql("{$dr}.id", 'errj'),
            "LEFT JOIN {h5pactivity} {$h} ON {$m}.name = 'h5pactivity' AND {$h}.id = {$cm}.instance",
            "LEFT JOIN {h5pactivity_attempts} {$ha} ON {$ha}.h5pactivityid = {$h}.id",
            "LEFT JOIN {scorm} {$s} ON {$m}.name = 'scorm' AND {$s}.id = {$cm}.instance",
            // A SCORM attempt counts once the package has recorded something for it.
            "LEFT JOIN {scorm_attempt} {$sa} ON {$sa}.scormid = {$s}.id
                                         AND EXISTS (SELECT 1 FROM {scorm_scoes_value} ersv WHERE ersv.attemptid = {$sa}.id)",
            "LEFT JOIN {grade_items} {$gi} ON {$gi}.courseid = {$cm}.course AND {$gi}.itemtype = 'mod'
                                     AND {$gi}.itemmodule = {$m}.name AND {$gi}.iteminstance = {$cm}.instance
                                     AND {$gi}.itemnumber = 0",
            "LEFT JOIN {grade_grades} {$gg} ON {$gg}.itemid = {$gi}.id AND {$gg}.userid = {$learner}",
            "LEFT JOIN {assign_user_flags} {$auf} ON {$auf}.assignment = {$a}.id AND {$auf}.userid = {$ag}.userid",
            "LEFT JOIN {user} {$gu} ON {$gu}.id = {$ag}.grader AND {$ag}.grader > 0",
        ];
        $latestag = "(SELECT MAX(erlag.attemptnumber) FROM {assign_grades} erlag
                       WHERE erlag.assignment = {$ag}.assignment AND erlag.userid = {$ag}.userid AND erlag.grade >= 0)";
        $latestqa = "(SELECT MAX(erlqa.attempt) FROM {quiz_attempts} erlqa
                       WHERE erlqa.quiz = {$qa}.quiz AND erlqa.userid = {$qa}.userid
                         AND erlqa.state = 'finished' AND erlqa.preview = 0)";
        $latestdr = "(SELECT MAX(erld.id) FROM {data_records} erld
                       WHERE erld.dataid = {$dr}.dataid AND erld.userid = {$dr}.userid
                         AND " . data_entries::rated_sql('erld.id', 'erldr') . ")";
        $latestha = "(SELECT MAX(erlha.attempt) FROM {h5pactivity_attempts} erlha
                       WHERE erlha.h5pactivityid = {$ha}.h5pactivityid AND erlha.userid = {$ha}.userid)";
        $latestsa = "(SELECT MAX(erlsa.attempt) FROM {scorm_attempt} erlsa
                       WHERE erlsa.scormid = {$sa}.scormid AND erlsa.userid = {$sa}.userid
                         AND EXISTS (SELECT 1 FROM {scorm_scoes_value} erlsv WHERE erlsv.attemptid = erlsa.id))";
        $scormtime = "(SELECT MAX(ersvt.timemodified) FROM {scorm_scoes_value} ersvt WHERE ersvt.attemptid = {$sa}.id)";
        $ratingsof = fn(string $agg) => "(SELECT {$agg} FROM {rating} erra
                                           WHERE erra.component = 'mod_data' AND erra.ratingarea = 'entry'
                                             AND erra.itemid = {$dr}.id)";
        $fields = [
            'modname' => "{$m}.name",
            'cmid' => "{$cm}.id",
            'courseid' => "{$cm}.course",
            'userid' => $learner,
            'attemptid' => "COALESCE({$ag}.id, {$qa}.id, {$dr}.id, {$ha}.id, {$sa}.id)",
            'attemptno' => "COALESCE({$ag}.attemptnumber + 1, {$qa}.attempt, {$ha}.attempt, {$sa}.attempt,
                (SELECT COUNT(1) FROM {data_records} erdc
                  WHERE erdc.dataid = {$dr}.dataid AND erdc.userid = {$dr}.userid AND erdc.id <= {$dr}.id))",
            'islatest' => "CASE WHEN {$ag}.id IS NOT NULL THEN
                               CASE WHEN {$ag}.attemptnumber = {$latestag} THEN 1 ELSE 0 END
                           WHEN {$qa}.id IS NOT NULL THEN
                               CASE WHEN {$qa}.attempt = {$latestqa} THEN 1 ELSE 0 END
                           WHEN {$dr}.id IS NOT NULL THEN
                               CASE WHEN {$dr}.id = {$latestdr} THEN 1 ELSE 0 END
                           WHEN {$ha}.id IS NOT NULL THEN
                               CASE WHEN {$ha}.attempt = {$latestha} THEN 1 ELSE 0 END
                           WHEN {$sa}.id IS NOT NULL THEN
                               CASE WHEN {$sa}.attempt = {$latestsa} THEN 1 ELSE 0 END
                           ELSE 0 END",
            'datarecordid' => "{$dr}.id",
            'assigngrade' => "{$ag}.grade",
            'quizsumgrades' => "{$qa}.sumgrades",
            'quizid' => "{$q}.id",
            'h5pactivityid' => "{$h}.id",
            'h5pscaled' => "{$ha}.scaled",
            'h5prawscore' => "{$ha}.rawscore",
            'h5pmaxscore' => "{$ha}.maxscore",
            'h5pduration' => "{$ha}.duration",
            'h5pcompletion' => "{$ha}.completion",
            'h5psuccess' => "{$ha}.success",
            'scormid' => "{$s}.id",
            'timesubmitted' => "CASE WHEN {$asub}.status = 'submitted' THEN {$asub}.timemodified
                                     WHEN {$qa}.id IS NOT NULL THEN {$qa}.timefinish
                                     WHEN {$dr}.id IS NOT NULL THEN {$dr}.timecreated
                                     WHEN {$ha}.id IS NOT NULL THEN {$ha}.timemodified
                                     WHEN {$sa}.id IS NOT NULL THEN {$scormtime} ELSE NULL END",
            'timegraded' => "COALESCE({$ag}.timemodified, {$qa}.timefinish, " . $ratingsof('MAX(erra.timemodified)') . ",
                {$ha}.timemodified, {$scormtime})",
            'feedbacktext' => "{$afc}.commenttext",
            'feedbackformat' => "{$afc}.commentformat",
            'gradeitemid' => "{$gi}.id",
            'gradeitemhidden' => "{$gi}.hidden",
            'gradehidden' => "{$gg}.hidden",
            'markingworkflow' => "{$a}.markingworkflow",
            'workflowstate' => "{$auf}.workflowstate",
            'graderid' => "{$gu}.id",
        ];
        foreach (\core_user\fields::get_name_fields() as $namefield) {
            $fields['g_' . $namefield] = "{$gu}.{$namefield}";
        }
        // Grade on the grade item's scale, and pass/fail against its grade to pass (for filtering).
        // Database entries: the entry's ratings combined the way the activity combines them (its aggregate type).
        $fields['gradevalue'] = "COALESCE({$ag}.grade,
            CASE WHEN {$q}.sumgrades > 0 THEN {$qa}.sumgrades * {$q}.grade / {$q}.sumgrades ELSE NULL END,
            CASE WHEN {$h}.grade > 0 AND {$ha}.id IS NOT NULL THEN {$ha}.scaled * {$h}.grade ELSE NULL END,
            CASE {$d}.assessed WHEN 2 THEN " . $ratingsof('COUNT(1)') . "
                               WHEN 3 THEN " . $ratingsof('MAX(erra.rating)') . "
                               WHEN 4 THEN " . $ratingsof('MIN(erra.rating)') . "
                               WHEN 5 THEN " . $ratingsof('SUM(erra.rating)') . "
                               ELSE " . $ratingsof('AVG(erra.rating)') . " END)";
        return [$joins, $learner, $fields];
    }

    /**
     * Default aliases for {@see get_sql_parts()}.
     *
     * @return array
     */
    public static function default_aliases(): array {
        $aliases = [];
        foreach (['a', 'ag', 'asub', 'afc', 'q', 'qa', 'gi', 'gg', 'auf', 'gu', 'd', 'dr', 'h', 'ha', 's', 'sa'] as $name) {
            $aliases[$name] = 'era' . $name;
        }
        return $aliases;
    }

    /**
     * All attempts by one learner at the given activities, oldest first, keyed by cmid.
     *
     * @param int $userid
     * @param int[] $cmids
     * @return array cmid => stdClass[] (raw rows, see {@see format()})
     */
    public static function get_for_user(int $userid, array $cmids): array {
        global $DB;
        $cmids = array_values(array_unique(array_filter(array_map('intval', $cmids))));
        if (!$cmids) {
            return [];
        }
        // One query per learner and activity set within a request (the report asks row by row).
        $cachekey = $userid . ':' . implode(',', $cmids);
        if (isset(self::$byuser[$cachekey])) {
            return self::$byuser[$cachekey];
        }
        [$joins, $learner, $fields] = self::get_sql_parts('eracm', 'eram', self::default_aliases());
        [$insql, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'eracmid');
        $params['learnerid'] = $userid;
        $params['learnerid2'] = $userid;
        $params['learnerid3'] = $userid;
        $params['learnerid4'] = $userid;
        $params['learnerid5'] = $userid;
        $select = implode(', ', array_map(fn($alias, $sql) => "{$sql} AS {$alias}", array_keys($fields), $fields));
        $sql = "SELECT {$select}
                  FROM {course_modules} eracm
                  JOIN {modules} eram ON eram.id = eracm.module
                  " . implode("\n", $joins) . "
                 WHERE eracm.id {$insql}
                   AND (eraag.userid = :learnerid OR eraqa.userid = :learnerid2 OR eradr.userid = :learnerid3
                        OR eraha.userid = :learnerid4 OR erasa.userid = :learnerid5)
              ORDER BY eracm.id, attemptno";
        $result = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            $result[(int) $row->cmid][] = $row;
        }
        $rs->close();
        self::$byuser[$cachekey] = $result;
        return $result;
    }

    /**
     * Turn a raw attempt row into display values, applying release rules for the current user.
     *
     * @param stdClass $row
     * @param bool $viewfullnames
     * @return stdClass attemptno, submitted, graded, grade, outcome ('pass'|'fail'|''), marker, feedback (HTML),
     *                  feedbackplain, released (bool), canseeunreleased (bool), url (moodle_url|null)
     */
    public static function format(stdClass $row, bool $viewfullnames): stdClass {
        global $USER;
        $key = "{$USER->id}:{$row->modname}:{$row->attemptid}";
        if (!isset(self::$formatted[$key])) {
            self::$formatted[$key] = self::format_uncached($row, $viewfullnames);
        }
        return self::$formatted[$key];
    }

    /**
     * The marker or raters of a formatted attempt, with redaction applied.
     *
     * @param stdClass $attempt From {@see format()}
     * @param identity $identity
     * @return string
     */
    public static function marker(stdClass $attempt, identity $identity): string {
        if ($attempt->isentry) {
            $names = array_map(fn($r) => $identity->person((int) $r->raterid, $r->rater), $attempt->ratings);
            return implode(', ', array_unique($names));
        }
        return $identity->staff($attempt->marker);
    }

    /**
     * Formatting work for {@see format()}.
     *
     * @param stdClass $row
     * @param bool $viewfullnames
     * @return stdClass
     */
    private static function format_uncached(stdClass $row, bool $viewfullnames): stdClass {
        $canseeunreleased = feedback_visibility::can_view_unreleased((int) $row->cmid, (string) $row->modname);
        $result = (object) [
            'attemptno' => (int) $row->attemptno,
            'submitted' => empty($row->timesubmitted) ? null : (int) $row->timesubmitted,
            'graded' => empty($row->timegraded) ? null : (int) $row->timegraded,
            'grade' => '',
            'outcome' => '',
            'marker' => '',
            'feedback' => '',
            'feedbackplain' => '',
            'released' => true,
            'canseeunreleased' => $canseeunreleased,
            'url' => null,
            'feedbacklink' => false,
            'isentry' => false,
            'ratings' => [],
            'comments' => [],
        ];

        // The activity type's own rules: grade value, link, marker, feedback and release.
        $source = sources::get((string) $row->modname);
        $parts = $source ? $source->format_attempt($row, $result, $canseeunreleased, $viewfullnames)
            : (object) ['value' => null, 'showmarks' => true, 'showfeedback' => true, 'overallfeedback' => ''];
        $value = $parts->value;
        $showmarks = $parts->showmarks;
        $showfeedback = $parts->showfeedback;
        $overallfeedback = $parts->overallfeedback;

        if ($value !== null) {
            if ($showmarks) {
                $result->grade = !empty($row->gradeitemid)
                    ? activity_grade::format_value((int) $row->gradeitemid, $value) : format_float($value, 2);
                $result->outcome = !empty($row->gradeitemid) ? activity_grade::outcome((int) $row->gradeitemid, $value) : '';
            } else {
                $result->grade = get_string('pendingrelease', 'report_evidencereporter');
            }
        }
        if (!empty($result->feedbacklink)) {
            // Quiz feedback lives on the attempt review page.
            if ($showfeedback) {
                $result->feedback = evidence_link::new_tab(
                    $result->url,
                    $result->feedbacklabel ?? get_string('viewattemptfeedback', 'report_evidencereporter'),
                    ['class' => 'er-feedbacklink']
                );
                $result->feedbackplain = get_string('feedbackinattempt', 'report_evidencereporter');
            } else {
                $result->feedback = s(get_string('pendingrelease', 'report_evidencereporter'));
                $result->feedbackplain = get_string('pendingrelease', 'report_evidencereporter');
            }
        } else if ($overallfeedback !== '') {
            if ($showfeedback) {
                $result->feedback = $overallfeedback;
                $result->feedbackplain = trim(html_to_text($overallfeedback, 0, false));
            } else {
                $result->feedback = s(get_string('pendingrelease', 'report_evidencereporter'));
                $result->feedbackplain = get_string('pendingrelease', 'report_evidencereporter');
            }
        }
        if (!$showmarks && !$canseeunreleased) {
            // Don't reveal who marked an unreleased attempt.
            $result->marker = '';
        }
        return $result;
    }

    /**
     * Reset caches (for unit tests).
     */
    public static function reset_cache(): void {
        source\quiz::reset_cache();
        source\h5pactivity::reset_cache();
        source\scorm::reset_cache();
        self::$formatted = [];
        self::$byuser = [];
    }
}
