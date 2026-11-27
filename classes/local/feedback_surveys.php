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
use stdClass;

/**
 * Learner feedback on assessments, gathered with Moodle's Feedback activity.
 *
 * Each survey belongs to an assessment: set on the Feedback activity ("Feedback for"), or else read from its Restrict
 * access (available once a particular assessment is complete or graded).
 *
 * A learner's own record only says how many assessments they were offered feedback on: never whether they answered, or
 * what. Responses are collated across learners (by course, unit or category), with no names, and hidden when too few
 * learners answered to keep them confidential.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_surveys {
    /** @var string Surveys set to belong to an assessment on the Feedback activity's form. */
    public const TABLE = 'report_evidencereporter_fbk';

    /** @var string Form field prefix. */
    public const FIELD = 'erfeedbackfor';

    /** @var string[] Question types collated. */
    public const QUESTION_TYPES = ['multichoicerated', 'multichoice', 'numeric', 'textarea', 'textfield'];

    /**
     * The smallest number of responses shown (site setting; default 5).
     *
     * @return int
     */
    public static function get_min_responses(): int {
        $value = get_config('report_evidencereporter', 'feedbackmin');
        return $value === false || $value === null || $value === '' ? 5 : max(1, (int) $value);
    }

    /**
     * Which assessment each survey in a course belongs to.
     *
     * @param int $courseid
     * @return int[] Feedback cmid => assessment cmid
     */
    public static function get_links(int $courseid): array {
        global $DB;
        $modinfo = get_fast_modinfo($courseid, -1);
        $surveys = [];
        foreach ($modinfo->get_instances_of('feedback') as $cm) {
            if (!$cm->deletioninprogress) {
                $surveys[(int) $cm->id] = $cm;
            }
        }
        if (!$surveys) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($surveys), SQL_PARAMS_NAMED, 'fbk');
        $set = $DB->get_records_select_menu(self::TABLE, "cmid {$insql}", $params, '', 'cmid, assesscmid');
        $cms = $modinfo->get_cms();
        $links = [];
        foreach ($surveys as $cmid => $cm) {
            $assessment = (int) ($set[$cmid] ?? 0);
            if (!$assessment || !isset($cms[$assessment])) {
                $assessment = self::infer($cm);
            }
            if ($assessment && isset($cms[$assessment]) && !$cms[$assessment]->deletioninprogress) {
                $links[(int) $cmid] = $assessment;
            }
        }
        return $links;
    }

    /**
     * The assessment a survey's Restrict access waits for: the first activity it needs completed, or graded.
     *
     * @param cm_info $cm The Feedback activity
     * @return int The assessment's cmid, or 0
     */
    public static function infer(cm_info $cm): int {
        global $DB;
        if (empty($cm->availability)) {
            return 0;
        }
        $tree = json_decode($cm->availability);
        $completion = [];
        $grades = [];
        $walk = function ($node) use (&$walk, &$completion, &$grades) {
            foreach ($node->c ?? [] as $child) {
                if (isset($child->c)) {
                    $walk($child);
                } else if (($child->type ?? '') === 'completion' && !empty($child->cm) && (int) $child->cm > 0) {
                    $completion[] = (int) $child->cm;
                } else if (($child->type ?? '') === 'grade' && !empty($child->id)) {
                    $grades[] = (int) $child->id;
                }
            }
        };
        if ($tree) {
            $walk($tree);
        }
        $cms = get_fast_modinfo($cm->course, -1)->get_cms();
        $modnames = tool_details::get_modnames();
        foreach ($completion as $cmid) {
            if (isset($cms[$cmid]) && in_array($cms[$cmid]->modname, $modnames)) {
                return $cmid;
            }
        }
        foreach ($grades as $itemid) {
            $item = $DB->get_record('grade_items', ['id' => $itemid, 'itemtype' => 'mod'], 'itemmodule, iteminstance');
            if ($item) {
                foreach ($cms as $other) {
                    if ($other->modname === $item->itemmodule && (int) $other->instance === (int) $item->iteminstance) {
                        return (int) $other->id;
                    }
                }
            }
        }
        return 0;
    }

    /**
     * For a learner's record: how many of these assessments have a survey, and after how many it has been offered to
     * them (open to them now, or answered: either way it was offered). Nothing about whether they answered.
     *
     * @param int $userid
     * @param int[] $cmids Assessments
     * @return stdClass offered, total
     */
    public static function offered_to(int $userid, array $cmids): stdClass {
        global $DB;
        $result = (object) ['offered' => 0, 'total' => 0];
        if (!$cmids) {
            return $result;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $cmids));
        $courses = $DB->get_records_select_menu('course_modules', "id {$insql}", $params, '', 'id, course');
        foreach (array_unique($courses) as $courseid) {
            $links = self::get_links((int) $courseid);
            if (!$links) {
                continue;
            }
            $usermodinfo = get_fast_modinfo((int) $courseid, $userid);
            $byassessment = [];
            foreach ($links as $surveyid => $assessmentid) {
                if (in_array($assessmentid, array_map('intval', $cmids))) {
                    $survey = $usermodinfo->get_cm($surveyid);
                    $byassessment[$assessmentid] = ($byassessment[$assessmentid] ?? false)
                        || ($survey->uservisible && $survey->available)
                        || $DB->record_exists('feedback_completed', ['feedback' => $survey->instance, 'userid' => $userid]);
                }
            }
            $result->total += count($byassessment);
            $result->offered += count(array_filter($byassessment));
        }
        return $result;
    }

    /**
     * Collate surveys: per survey, the learners it was offered to and how many answered; per question (questions with
     * the same wording are combined across surveys), the results, unless too few answered.
     *
     * @param array $surveys [feedback cmid => assessment cm_info] as from {@see get_links()}, resolved
     * @return stdClass surveys (survey rows), questions (combined question rows), min (int)
     */
    public static function collate(array $surveys): stdClass {
        global $DB;
        $min = self::get_min_responses();
        $rows = [];
        $questions = [];
        foreach ($surveys as $surveyid => $assessment) {
            $modinfo = get_fast_modinfo($assessment->course, -1);
            $survey = $modinfo->get_cm($surveyid);
            // Offered: learners it's open to now (Restrict access checked for each, e.g. once their assessment's
            // marked), and anyone who has answered it.
            $responders = array_map('intval', $DB->get_fieldset_select(
                'feedback_completed',
                'DISTINCT userid',
                'feedback = ? AND userid > 0',
                [$survey->instance]
            ));
            $info = new \core_availability\info_module($survey);
            $offered = 0;
            foreach (array_keys(declarations::get_learners($survey)) as $userid) {
                $why = '';
                if (in_array((int) $userid, $responders) || ($survey->visible && $info->is_available($why, false, (int) $userid))) {
                    $offered++;
                }
            }
            $responses = (int) $DB->count_records('feedback_completed', ['feedback' => $survey->instance]);
            $offered = max($offered, $responses);
            $row = (object) [
                'cmid' => (int) $surveyid,
                'survey' => $survey->get_formatted_name(),
                'surveyurl' => (new \moodle_url('/mod/feedback/analysis.php', ['id' => $surveyid]))->out(false),
                'assessment' => $assessment->get_formatted_name(),
                'assessmentcmid' => (int) $assessment->id,
                'course' => format_string(
                    get_course($assessment->course)->shortname,
                    true,
                    ['context' => context_course::instance($assessment->course)]
                ),
                'offered' => $offered,
                'responses' => $responses,
                'rate' => $offered ? round(100 * min($responses, $offered) / $offered) : 0,
                'shown' => $responses >= $min,
            ];
            $rows[] = $row;
            self::add_questions($questions, (int) $survey->instance, $row);
        }
        // Combine, then hide what too few answered.
        $result = [];
        foreach ($questions as $question) {
            $question->shown = $question->responses >= $min;
            if (!$question->shown) {
                $question->options = [];
                $question->comments = [];
                $question->average = null;
            } else {
                foreach ($question->options as $option) {
                    $option->percent = $question->responses ? round(100 * $option->count / $question->responses) : 0;
                }
                $question->average = $question->ratingcount ? round($question->ratingsum / $question->ratingcount, 2) : null;
                // Comments in no particular order that could point to who wrote them.
                sort($question->comments, SORT_NATURAL | SORT_FLAG_CASE);
            }
            $question->surveys = implode(', ', array_unique($question->surveys));
            $result[] = $question;
        }
        return (object) ['surveys' => $rows, 'questions' => $result, 'min' => $min];
    }

    /**
     * Add a survey's answers to the combined questions.
     *
     * @param stdClass[] $questions Keyed by type and wording
     * @param int $feedbackid
     * @param stdClass $row The survey's row
     */
    private static function add_questions(array &$questions, int $feedbackid, stdClass $row): void {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(self::QUESTION_TYPES, SQL_PARAMS_NAMED, 'fbt');
        $items = $DB->get_records_select(
            'feedback_item',
            "feedback = :feedback AND typ {$insql}",
            ['feedback' => $feedbackid] + $params,
            'position'
        );
        foreach ($items as $item) {
            $name = trim(strip_tags(format_string($item->name)));
            $key = $item->typ . '|' . \core_text::strtolower($name);
            if (!isset($questions[$key])) {
                $questions[$key] = (object) ['question' => $name, 'type' => $item->typ, 'responses' => 0, 'options' => [],
                    'ratingsum' => 0.0, 'ratingcount' => 0, 'ratingmax' => null, 'comments' => [], 'surveys' => [],
                    'rated' => $item->typ === 'multichoicerated' || $item->typ === 'numeric',
                    'text' => $item->typ === 'textarea' || $item->typ === 'textfield'];
            }
            $question = $questions[$key];
            $question->surveys[] = $row->assessment;
            $options = self::get_options($item);
            // Every option, in the question's own order (unchosen ones too).
            foreach ($options as $option) {
                $question->options[$option->label] ??= (object) ['label' => $option->label, 'count' => 0];
                if ($option->rating !== null) {
                    $question->ratingmax = max($question->ratingmax ?? 0, $option->rating);
                }
            }
            $values = $DB->get_fieldset_select('feedback_value', 'value', 'item = ?', [$item->id]);
            foreach ($values as $value) {
                $value = trim((string) $value);
                if ($value === '' || $value === '0' && $item->typ !== 'numeric') {
                    continue;
                }
                $question->responses++;
                if ($question->text) {
                    $question->comments[] = $value;
                } else if ($item->typ === 'numeric') {
                    $question->ratingsum += (float) $value;
                    $question->ratingcount++;
                } else {
                    foreach (explode('|', $value) as $index) {
                        $option = $options[(int) $index] ?? null;
                        if (!$option) {
                            continue;
                        }
                        $question->options[$option->label]->count++;
                        if ($option->rating !== null) {
                            $question->ratingsum += $option->rating;
                            $question->ratingcount++;
                        }
                    }
                }
            }
        }
    }

    /**
     * A multiple-choice question's options, by their 1-based index (as answers are stored).
     *
     * @param stdClass $item
     * @return stdClass[] index => label, rating (null when not rated)
     */
    private static function get_options(stdClass $item): array {
        if ($item->typ !== 'multichoice' && $item->typ !== 'multichoicerated') {
            return [];
        }
        $presentation = (string) $item->presentation;
        $parts = explode('>>>>>', $presentation, 2);
        $presentation = $parts[1] ?? $parts[0];
        $presentation = explode('<<<<<', $presentation)[0];
        $options = [];
        foreach (explode('|', $presentation) as $i => $line) {
            $rating = null;
            $label = $line;
            if ($item->typ === 'multichoicerated') {
                $bits = explode('####', $line, 2);
                $rating = (float) $bits[0];
                $label = $bits[1] ?? $bits[0];
            }
            $options[$i + 1] = (object) ['label' => trim(format_string($label)), 'rating' => $rating];
        }
        return $options;
    }

    /**
     * The assessments a survey can be set to belong to, for the Feedback activity's form.
     *
     * @param int $courseid
     * @return array cmid => name
     */
    public static function get_assessment_choices(int $courseid): array {
        $choices = [];
        foreach (get_fast_modinfo($courseid, -1)->get_cms() as $cm) {
            if (!$cm->deletioninprogress && in_array($cm->modname, tool_details::get_modnames())) {
                $choices[(int) $cm->id] = $cm->get_formatted_name();
            }
        }
        return $choices;
    }

    /**
     * Add "Feedback for" to a Feedback activity's settings.
     *
     * @param \MoodleQuickForm $mform
     * @param int $courseid
     * @param int $cmid 0 for a new activity
     */
    public static function add_form_elements(\MoodleQuickForm $mform, int $courseid, int $cmid): void {
        global $DB;
        $mform->addElement('header', self::FIELD . 'header', get_string('feedbackforheader', 'report_evidencereporter'));
        $mform->addElement(
            'select',
            self::FIELD,
            get_string('feedbackfor', 'report_evidencereporter'),
            [0 => get_string('feedbackforauto', 'report_evidencereporter')] + self::get_assessment_choices($courseid)
        );
        $mform->addHelpButton(self::FIELD, 'feedbackfor', 'report_evidencereporter');
        if ($cmid) {
            $mform->setDefault(self::FIELD, (int) $DB->get_field(self::TABLE, 'assesscmid', ['cmid' => $cmid]));
        }
    }

    /**
     * Save "Feedback for".
     *
     * @param stdClass $data The activity form's data
     */
    public static function save_from_form(stdClass $data): void {
        global $DB;
        if (empty($data->coursemodule) || ($data->modulename ?? '') !== 'feedback' || !property_exists($data, self::FIELD)) {
            return;
        }
        $assessment = (int) $data->{self::FIELD};
        $DB->delete_records(self::TABLE, ['cmid' => $data->coursemodule]);
        if ($assessment) {
            $DB->insert_record(self::TABLE, ['cmid' => (int) $data->coursemodule, 'assesscmid' => $assessment]);
        }
    }

    /**
     * Forget a deleted activity (as a survey, or as the assessment a survey was set to).
     *
     * @param int $cmid
     */
    public static function delete_for_activity(int $cmid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['cmid' => $cmid]);
        $DB->delete_records(self::TABLE, ['assesscmid' => $cmid]);
    }
}
