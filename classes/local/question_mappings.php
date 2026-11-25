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
 * Questions mapped to competencies, read from whichever question bank plugin holds the mappings: Question competencies
 * (qbank_competencies) or Question Bank Competency Mapping (qbank_comp_ext). Both can be installed; their mappings are
 * combined.
 *
 * Mappings are read per question bank entry, so a mapping on any version of a question counts for every version.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_mappings {
    /** @var string Question competencies: one row per question bank entry and competency. */
    public const QBANK_COMPETENCIES = 'qbank_competencies';

    /** @var string Question Bank Competency Mapping: one row per question version, course and competency. */
    public const QBANK_COMP_EXT = 'qbank_comp_ext';

    /** @var string Outcome: the question was answered well enough to pass. */
    public const PASSED = 'passed';
    /** @var string Outcome: answered, but not well enough. */
    public const NOTPASSED = 'notpassed';
    /** @var string Outcome: waiting for a marker. */
    public const PENDING = 'pending';
    /** @var string Outcome: not answered. */
    public const NOTANSWERED = 'notanswered';

    /** @var array|null Cache of installed sources, for this request. */
    private static ?array $sources = null;

    /**
     * The mapping plugins installed and enabled on this site.
     *
     * @return string[] Component names
     */
    public static function get_sources(): array {
        global $DB;
        if (self::$sources !== null) {
            return self::$sources;
        }
        $tables = [self::QBANK_COMPETENCIES => 'qbank_competencies', self::QBANK_COMP_EXT => 'qbank_comp_ext_qmap'];
        $found = [];
        foreach ($tables as $component => $table) {
            if (\core_component::get_component_directory($component) === null || !get_config($component, 'version')) {
                continue;
            }
            if (!\core\plugininfo\qbank::is_plugin_enabled($component)) {
                continue;
            }
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            $found[] = $component;
        }
        if (!PHPUNIT_TEST) {
            self::$sources = $found;
        }
        return $found;
    }

    /**
     * Is any mapping plugin installed and enabled?
     *
     * @return bool
     */
    public static function is_available(): bool {
        return (bool) self::get_sources();
    }

    /**
     * The plugins' names, for messages.
     *
     * @return string[]
     */
    public static function get_source_names(): array {
        return array_map(fn($component) => get_string('pluginname', $component), self::get_sources());
    }

    /**
     * A subquery of every mapping, as (questionbankentryid, competencyid) pairs, to use as a table: "{$sql} qcm".
     *
     * @return string|null SQL in brackets, with no parameters, or null with no mapping plugin
     */
    public static function get_sql(): ?string {
        $parts = [];
        foreach (self::get_sources() as $component) {
            if ($component === self::QBANK_COMPETENCIES) {
                $parts[] = "SELECT qbcm.questionbankentryid, qbcm.competencyid FROM {qbank_competencies} qbcm";
            } else if ($component === self::QBANK_COMP_EXT) {
                $parts[] = "SELECT qbxv.questionbankentryid, qbxm.competencyid
                              FROM {qbank_comp_ext_qmap} qbxm
                              JOIN {question_versions} qbxv ON qbxv.questionid = qbxm.questionid";
            }
        }
        return $parts ? '(' . implode(' UNION ', $parts) . ')' : null;
    }

    /**
     * The question bank entry of a question (any version).
     *
     * @param int $questionid
     * @return int 0 if the question doesn't exist
     */
    public static function get_entryid(int $questionid): int {
        global $DB;
        return (int) $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $questionid]);
    }

    /**
     * Competencies mapped to question bank entries.
     *
     * @param int[] $entryids
     * @return array entryid => [competencyid => stdClass (id, idnumber, shortname, competencyframeworkid)]
     */
    public static function get_for_entries(array $entryids): array {
        global $DB;
        $entryids = array_values(array_unique(array_filter(array_map('intval', $entryids))));
        $mappings = self::get_sql();
        if (!$entryids || $mappings === null) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($entryids, SQL_PARAMS_NAMED, 'qme');
        $result = [];
        $rs = $DB->get_recordset_sql("SELECT qcm.questionbankentryid, c.id, c.idnumber, c.shortname,
                                             c.competencyframeworkid, c.path, c.sortorder
                                        FROM {$mappings} qcm
                                        JOIN {competency} c ON c.id = qcm.competencyid
                                       WHERE qcm.questionbankentryid {$insql}
                                    ORDER BY c.competencyframeworkid, c.path, c.sortorder, c.id", $params);
        foreach ($rs as $row) {
            $entryid = (int) $row->questionbankentryid;
            unset($row->questionbankentryid, $row->path, $row->sortorder);
            $result[$entryid][(int) $row->id] = $row;
        }
        $rs->close();
        return $result;
    }

    /**
     * A competency's label: its ID number and short name.
     *
     * @param stdClass $competency With idnumber and shortname
     * @return string
     */
    public static function label(stdClass $competency): string {
        $name = format_string($competency->shortname, true, ['context' => \context_system::instance(), 'escape' => false]);
        $idnumber = trim((string) $competency->idnumber);
        return $idnumber !== '' && $idnumber !== $name ? $idnumber . ' ' . $name : $name;
    }

    /**
     * The mark (fraction of the question's marks) a question needs to count as passed: Question competencies' setting
     * when it's installed, otherwise full marks.
     *
     * @return float
     */
    public static function get_pass_fraction(): float {
        $value = in_array(self::QBANK_COMPETENCIES, self::get_sources())
            ? get_config(self::QBANK_COMPETENCIES, 'passfraction') : false;
        return ($value === false || $value === '' || $value === null) ? 1.0 : max(0.0, min(1.0, (float) $value));
    }

    /**
     * The outcome of one question in an attempt.
     *
     * @param float|string|null $fraction The fraction from the question's latest step
     * @param string $state The question's latest state (e.g. gradedright, needsgrading)
     * @return string One of the PASSED, NOTPASSED, PENDING, NOTANSWERED constants
     */
    public static function outcome($fraction, string $state): string {
        if ($fraction === null || $fraction === '') {
            if ($state === 'needsgrading') {
                return self::PENDING;
            }
            if (in_array($state, ['gaveup', 'mangaveup'])) {
                return self::NOTPASSED;
            }
            return self::NOTANSWERED;
        }
        return ((float) $fraction + 0.0000001 >= self::get_pass_fraction()) ? self::PASSED : self::NOTPASSED;
    }

    /**
     * One learner's results on mapped questions in finished quiz attempts (previews excluded), oldest first.
     *
     * Every quiz is included, whether or not the quiz itself is linked to the competency.
     *
     * @param int $userid
     * @param int[]|null $competencyids Limit to these competencies
     * @param array|null $condition Extra [SQL, params] restriction; aliases available: qza (quiz_attempts), cm
     *                              (course_modules), qcm (the mapping)
     * @return stdClass[] competencyid, cmid, courseid, quizid, attemptid, attemptno, timefinish, sumgrades, slot,
     *                    questionid, entryid, questionname, maxmark, fraction, state, mark (null if not marked),
     *                    outcome (see {@see outcome()}), linkedatquiz (bool), islatest (bool)
     */
    public static function get_user_results(int $userid, ?array $competencyids = null, ?array $condition = null): array {
        global $DB;
        $mappings = self::get_sql();
        if ($mappings === null || ($competencyids !== null && !$competencyids)) {
            return [];
        }
        $params = ['userid' => $userid];
        $where = "qza.userid = :userid AND qza.state = 'finished' AND qza.preview = 0";
        if ($competencyids !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_map('intval', $competencyids), SQL_PARAMS_NAMED, 'qmc');
            $where .= " AND qcm.competencyid {$insql}";
            $params += $inparams;
        }
        if ($condition) {
            $where .= ' AND (' . $condition[0] . ')';
            $params += $condition[1];
        }
        $sql = "SELECT qcm.competencyid, cm.id AS cmid, cm.course AS courseid, qz.id AS quizid, qza.id AS attemptid,
                       qza.attempt AS attemptno, qza.timefinish, qza.sumgrades, qat.slot, qat.questionid,
                       qv.questionbankentryid AS entryid, q.name AS questionname, qat.maxmark, qas.fraction, qas.state,
                       CASE WHEN EXISTS (SELECT 1 FROM {competency_modulecomp} qmc
                                          WHERE qmc.cmid = cm.id AND qmc.competencyid = qcm.competencyid)
                            THEN 1 ELSE 0 END AS linkedatquiz,
                       CASE WHEN qza.attempt = (SELECT MAX(qza2.attempt) FROM {quiz_attempts} qza2
                                                 WHERE qza2.quiz = qza.quiz AND qza2.userid = qza.userid
                                                   AND qza2.state = 'finished' AND qza2.preview = 0)
                            THEN 1 ELSE 0 END AS islatest
                  FROM {quiz_attempts} qza
                  JOIN {quiz} qz ON qz.id = qza.quiz
                  JOIN {modules} m ON m.name = 'quiz'
                  JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = qz.id
                  JOIN {question_attempts} qat ON qat.questionusageid = qza.uniqueid
                  JOIN {question_versions} qv ON qv.questionid = qat.questionid
                  JOIN {$mappings} qcm ON qcm.questionbankentryid = qv.questionbankentryid
                  JOIN {question} q ON q.id = qat.questionid
                  JOIN {question_attempt_steps} qas ON qas.questionattemptid = qat.id
                       AND qas.sequencenumber = (SELECT MAX(qas2.sequencenumber) FROM {question_attempt_steps} qas2
                                                  WHERE qas2.questionattemptid = qat.id)
                 WHERE {$where}
              ORDER BY cm.course, cm.id, qza.attempt, qat.slot, qcm.competencyid";
        $result = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            foreach (
                ['competencyid', 'cmid', 'courseid', 'quizid', 'attemptid', 'attemptno', 'slot', 'questionid',
                    'entryid'] as $field
            ) {
                $row->$field = (int) $row->$field;
            }
            $row->maxmark = (float) $row->maxmark;
            $row->fraction = $row->fraction === null ? null : (float) $row->fraction;
            $row->mark = $row->fraction === null ? null : $row->fraction * $row->maxmark;
            $row->outcome = self::outcome($row->fraction, (string) $row->state);
            $row->linkedatquiz = (bool) $row->linkedatquiz;
            $row->islatest = (bool) $row->islatest;
            $result[] = $row;
        }
        $rs->close();
        return $result;
    }

    /**
     * Link to one question in a learner's attempt (the quiz's own rules decide what the viewer may see).
     *
     * @param int $attemptid
     * @param int $slot
     * @return moodle_url
     */
    public static function review_url(int $attemptid, int $slot): moodle_url {
        return new moodle_url('/mod/quiz/reviewquestion.php', ['attempt' => $attemptid, 'slot' => $slot]);
    }

    /**
     * Link to comment on or override the mark of one question in an attempt.
     *
     * @param int $attemptid
     * @param int $slot
     * @return moodle_url
     */
    public static function comment_url(int $attemptid, int $slot): moodle_url {
        return new moodle_url('/mod/quiz/comment.php', ['attempt' => $attemptid, 'slot' => $slot]);
    }
}
