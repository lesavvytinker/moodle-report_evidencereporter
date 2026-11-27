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
 * The assessor's marking page: what needs marking, feedback and reasonable adjustments, per learner and assessment.
 * With a course: that course's grid (by unit or all units). Without: one line per course the user marks in.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use report_evidencereporter\local\marking;

$courseid = optional_param('courseid', 0, PARAM_INT);
$unitid = optional_param('unitid', 0, PARAM_INT);

if (!$courseid) {
    require_login(null, false);
    $context = context_system::instance();
    $PAGE->set_context($context);
    $PAGE->set_url(new moodle_url('/report/evidencereporter/marking.php'));
    $PAGE->set_pagelayout('standard');
    $PAGE->set_title(get_string('marking', 'report_evidencereporter'));
    $PAGE->set_heading(get_string('marking', 'report_evidencereporter'));
    $overview = marking::get_overview();
    $total = $overview[0]->counts;
    unset($overview[0]);
    $courses = [];
    foreach ($overview as $courseid => $line) {
        $courses[] = ['name' => format_string($line->course->fullname, true, ['context' => context_course::instance($courseid)]),
            'url' => (new moodle_url('/report/evidencereporter/marking.php', ['courseid' => $courseid]))->out(false)]
            + marking_count_data($line->counts);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('report_evidencereporter/marking_overview', [
        'hascourses' => (bool) $courses,
        'courses' => $courses,
        'total' => marking_count_data($total),
        'thresholds' => marking_threshold_data(),
    ]);
    echo $OUTPUT->footer();
    die();
}

$course = get_course($courseid);
require_login($course, false);
$context = context_course::instance($course->id);
require_capability('report/evidencereporter:marking', $context);
// Each marker chooses whether to "Check before marking".
$check = optional_param('checkbeforemarking', -1, PARAM_INT);
if ($check >= 0 && confirm_sesskey()) {
    \report_evidencereporter\local\before_marking::set((bool) $check);
    redirect(new moodle_url('/report/evidencereporter/marking.php', ['courseid' => $course->id]
        + ($unitid ? ['unitid' => $unitid] : [])));
}
$checkbefore = \report_evidencereporter\local\before_marking::is_on();
$params = ['courseid' => $course->id] + ($unitid ? ['unitid' => $unitid] : []);
$url = new moodle_url('/report/evidencereporter/marking.php', $params);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('marking', 'report_evidencereporter') . ': ' . format_string($course->shortname));
$PAGE->set_heading(format_string($course->fullname));
navigation_node::override_active_url(new moodle_url('/report/evidencereporter/marking.php', ['courseid' => $course->id]));

$groupid = 0;
if (groups_get_course_groupmode($course)) {
    $groupid = (int) groups_get_course_group($course, true);
}
$grid = marking::build((int) $course->id, $unitid ?: null, $groupid);

