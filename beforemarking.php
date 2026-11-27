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
 * Before marking a learner's assessment: 1. the assessment tool's details, 2. the learner's RA and LLN, 3. start
 * marking. Opened from the marking page by markers who choose "Check before marking". Marking is never blocked:
 * starting before the checks are done is logged.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use report_evidencereporter\local\before_marking;
use report_evidencereporter\local\declarations;
use report_evidencereporter\local\marking;
use report_evidencereporter\local\reasonable_adjustments;

$cmid = required_param('cmid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$userid = $action === 'notify' ? optional_param('userid', 0, PARAM_INT) : required_param('userid', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($cmid);
require_login($course, false, $cm);
$coursecontext = context_course::instance($course->id);
$context = context_module::instance($cm->id);
require_capability('report/evidencereporter:marking', $coursecontext);

// Let the editing teachers know about missing tool details (from here, or from a marking page's warning).
if ($action === 'notify') {
    require_sesskey();
    $told = before_marking::notify_tool($cm);
    $returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
    redirect(
        $returnurl ? new moodle_url($returnurl) : new moodle_url(
            '/report/evidencereporter/beforemarking.php',
            ['cmid' => $cm->id, 'userid' => $userid]
        ),
        $told ? get_string('beforetoolnotifiedsent', 'report_evidencereporter', $told)
            : get_string('beforetoolnoeditors', 'report_evidencereporter'),
        null,
        $told ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
    );
}

// Only learners the assessor marks: in the course, and in their groups.
if (!isset(marking::get_learners((int) $course->id)[$userid]) || !declarations::can_see_learner($cm, $userid)) {
    throw new required_capability_exception($context, 'moodle/site:accessallgroups', 'nopermissions', '');
}
$learner = core_user::get_user($userid, '*', MUST_EXIST);
$url = new moodle_url('/report/evidencereporter/beforemarking.php', ['cmid' => $cm->id, 'userid' => $userid]);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$title = get_string('beforemarking', 'report_evidencereporter') . ': ' . fullname($learner);
$PAGE->set_title($title . ' · ' . $cm->get_formatted_name());
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();
$markingurl = new moodle_url('/report/evidencereporter/marking.php', ['courseid' => $course->id]);
$cells = marking::get_cells($cm, [$userid]);
$graderurl = $cells[$userid]->url ?? $cm->url->out(false);
$canrecord = declarations::available($cm) && reasonable_adjustments::can_manage($context)
    && isset(declarations::get_learners($cm)[$userid]);

// Start marking (logged when the checks aren't done).
if ($action === 'start' && confirm_sesskey()) {
    $status = before_marking::status($cm, $userid);
    if (!$status->ready) {
        before_marking::log_unchecked($cm, $userid, $status->missing);
    }
    redirect($graderurl);
}

// RA and LLN for this learner.
$posted = null;
$errors = [];
if ($canrecord && $action === '' && data_submitted() && confirm_sesskey()) {
    [$answers, $errors] = declarations::from_request([$userid]);
    $posted = $answers[$userid];
    $errors = $errors[$userid] ?? [];
    if (!$errors && ($posted['ra'] !== null || $posted['lln'] !== null)) {
        declarations::save($cm, [$userid => $posted]);
        redirect(
            $url,
            get_string('declsaved', 'report_evidencereporter', 1),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    if (!$errors) {
        redirect(
            $url,
            get_string('declnothingticked', 'report_evidencereporter'),
            null,
            \core\output\notification::NOTIFY_INFO
        );
    }
}

$declared = declarations::available($cm) ? declarations::get_effective($cm) : [];
$status = before_marking::status($cm, $userid, $declared);
$row = null;
if ($status->declapplies) {
    $row = declarations::export_row(
        $cm,
        $userid,
        fullname($learner),
        $canrecord,
        $declared[$userid] ?? null,
        reasonable_adjustments::get_for_activity((int) $cm->id)[$userid] ?? [],
        $posted,
        $errors
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
if ($errors) {
    echo $OUTPUT->notification(get_string('declerrors', 'report_evidencereporter'), 'error');
}
echo $OUTPUT->render_from_template('report_evidencereporter/beforemarking', [
    'activity' => $cm->get_formatted_name(),
    'activityurl' => $cm->url->out(false),
    'learnername' => fullname($learner),
    'tool' => [
        'complete' => $status->tool->complete,
        'missing' => implode(', ', $status->tool->missing),
        'canedit' => $status->tool->canedit,
        'editurl' => $status->tool->editurl,
        'notified' => $status->tool->notified,
        'notifyurl' => (new moodle_url($url, ['action' => 'notify', 'sesskey' => sesskey()]))->out(false),
    ],
    'declapplies' => $status->declapplies,
    'declcomplete' => $status->declcomplete,
    'decl' => $row ? [
        'action' => $url->out(false),
        'sesskey' => sesskey(),
        'canmanage' => $canrecord,
        'rowlabel' => \report_evidencereporter\local\terms::get('learner'),
        'rows' => [$row],
        'backurl' => null,
    ] : null,
    'ready' => $status->ready,
    'missing' => $status->missing,
    'starturl' => (new moodle_url($url, ['action' => 'start', 'sesskey' => sesskey()]))->out(false),
    'markingurl' => $markingurl->out(false),
]);
echo $OUTPUT->footer();
