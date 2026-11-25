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
 * Reasonable adjustments made for learners in one activity: list, confirm Moodle's own (overrides, extensions), delete.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use report_evidencereporter\local\reasonable_adjustments;

$cmid = required_param('cmid', PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
[$course, $cm] = get_course_and_cm_from_cmid($cmid);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
if (!reasonable_adjustments::supports($cm->modname)) {
    throw new moodle_exception('invalidcoursemodule');
}
if (!has_any_capability(['report/evidencereporter:viewadjustments', 'report/evidencereporter:manageadjustments'], $context)) {
    require_capability('report/evidencereporter:viewadjustments', $context);
}
$canmanage = reasonable_adjustments::can_manage($context);
$url = new moodle_url('/report/evidencereporter/adjustments.php', ['cmid' => $cm->id]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('adjustments', 'report_evidencereporter') . ': ' . $cm->get_formatted_name());
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();

if ($delete && $canmanage) {
    $record = reasonable_adjustments::get($delete);
    if (!$record || (int) $record->cmid !== (int) $cm->id) {
        throw new moodle_exception('invalidrecord', 'error', '', reasonable_adjustments::TABLE);
    }
    if ($confirm && confirm_sesskey()) {
        reasonable_adjustments::delete($delete);
        redirect(
            $url,
            get_string('adjustmentsdeleted', 'report_evidencereporter'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('adjustmentsdeleteconfirm', 'report_evidencereporter', fullname(core_user::get_user($record->userid))),
        new moodle_url($url, ['delete' => $delete, 'confirm' => 1, 'sesskey' => sesskey()]),
        $url
    );
    echo $OUTPUT->footer();
    die();
}

// Learners: enrolled and active, not staff who record adjustments themselves; only the viewer's groups when separate.
$groupmode = groups_get_activity_groupmode($cm);
$groupid = 0;
if ($groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
    $groupid = (int) groups_get_activity_group($cm, true);
}
$learners = get_enrolled_users($context, '', $groupid, 'u.*', 'u.lastname, u.firstname', 0, 0, true);
$learners = array_filter($learners, fn($u) => !has_capability('report/evidencereporter:manageadjustments', $context, $u));
$recorded = reasonable_adjustments::get_for_activity($cm->id);
$suggested = $canmanage ? reasonable_adjustments::get_suggestions($cm) : [];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('adjustments', 'report_evidencereporter') . ': ' . $cm->get_formatted_name());
echo html_writer::tag('p', get_string('adjustmentsintro', 'report_evidencereporter'), ['class' => 'text-muted']);
if ($groupmode) {
    groups_print_activity_menu($cm, $url);
}
if (!$learners) {
    echo $OUTPUT->notification(get_string('adjustmentsnonelearners', 'report_evidencereporter'), 'info');
    echo $OUTPUT->footer();
    die();
}

$table = new html_table();
$table->attributes['class'] = 'generaltable er-adjustments';
$table->head = [\report_evidencereporter\local\terms::get('learner'), get_string('ratype', 'report_evidencereporter'),
    get_string('radetails', 'report_evidencereporter'), get_string('rareason', 'report_evidencereporter'),
    get_string('raapprovedby', 'report_evidencereporter'), get_string('rarecorded', 'report_evidencereporter'), ''];
foreach ($learners as $learner) {
    $name = html_writer::tag('strong', fullname($learner));
    $rows = [];
    foreach (reasonable_adjustments::format($recorded[$learner->id] ?? []) as $item) {
        $actions = '';
        if ($canmanage) {
            $actions = html_writer::link(
                new moodle_url('/report/evidencereporter/adjustment.php', ['id' => $item->id]),
                $OUTPUT->pix_icon('t/edit', get_string('edit'))
            ) . ' ' . html_writer::link(
                new moodle_url($url, ['delete' => $item->id]),
                $OUTPUT->pix_icon('t/delete', get_string('delete'))
            );
        }
        $rows[] = [s($item->type) . ($item->source !== '' ? html_writer::div(s($item->source), 'small text-muted') : ''),
            format_text($item->details, FORMAT_PLAIN), format_text((string) $item->reason, FORMAT_PLAIN), s($item->approvedby),
            s($item->recorded) . html_writer::div(s($item->recordedby), 'small text-muted'), $actions];
    }
    foreach ($suggested[$learner->id] ?? [] as $suggestion) {
        $confirmurl = new moodle_url('/report/evidencereporter/adjustment.php', ['cmid' => $cm->id, 'userid' => $learner->id,
            'source' => $suggestion->source, 'sourceid' => $suggestion->sourceid]);
        $rows[] = [s(reasonable_adjustments::type_label($suggestion->adjtype)) . html_writer::div(
            s(get_string('rasource_' . $suggestion->source, 'report_evidencereporter')) . ' · '
                . s(get_string('adjustmentssuggested', 'report_evidencereporter')),
            'small text-warning'
        ), s($suggestion->details), '', '', '', html_writer::link(
            $confirmurl,
            get_string('adjustmentsconfirm', 'report_evidencereporter'),
            ['class' => 'btn btn-sm btn-outline-primary']
        )];
    }
    $add = $canmanage ? html_writer::link(
        new moodle_url('/report/evidencereporter/adjustment.php', ['cmid' => $cm->id, 'userid' => $learner->id]),
        $OUTPUT->pix_icon('t/add', '') . get_string('adjustmentsadd', 'report_evidencereporter'),
        ['class' => 'small']
    ) : '';
    if (!$rows) {
        $table->data[] = [$name . html_writer::div($add), html_writer::span(
            get_string('adjustmentsnone', 'report_evidencereporter'),
            'text-muted'
        ), '', '', '', '', ''];
        continue;
    }
    foreach ($rows as $i => $row) {
        $table->data[] = array_merge([$i === 0 ? $name . html_writer::div($add) : ''], $row);
    }
}
echo html_writer::table($table);
echo $OUTPUT->footer();
