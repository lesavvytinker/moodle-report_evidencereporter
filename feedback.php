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

/**
 * Assessment feedback: learners' feedback on assessments, collated for a course (optionally one unit) or a course
 * category. No names; results from too few responses are hidden.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use report_evidencereporter\local\feedback_surveys;

$courseid = optional_param('courseid', 0, PARAM_INT);
$categoryid = optional_param('categoryid', 0, PARAM_INT);
$unitid = optional_param('unitid', 0, PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

if ($courseid) {
    $course = get_course($courseid);
    require_login($course, false);
    $context = context_course::instance($course->id);
    $courseids = [(int) $course->id];
    $heading = format_string($course->fullname, true, ['context' => $context]);
    $PAGE->set_pagelayout('report');
} else {
    $category = core_course_category::get($categoryid);
    require_login();
    $context = context_coursecat::instance($category->id);
    $PAGE->set_context($context);
    $PAGE->set_pagelayout('admin');
    $courseids = array_map('intval', array_keys($category->get_courses(['recursive' => true])));
    $heading = $category->get_formatted_name();
}
require_capability('report/evidencereporter:viewfeedback', $context);
$url = new moodle_url('/report/evidencereporter/feedback.php', $courseid ? ['courseid' => $courseid]
    : ['categoryid' => $categoryid]);
if ($unitid) {
    $url->param('unitid', $unitid);
}
$PAGE->set_url($url);
$title = get_string('feedbackreport', 'report_evidencereporter');
$PAGE->set_title($title . ': ' . $heading);
$PAGE->set_heading($heading);

// The surveys, each with its assessment; only assessments linked to the unit (or under it) when one is picked.
$unitcmids = null;
if ($unitid) {
    $unitcmids = array_map('intval', $DB->get_fieldset_sql(
        "SELECT DISTINCT mc.cmid
           FROM {competency_modulecomp} mc
           JOIN {competency} c ON c.id = mc.competencyid
          WHERE c.id = :unitid OR " . $DB->sql_like('c.path', ':path'),
        ['unitid' => $unitid, 'path' => '%/' . $unitid . '/%']
    ));
}
$surveys = [];
foreach ($courseids as $id) {
    $modinfo = get_fast_modinfo($id, -1);
    foreach (feedback_surveys::get_links($id) as $surveyid => $assessmentid) {
        if ($unitcmids === null || in_array($assessmentid, $unitcmids)) {
            $surveys[$surveyid] = $modinfo->get_cm($assessmentid);
        }
    }
}
$collated = feedback_surveys::collate($surveys);

// Units to pick from: competencies linked to these courses.
$units = [];
if ($courseids) {
    [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'fcrs');
    $units = $DB->get_records_sql_menu(
        "SELECT DISTINCT c.id, " . $DB->sql_concat('c.idnumber', "' '", 'c.shortname') . " AS name
           FROM {competency_coursecomp} cc
           JOIN {competency} c ON c.id = cc.competencyid
          WHERE cc.courseid {$insql}
       ORDER BY name",
        $params
    );
}

$str = fn($id, $a = null) => get_string($id, 'report_evidencereporter', $a);
if ($download === 'csv') {
    require_once($CFG->libdir . '/csvlib.class.php');
    $csv = new csv_export_writer();
    $csv->set_filename(clean_filename($title . ' ' . $heading . ' ' . date('Y-m-d')));
    $csv->add_data([$str('feedbackcolcourse'), $str('assessment'), $str('feedbackcolsurvey'), $str('feedbackcoloffered'),
        $str('feedbackcolresponses'), $str('feedbackcolrate')]);
    foreach ($collated->surveys as $row) {
        $csv->add_data([$row->course, $row->assessment, $row->survey, $row->offered, $row->responses, $row->rate . '%']);
    }
    $csv->add_data([]);
    $csv->add_data([$str('feedbackcolquestion'), $str('feedbackcolassessments'), $str('feedbackcolresponses'),
        $str('feedbackcolaverage'), $str('feedbackcolanswers')]);
    foreach ($collated->questions as $question) {
        if (!$question->shown) {
            $answers = $str('feedbacktoofew', $collated->min);
        } else if ($question->text) {
            $answers = implode("\n", $question->comments);
        } else {
            $answers = implode('; ', array_map(fn($o) => $o->label . ': ' . $o->count, $question->options));
        }
        $csv->add_data([$question->question, $question->surveys, $question->responses,
            $question->average ?? '', $answers]);
    }
    $csv->download_file();
    die();
}

$unitchoices = [['id' => 0, 'name' => $str('feedbackallunits'), 'selected' => !$unitid]];
foreach ($units as $id => $name) {
    $unitchoices[] = ['id' => (int) $id, 'name' => format_string($name), 'selected' => (int) $id === $unitid];
}
$totaloffered = array_sum(array_map(fn($r) => $r->offered, $collated->surveys));
$totalresponses = array_sum(array_map(fn($r) => $r->responses, $collated->surveys));

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
echo $OUTPUT->render_from_template('report_evidencereporter/feedback_report', [
    'action' => (new moodle_url('/report/evidencereporter/feedback.php'))->out(false),
    'courseid' => $courseid ?: null,
    'categoryid' => $courseid ? null : $categoryid,
    'categorymode' => !$courseid,
    'units' => $unitchoices,
    'hasunits' => (bool) $units,
    'surveys' => array_map(fn($r) => (array) $r, $collated->surveys),
    'hassurveys' => (bool) $collated->surveys,
    'questions' => array_map(function ($q) {
        return [
            'question' => $q->question,
            'surveys' => $q->surveys,
            'responses' => $q->responses,
            'shown' => $q->shown,
            'rated' => $q->rated,
            'text' => $q->text,
            'average' => $q->average === null ? null : format_float($q->average, 1),
            'hasaverage' => $q->average !== null,
            'outof' => $q->ratingmax ? format_float($q->ratingmax, 0) : null,
            'options' => array_values(array_map(fn($o) => (array) $o, $q->options)),
            'comments' => $q->comments,
            'commentcount' => count($q->comments),
        ];
    }, $collated->questions),
    'hasquestions' => (bool) $collated->questions,
    'min' => $collated->min,
    'totaloffered' => $totaloffered,
    'totalresponses' => $totalresponses,
    'totalrate' => $totaloffered ? round(100 * $totalresponses / $totaloffered) : 0,
    'downloadurl' => (new moodle_url($url, ['download' => 'csv']))->out(false),
]);
echo $OUTPUT->footer();
