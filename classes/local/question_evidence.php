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

use stdClass;

/**
 * Supporting evidence from quiz questions mapped to competencies (with Question competencies, qbank_competencies, or
 * Question Bank Competency Mapping, qbank_comp_ext: see {@see question_mappings}).
 *
 * Each result sits under the quiz attempt it came from, with the whole quiz's result, because a question is only ever
 * supporting evidence: it never rates the competency. A question passed in a quiz that wasn't satisfactory is flagged,
 * as is a question not passed in a satisfactory quiz, and a quiz that isn't itself linked to the competency.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_evidence {
    /**
     * Is a plugin that maps questions to competencies installed and enabled?
     *
     * @return bool
     */
    public static function is_available(): bool {
        return question_mappings::is_available();
    }

    /**
     * Raw results on mapped questions the current user may see, for one learner.
     *
     * @param int $userid
     * @param int[]|null $competencyids
     * @return stdClass[] See {@see question_mappings::get_user_results()}
     */
    private static function get_rows(int $userid, ?array $competencyids): array {
        if (!self::is_available()) {
            return [];
        }
        return question_mappings::get_user_results(
            $userid,
            $competencyids,
            access::get_sql('qza.userid', 'cm.course')
        );
    }

    /**
     * Competencies the learner has question results in (that the current user may see).
     *
     * @param int $userid
     * @return int[]
     */
    public static function get_competencyids(int $userid): array {
        return array_values(array_unique(array_map(fn($row) => (int) $row->competencyid, self::get_rows($userid, null))));
    }

    /**
     * Question results grouped by competency, then by quiz attempt (oldest first).
     *
     * @param int $userid
     * @param int[] $competencyids
     * @param bool $viewfullnames
     * @return array competencyid => stdClass[] groups: activity, cmid, attemptid, label, islatest, grade, quizresult,
     *               quizoutcome ('pass'|'fail'|''), visible, notlinked, flags string[], questions stdClass[] (number, name,
     *               mark, outcome, outcomekey, flags string[], reviewurl, commenturl)
     */
    public static function get_for_user(int $userid, array $competencyids, bool $viewfullnames): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        if (!$competencyids) {
            return [];
        }
        $rows = self::get_rows($userid, $competencyids);
        if (!$rows) {
            return [];
        }

        // The quiz attempts, formatted the way the rest of the summary shows them (grade, pass/fail, release rules).
        $cmids = array_values(array_unique(array_map(fn($row) => $row->cmid, $rows)));
        $attempts = [];
        foreach (attempts::get_for_user($userid, $cmids) as $cmattempts) {
            foreach ($cmattempts as $raw) {
                if ($raw->modname === 'quiz') {
                    $attempts[(int) $raw->attemptid] = attempts::format($raw, $viewfullnames);
                }
            }
        }

        $competency = \core_text::strtolower(terms::get('competency'));
        $groups = [];
        $quizattempts = [];
        $cangrade = [];
        foreach ($rows as $row) {
            $key = $row->competencyid . ':' . $row->attemptid;
            if (!isset($groups[$key])) {
                $attempt = $attempts[$row->attemptid] ?? null;
                $visible = $attempt && ($attempt->released || $attempt->canseeunreleased);
                $quizoutcome = $visible ? $attempt->outcome : '';
                $flags = [];
                if (!$row->linkedatquiz) {
                    $flags[] = get_string('qflag_notlinkedatquiz', 'report_evidencereporter', $competency);
                }
                try {
                    $activity = get_fast_modinfo($row->courseid)->get_cm($row->cmid)->get_formatted_name();
                } catch (\Throwable $e) {
                    $activity = '';
                }
                $groups[$key] = (object) [
                    'competencyid' => $row->competencyid,
                    'activity' => $activity,
                    'cmid' => $row->cmid,
                    'attemptid' => $row->attemptid,
                    'label' => get_string('attemptsummary', 'report_evidencereporter', $row->attemptno)
                        . ($row->islatest ? ' ' . get_string('latestattemptsuffix', 'report_evidencereporter') : ''),
                    'islatest' => $row->islatest,
                    'grade' => $attempt ? $attempt->grade : '',
                    'quizoutcome' => $quizoutcome,
                    'quizresult' => match ($quizoutcome) {
                        'pass' => terms::get('satisfactory'),
                        'fail' => terms::get('notsatisfactory'),
                        default => '',
                    },
                    'visible' => $visible,
                    'notlinked' => !$row->linkedatquiz,
                    'flags' => $flags,
                    'questions' => [],
                ];
            }
            $group = $groups[$key];

            // Question number as the learner saw it (description items and custom numbering included).
            if (!array_key_exists($row->attemptid, $quizattempts)) {
                try {
                    $quizattempts[$row->attemptid] = \mod_quiz\quiz_attempt::create($row->attemptid);
                } catch (\Throwable $e) {
                    $quizattempts[$row->attemptid] = null;
                }
            }
            $number = $quizattempts[$row->attemptid]
                ? (string) $quizattempts[$row->attemptid]->get_question_number($row->slot) : (string) $row->slot;
            if (!isset($cangrade[$row->cmid])) {
                $cangrade[$row->cmid] = has_capability('mod/quiz:grade', \context_module::instance($row->cmid));
            }

            $outcomekey = $row->outcome;
            $qflags = [];
            if ($group->visible && $group->quizoutcome === 'fail' && $outcomekey === 'passed') {
                $qflags[] = get_string('qflag_passedinns', 'report_evidencereporter', terms::get('notsatisfactory'));
            } else if ($group->visible && $group->quizoutcome === 'pass' && $outcomekey === 'notpassed') {
                $qflags[] = get_string('qflag_notpassedins', 'report_evidencereporter', terms::get('satisfactory'));
            }
            $review = question_mappings::review_url($row->attemptid, $row->slot);
            $group->questions[] = (object) [
                'number' => $number,
                'name' => format_string($row->questionname, true, ['context' => \context_module::instance($row->cmid)]),
                'mark' => $group->visible && $row->mark !== null
                    ? format_float($row->mark, 2) . ' / ' . format_float($row->maxmark, 2) : '',
                'outcomekey' => $group->visible ? $outcomekey : 'pendingrelease',
                'outcome' => $group->visible ? get_string('qoutcome_' . $outcomekey, 'report_evidencereporter')
                    : get_string('pendingrelease', 'report_evidencereporter'),
                'flags' => $qflags,
                'reviewurl' => $review->out(false),
                'commenturl' => $cangrade[$row->cmid]
                    ? question_mappings::comment_url($row->attemptid, $row->slot)->out(false) : '',
            ];
        }

        $result = [];
        foreach ($groups as $group) {
            $result[$group->competencyid][] = $group;
        }
        return $result;
    }

    /**
     * Groups ready for the summary template.
     *
     * @param stdClass[] $groups From {@see get_for_user()}
     * @return array[]
     */
    public static function export(array $groups): array {
        return array_map(function (stdClass $group): array {
            $data = (array) $group;
            $data['flags'] = array_map(fn($flag) => ['text' => $flag], $group->flags);
            $data['hasflags'] = !empty($group->flags);
            $data['questions'] = array_map(function (stdClass $question): array {
                $q = (array) $question;
                $q['flags'] = array_map(fn($flag) => ['text' => $flag], $question->flags);
                $q['passed'] = $question->outcomekey === 'passed';
                return $q;
            }, $group->questions);
            return $data;
        }, $groups);
    }
}
