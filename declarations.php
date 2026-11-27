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
 * RA and LLN: whether a reasonable adjustment was made and whether the learner had language, literacy and numeracy needs
 * or support, with details. Counted assessments are held from release until every learner is recorded.
 *
 * Two views: one assessment with every learner (cmid), or one learner with every assessment in a course (courseid and
 * userid, from the marking page).
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use report_evidencereporter\local\declarations;
use report_evidencereporter\local\reasonable_adjustments;
use report_evidencereporter\local\release_hold;

$cmid = optional_param('cmid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$learnermode = !$cmid;
$cancapabilities = ['report/evidencereporter:viewadjustments', 'report/evidencereporter:manageadjustments'];

if ($learnermode) {
    // One learner, every assessment in the course.
    $course = get_course($courseid);
    require_login($course, false);
    require_capability('report/evidencereporter:marking', context_course::instance($course->id));
    $learner = core_user::get_user($userid, '*', MUST_EXIST);
    $url = new moodle_url('/report/evidencereporter/declarations.php', ['courseid' => $course->id, 'userid' => $userid]);
    $PAGE->set_url($url);
    $PAGE->set_pagelayout('report');
    $title = get_string('declarations', 'report_evidencereporter') . ': ' . fullname($learner);
    $PAGE->set_title($title);
    $PAGE->set_heading(format_string($course->fullname));
    navigation_node::override_active_url(new moodle_url('/report/evidencereporter/marking.php', ['courseid' => $course->id]));
    // A row for each assessment the learner needs recording in, where the viewer may see RA and LLN.
    $items = [];
    foreach (get_fast_modinfo($course)->get_cms() as $cm) {
        if (!$cm->uservisible || !reasonable_adjustments::supports($cm->modname) || !declarations::available($cm)) {
            continue;
        }
        $context = context_module::instance($cm->id);
        if (!has_any_capability($cancapabilities, $context) || !isset(declarations::get_learners($cm)[$userid])) {
            continue;
        }
        $items[(int) $cm->id] = (object) ['key' => (int) $cm->id, 'cm' => $cm, 'userid' => $userid,
            'name' => $cm->get_formatted_name(), 'canmanage' => reasonable_adjustments::can_manage($context)];
    }
    $backurl = new moodle_url('/report/evidencereporter/marking.php', ['courseid' => $course->id]);
} else {
    // One assessment, every learner.
    [$course, $cm] = get_course_and_cm_from_cmid($cmid);
    require_login($course, false, $cm);
    $context = context_module::instance($cm->id);
    if (!declarations::available($cm)) {
        throw new moodle_exception('invalidcoursemodule');
    }
    if (!has_any_capability($cancapabilities, $context)) {
        require_capability('report/evidencereporter:viewadjustments', $context);
    }
    $url = new moodle_url('/report/evidencereporter/declarations.php', ['cmid' => $cm->id]);
    $PAGE->set_url($url);
    $title = get_string('declarations', 'report_evidencereporter') . ': ' . $cm->get_formatted_name();
    $PAGE->set_title($title);
    $PAGE->set_heading(format_string($course->fullname));
    $PAGE->activityheader->disable();
    $groupmode = groups_get_activity_groupmode($cm);
    $groupid = 0;
    if ($groupmode) {
        $groupid = (int) groups_get_activity_group($cm, true);
        if ($groupmode == SEPARATEGROUPS && !$groupid && !has_capability('moodle/site:accessallgroups', $context)) {
            $groupid = -1;
        }
    }
    $canmanage = reasonable_adjustments::can_manage($context);
    $items = [];
    foreach ($groupid >= 0 ? declarations::get_learners($cm, $groupid) : [] as $id => $learner) {
        $items[(int) $id] = (object) ['key' => (int) $id, 'cm' => $cm, 'userid' => (int) $id, 'name' => fullname($learner),
            'canmanage' => $canmanage];
    }
    $backurl = null;
}

// Save.
$posted = null;
$errors = [];
if (data_submitted() && confirm_sesskey()) {
    $shown = array_map('intval', optional_param_array('key', [], PARAM_INT));
    $ra = optional_param_array('ra', [], PARAM_BOOL);
    $radetails = optional_param_array('radetails', [], PARAM_TEXT);
    $lln = optional_param_array('lln', [], PARAM_BOOL);
    $llndetails = optional_param_array('llndetails', [], PARAM_TEXT);
    $posted = [];
    foreach ($shown as $key) {
        if (!isset($items[$key]) || !$items[$key]->canmanage) {
            continue;
        }
        $posted[$key] = ['ra' => !empty($ra[$key]), 'radetails' => (string) ($radetails[$key] ?? ''),
            'lln' => !empty($lln[$key]), 'llndetails' => (string) ($llndetails[$key] ?? '')];
    }
    $errors = declarations::validate($posted);
    if (!$errors && $posted) {
        // Save per assessment (in the learner view, one learner in each).
        $byactivity = [];
        foreach ($posted as $key => $answer) {
            $byactivity[(int) $items[$key]->cm->id][$items[$key]->userid] = $answer;
        }
        foreach ($byactivity as $id => $answers) {
            declarations::save(get_fast_modinfo($course)->get_cm($id), $answers);
        }
        $message = get_string('declsaved', 'report_evidencereporter', count($posted));
        if (!$learnermode) {
            $outstanding = count(declarations::get_outstanding($cm));
            if ($outstanding && release_hold::status($cm)->held) {
                $message .= ' ' . get_string('declsavedstillheld', 'report_evidencereporter', $outstanding);
            }
        }
        redirect($url, $message, null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Rows.
$effective = [];
$adjustments = [];
$rows = [];
foreach ($items as $key => $item) {
    $id = (int) $item->cm->id;
    $effective[$id] ??= declarations::get_effective($item->cm);
    $adjustments[$id] ??= reasonable_adjustments::get_for_activity($id);
    $record = $effective[$id][$item->userid] ?? null;
    $formatted = reasonable_adjustments::format($adjustments[$id][$item->userid] ?? []);
    $itemstext = $formatted ? reasonable_adjustments::to_text($formatted, false, '; ') : '';
    if ($posted !== null && isset($posted[$key])) {
        $answer = $posted[$key];
    } else if ($record) {
        $answer = ['ra' => (bool) $record->ra, 'radetails' => (string) $record->radetails, 'lln' => (bool) $record->lln,
            'llndetails' => (string) $record->llndetails];
    } else {
        // Not yet recorded: a detailed adjustment means "yes", described as recorded.
        $answer = ['ra' => (bool) $formatted, 'radetails' => $itemstext, 'lln' => false, 'llndetails' => ''];
    }
    $row = [
        'key' => $key,
        'name' => $item->name,
        'canedit' => $item->canmanage,
        'recorded' => (bool) $record,
        // The carried-over note is shown on its own line.
        'recordedtext' => $record ? declarations::recorded_text((object) (['carriedname' => ''] + (array) $record)) : '',
        'carried' => $record && $record->carriedfrom ? $record->carriedname : '',
        'ra' => $answer['ra'],
        'radetails' => $answer['radetails'],
        'raerror' => $errors[$key]['ra'] ?? '',
        'lln' => $answer['lln'],
        'llndetails' => $answer['llndetails'],
        'llnerror' => $errors[$key]['lln'] ?? '',
        'adjustments' => $itemstext,
        'adjustmentsurl' => (new moodle_url('/report/evidencereporter/adjustments.php', ['cmid' => $id]))->out(false),
        'held' => false,
        'url' => null,
    ];
    if ($learnermode) {
        $hold = release_hold::status($item->cm);
        $row['held'] = $hold->applies && ($hold->held || $hold->learnersheld > 0);
        $row['url'] = (new moodle_url('/report/evidencereporter/declarations.php', ['cmid' => $id]))->out(false);
    }
    $rows[] = $row;
}

$data = [
    'action' => $url->out(false),
    'sesskey' => sesskey(),
    'canmanage' => (bool) array_filter($items, fn($item) => $item->canmanage),
    'learnermode' => $learnermode,
    'rowlabel' => $learnermode ? get_string('assessment', 'report_evidencereporter')
        : \report_evidencereporter\local\terms::get('learner'),
    'rows' => $rows,
    'hasrows' => (bool) $rows,
    'backurl' => $backurl ? $backurl->out(false) : null,
];
if (!$learnermode) {
    $status = release_hold::status($cm);
    $outstanding = declarations::get_outstanding($cm);
    $data += [
        'applies' => $status->applies,
        'held' => $status->applies && $status->held,
        'exempt' => $status->applies && $status->exempt,
        'learnersheld' => $status->learnersheld,
        'haslearnersheld' => $status->learnersheld > 0,
        'outstanding' => count($outstanding),
        'hasoutstanding' => (bool) $outstanding,
        'shared' => implode(', ', array_map(
            fn($id) => get_fast_modinfo($course)->get_cm($id)->get_formatted_name(),
            array_slice(declarations::get_group($cm), 1)
        )),
    ];
} else {
    $data['outstanding'] = count(array_filter($rows, fn($r) => !$r['recorded']));
    $data['hasoutstanding'] = $data['outstanding'] > 0;
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
if (!$learnermode && $groupmode) {
    groups_print_activity_menu($cm, $url);
}
if ($errors) {
    echo $OUTPUT->notification(get_string('declerrors', 'report_evidencereporter'), 'error');
}
echo $OUTPUT->render_from_template('report_evidencereporter/declarations', $data);
echo $OUTPUT->footer();
