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
 * Record or change one reasonable adjustment for a learner in an activity.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use report_evidencereporter\local\reasonable_adjustments;

$id = optional_param('id', 0, PARAM_INT);
$returnto = optional_param('returnto', '', PARAM_LOCALURL);
if ($id) {
    $record = reasonable_adjustments::get($id);
    if (!$record) {
        throw new moodle_exception('invalidrecord', 'error', '', reasonable_adjustments::TABLE);
    }
    $cmid = (int) $record->cmid;
    $userid = (int) $record->userid;
} else {
    $record = null;
    $cmid = required_param('cmid', PARAM_INT);
    $userid = required_param('userid', PARAM_INT);
}
[$course, $cm] = get_course_and_cm_from_cmid($cmid);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('report/evidencereporter:manageadjustments', $context);
if (!reasonable_adjustments::available($cm)) {
    throw new moodle_exception('invalidcoursemodule');
}
$learner = core_user::get_user($userid, '*', MUST_EXIST);
if (!is_enrolled(context_course::instance($course->id), $learner)) {
    throw new moodle_exception('notenrolled', '', '', fullname($learner));
}

$url = new moodle_url('/report/evidencereporter/adjustment.php', $id ? ['id' => $id] : ['cmid' => $cmid, 'userid' => $userid]);
$listurl = new moodle_url('/report/evidencereporter/adjustments.php', ['cmid' => $cm->id]);
$back = $returnto !== '' ? new moodle_url($returnto) : $listurl;
$PAGE->set_url($url);
$title = get_string('adjustmentsedit', 'report_evidencereporter', fullname($learner));
$PAGE->set_title($title);
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();

// Confirming one of Moodle's own adjustments: start from it.
$defaults = $record ? clone $record : (object) ['cmid' => $cmid, 'userid' => $userid, 'adjtype' => 'extratime',
    'source' => reasonable_adjustments::SOURCE_MANUAL, 'sourceid' => 0];
$source = optional_param('source', '', PARAM_ALPHA);
$sourceid = optional_param('sourceid', 0, PARAM_INT);
if (!$record && $source !== '') {
    foreach (reasonable_adjustments::get_suggestions($cm)[$userid] ?? [] as $suggestion) {
        if ($suggestion->source === $source && (int) $suggestion->sourceid === $sourceid) {
            $defaults = (object) ['cmid' => $cmid, 'userid' => $userid, 'adjtype' => $suggestion->adjtype,
                'details' => $suggestion->details, 'source' => $source, 'sourceid' => $sourceid];
        }
    }
}
$defaults->returnto = $returnto;

$currentapprover = $record ? (int) $record->approvedbyid : 0;
$form = new \report_evidencereporter\form\adjustment_form($url, ['activity' => $cm->get_formatted_name(),
    'learner' => fullname($learner), 'context' => $context, 'approvedbyid' => $currentapprover]);
$form->set_data($defaults);
if ($form->is_cancelled()) {
    redirect($back);
}
if ($data = $form->get_data()) {
    $data->id = $id;
    $data->cmid = $cmid;
    $data->userid = $userid;
    // Only staff (or the approver already saved) can be chosen.
    if (!\report_evidencereporter\local\staff::is_valid_choice((int) ($data->approvedbyid ?? 0), $context, $currentapprover)) {
        $data->approvedbyid = $currentapprover;
    }
    reasonable_adjustments::save($data);
    redirect($back, get_string('adjustmentssaved', 'report_evidencereporter'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
$form->display();
echo $OUTPUT->footer();
