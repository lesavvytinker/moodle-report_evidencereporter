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
 * RA and LLN for one assessment: for each learner, whether a reasonable adjustment was made and whether they had
 * language, literacy and numeracy needs or support, with details. Counted assessments are held from release until
 * every learner is recorded.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use report_evidencereporter\local\declarations;
use report_evidencereporter\local\reasonable_adjustments;
use report_evidencereporter\local\release_hold;

$cmid = required_param('cmid', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($cmid);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
if (!declarations::available($cm)) {
    throw new moodle_exception('invalidcoursemodule');
}
if (!has_any_capability(['report/evidencereporter:viewadjustments', 'report/evidencereporter:manageadjustments'], $context)) {
    require_capability('report/evidencereporter:viewadjustments', $context);
}
$canmanage = reasonable_adjustments::can_manage($context);
$url = new moodle_url('/report/evidencereporter/declarations.php', ['cmid' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('declarations', 'report_evidencereporter') . ': ' . $cm->get_formatted_name());
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
$learners = $groupid >= 0 ? declarations::get_learners($cm, $groupid) : [];

// Save.
$posted = null;
$errors = [];
if ($canmanage && data_submitted() && confirm_sesskey()) {
    $shown = array_map('intval', optional_param_array('learner', [], PARAM_INT));
    $ra = optional_param_array('ra', [], PARAM_BOOL);
    $radetails = optional_param_array('radetails', [], PARAM_TEXT);
    $lln = optional_param_array('lln', [], PARAM_BOOL);
    $llndetails = optional_param_array('llndetails', [], PARAM_TEXT);
    $posted = [];
    foreach ($shown as $userid) {
        if (!isset($learners[$userid])) {
            continue;
        }
        $posted[$userid] = ['ra' => !empty($ra[$userid]), 'radetails' => (string) ($radetails[$userid] ?? ''),
            'lln' => !empty($lln[$userid]), 'llndetails' => (string) ($llndetails[$userid] ?? '')];
    }
    $errors = declarations::validate($posted);
    if (!$errors) {
        $changed = declarations::save($cm, $posted);
        $outstanding = count(declarations::get_outstanding($cm));
        $message = get_string('declsaved', 'report_evidencereporter', count($posted));
        if ($outstanding && release_hold::status($cm)->held) {
            $message .= ' ' . get_string('declsavedstillheld', 'report_evidencereporter', $outstanding);
        }
        redirect($url, $message, null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

$recorded = declarations::get_effective($cm);
$adjustments = reasonable_adjustments::get_for_activity((int) $cm->id);
$status = release_hold::status($cm);
$outstanding = declarations::get_outstanding($cm);
$adjustmentsurl = (new moodle_url('/report/evidencereporter/adjustments.php', ['cmid' => $cm->id]))->out(false);
$rows = [];
foreach ($learners as $userid => $learner) {
    $record = $recorded[$userid] ?? null;
    $items = reasonable_adjustments::format($adjustments[$userid] ?? []);
    $itemstext = $items ? reasonable_adjustments::to_text($items, false, '; ') : '';
    if ($posted !== null && isset($posted[$userid])) {
        $answer = $posted[$userid];
    } else if ($record) {
        $answer = ['ra' => (bool) $record->ra, 'radetails' => (string) $record->radetails, 'lln' => (bool) $record->lln,
            'llndetails' => (string) $record->llndetails];
    } else {
        // Not yet recorded: a detailed adjustment means "yes", described as recorded.
        $answer = ['ra' => (bool) $items, 'radetails' => $itemstext, 'lln' => false, 'llndetails' => ''];
    }
    $recorder = $record && $record->usermodified ? core_user::get_user($record->usermodified) : null;
    $rows[] = [
        'userid' => $userid,
        'name' => fullname($learner),
        'recorded' => (bool) $record,
        'recordedtext' => $record ? get_string('declrecordedon', 'report_evidencereporter', (object) [
            'date' => userdate((int) $record->timemodified, get_string('strftimedatefullshort', 'core_langconfig')),
            'by' => $recorder ? fullname($recorder) : '']) : '',
        'carried' => $record && $record->carriedfrom ? $record->carriedname : '',
        'ra' => $answer['ra'],
        'radetails' => $answer['radetails'],
        'raerror' => $errors[$userid]['ra'] ?? '',
        'lln' => $answer['lln'],
        'llndetails' => $answer['llndetails'],
        'llnerror' => $errors[$userid]['lln'] ?? '',
        'adjustments' => $itemstext,
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('declarations', 'report_evidencereporter') . ': ' . $cm->get_formatted_name());
if ($groupmode) {
    groups_print_activity_menu($cm, $url);
}
if ($errors) {
    echo $OUTPUT->notification(get_string('declerrors', 'report_evidencereporter'), 'error');
}
echo $OUTPUT->render_from_template('report_evidencereporter/declarations', [
    'action' => $url->out(false),
    'sesskey' => sesskey(),
    'canmanage' => $canmanage,
    'learnerlabel' => \report_evidencereporter\local\terms::get('learner'),
    'applies' => $status->applies,
    'held' => $status->applies && $status->held,
    'exempt' => $status->applies && $status->exempt,
    'learnersheld' => $status->learnersheld,
    'haslearnersheld' => $status->learnersheld > 0,
    'outstanding' => count($outstanding),
    'hasoutstanding' => (bool) $outstanding,
    'rows' => $rows,
    'hasrows' => (bool) $rows,
    'adjustmentsurl' => $adjustmentsurl,
    'shared' => implode(', ', array_map(
        fn($id) => get_fast_modinfo($course)->get_cm($id)->get_formatted_name(),
        array_slice(declarations::get_group($cm), 1)
    )),
]);
echo $OUTPUT->footer();