$assessments = [];
$hasdecl = false;
foreach ($grid->assessments as $cmid => $assessment) {
    $decl = null;
    if (\report_evidencereporter\local\declarations::available($assessment->cm)) {
        $hasdecl = true;
        $outstanding = count(array_filter($grid->rows, fn($r) => !empty($r->cells[$cmid]->declmissing)));
        $hold = \report_evidencereporter\local\release_hold::status($assessment->cm);
        $decl = ['url' => (new moodle_url('/report/evidencereporter/declarations.php', ['cmid' => $cmid]))->out(false),
            'outstanding' => $outstanding, 'hasoutstanding' => $outstanding > 0,
            'held' => $hold->applies && ($hold->held || $hold->learnersheld > 0)];
    }
    $assessments[] = ['name' => $assessment->cm->get_formatted_name(), 'url' => $assessment->cm->url->out(false),
        'icon' => $assessment->cm->get_icon_url()->out(false), 'decl' => $decl,
        'toolmissing' => (bool) \report_evidencereporter\local\tool_details::get_missing_fields((int) $cmid)];
}
$unitids = $grid->unitid !== null ? [$grid->unitid] : array_keys($grid->units);
$toolcomplete = [];
foreach ($grid->assessments as $cmid => $assessment) {
    $toolcomplete[$cmid] = !\report_evidencereporter\local\tool_details::get_missing_fields((int) $cmid);
}
$statuslabels = [];
foreach (
    [marking::STATUS_NOTSUBMITTED, marking::STATUS_INPROGRESS, marking::STATUS_TOMARK, marking::STATUS_NOTRELEASED,
        marking::STATUS_FEEDBACK, marking::STATUS_NOTSATISFACTORY, marking::STATUS_SATISFACTORY, marking::STATUS_MARKED,
        marking::STATUS_NOTMARKED, marking::STATUS_SUBMITTED] as $status
) {
    $statuslabels[$status] = get_string(
        'markingstatus_' . $status,
        'report_evidencereporter',
        \report_evidencereporter\local\terms::all()
    );
}
$rows = [];
foreach ($grid->rows as $row) {
    $cells = [];
    foreach ($grid->assessments as $cmid => $assessment) {
        $cell = $row->cells[$cmid];
        $label = $statuslabels[$cell->status];
        if ($cell->status === marking::STATUS_TOMARK) {
            $label = get_string('markingwaiting', 'report_evidencereporter', $cell->days);
        }
        $cells[] = [
            'status' => $cell->status,
            'age' => $cell->age,
            'label' => $label,
            'grade' => $cell->grade,
            'attempt' => in_array($cell->status, [marking::STATUS_NOTSUBMITTED, marking::STATUS_NOTMARKED]) ? '' : $cell->attempt,
            // With "Check before marking" on, a learner with something still to do goes through the checklist first.
            'url' => $checkbefore && (!$toolcomplete[$cmid] || !empty($cell->declmissing))
                ? (new moodle_url('/report/evidencereporter/beforemarking.php', ['cmid' => $cmid,
                    'userid' => $row->userid]))->out(false) : $cell->url,
            'checkfirst' => $checkbefore && (!$toolcomplete[$cmid] || !empty($cell->declmissing)),
            'flag' => $cell->status === marking::STATUS_FEEDBACK,
            'noattemptsleft' => !empty($cell->noattemptsleft),
            'adjustmentsurl' => $cell->adjustmentsurl,
            'hasadjustments' => $cell->adjustments > 0,
            'adjustments' => $cell->adjustments,
            'toconfirm' => $cell->toconfirm,
            'hastoconfirm' => $cell->toconfirm > 0,
        ];
    }
    $frameworkids = $grid->unitid !== null ? [$grid->units[$grid->unitid]->frameworkid] : [];
    $plans = marking::get_relevant_plans((int) $row->userid, $unitids);
    $declurl = (new moodle_url('/report/evidencereporter/declarations.php', ['courseid' => $course->id,
        'userid' => $row->userid]))->out(false);
    $rows[] = [
        'name' => fullname($row->user),
        'profileurl' => (new moodle_url('/user/view.php', ['id' => $row->userid, 'course' => $course->id]))->out(false),
        'cells' => $cells,
        'highlight' => $row->priority >= 40 ? 'red' : ($row->priority >= 30 ? 'amber' : ''),
        'unit' => $row->unit ? (array) $row->unit : null,
        'readycount' => count($row->ready),
        'hasready' => (bool) $row->ready,
        'summaryurl' => (new moodle_url('/report/evidencereporter/print.php', ['userid' => $row->userid]
            + ($frameworkids ? ['frameworkid' => reset($frameworkids)] : [])))->out(false),
        'plans' => $plans,
        'hasplans' => (bool) $plans,
        'hasdecl' => $hasdecl,
        'declurl' => $declurl,
        'declmissing' => $row->declmissing,
        'hasdeclmissing' => $row->declmissing > 0,
        'readypendingcount' => count($row->readypending),
        'hasreadypending' => (bool) $row->readypending,
    ];
    if ($rows[count($rows) - 1]['unit']) {
        $rows[count($rows) - 1]['unit']['declurl'] = $declurl;
    }
}
$units = [['id' => 0, 'label' => get_string('markingallunits', 'report_evidencereporter'), 'selected' => $grid->unitid === null]];
foreach ($grid->units as $unit) {
    $units[] = ['id' => $unit->id, 'label' => $unit->label, 'selected' => $grid->unitid === $unit->id];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('marking', 'report_evidencereporter'));
if (groups_get_course_groupmode($course)) {
    echo groups_print_course_menu($course, $url, true);
}
echo $OUTPUT->render_from_template('report_evidencereporter/marking', [
    'action' => (new moodle_url('/report/evidencereporter/marking.php'))->out(false),
    'courseid' => $course->id,
    'learnerlabel' => \report_evidencereporter\local\terms::get('learner'),
    'units' => $units,
    'oneunit' => $grid->unitid !== null,
    'assessments' => $assessments,
    'hasassessments' => (bool) $assessments,
    'rows' => $rows,
    'hasrows' => (bool) $rows,
    'counts' => marking_count_data($grid->counts),
    'hasdecl' => $hasdecl,
    'checkbefore' => $checkbefore,
    'checkbeforeurl' => (new moodle_url('/report/evidencereporter/marking.php', ['courseid' => $course->id,
        'checkbeforemarking' => $checkbefore ? 0 : 1, 'sesskey' => sesskey()] + ($unitid ? ['unitid' => $unitid] : [])))
        ->out(false),
    'thresholds' => marking_threshold_data(),
    'overviewurl' => (new moodle_url('/report/evidencereporter/marking.php'))->out(false),
]);
echo $OUTPUT->footer();

/**
 * Counts for the templates.
 *
 * @param stdClass $counts From {@see marking::count_cells()}
 * @return array
 */
function marking_count_data(stdClass $counts): array {
    $data = (array) $counts;
    $data['overdue'] = $counts->amber + $counts->red;
    $data['nothing'] = !$counts->tomark && !$counts->feedback && !$counts->notreleased && !$counts->toconfirm
        && !$counts->ready && empty($counts->torecord);
    $data['hastorecord'] = !empty($counts->torecord);
    return $data;
}

/**
 * The thresholds, for the legend.
 *
 * @return array amber, red
 */
function marking_threshold_data(): array {
    [$amber, $red] = marking::get_thresholds();
    return ['amber' => $amber, 'red' => $red];
}
