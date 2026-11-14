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
 * Bulk downloads of evidence summaries or packs for a learning plan template or cohort.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use report_evidencereporter\local\bulk;

$action = optional_param('action', '', PARAM_ALPHA);
$fileid = optional_param('fileid', 0, PARAM_INT);

admin_externalpage_setup('report_evidencereporter_bulk');
$context = context_system::instance();
require_capability(bulk::CAPABILITY, $context);
$url = new moodle_url('/report/evidencereporter/bulk.php');

if (!\core_competency\api::is_enabled()) {
    throw new moodle_exception('competenciesarenotenabled', 'core_competency');
}

// Delete one of your own downloads.
if ($action === 'delete' && $fileid && confirm_sesskey()) {
    $file = get_file_storage()->get_file_by_id($fileid);
    if (
        $file && $file->get_component() === 'report_evidencereporter' && $file->get_filearea() === bulk::FILEAREA
            && $file->get_contextid() == context_user::instance($USER->id)->id
    ) {
        $file->delete();
    }
    redirect($url, get_string('bulkdeleted', 'report_evidencereporter'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$form = new \report_evidencereporter\form\bulk_form($url);
if ($data = $form->get_data()) {
    $task = new \report_evidencereporter\task\bulk_download();
    $task->set_userid($USER->id);
    $task->set_custom_data([
        'sourcetype' => $data->sourcetype,
        'sourceid' => (int) ($data->sourcetype === 'cohort' ? $data->cohortid : $data->templateid),
        'frameworkid' => (int) $data->frameworkid,
        'mode' => $data->mode,
        'includefeedback' => (int) $data->includefeedback,
        'redactname' => (int) $data->redactname,
        'redactusername' => (int) $data->redactusername,
        'redactstaff' => (int) $data->redactstaff,
    ]);
    \core\task\manager::queue_adhoc_task($task);
    redirect($url, get_string('bulkqueued', 'report_evidencereporter'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('bulkdownloads', 'report_evidencereporter'));
echo html_writer::tag('p', get_string('bulkintro', 'report_evidencereporter', ['max' => bulk::MAX_LEARNERS,
    'days' => bulk::KEEP_DAYS]));
$form->display();

$pending = bulk::get_pending((int) $USER->id);
if ($pending) {
    echo $OUTPUT->notification(
        get_string('bulkpending', 'report_evidencereporter', count($pending)),
        \core\output\notification::NOTIFY_INFO,
        false
    );
}

echo $OUTPUT->heading(get_string('bulkready', 'report_evidencereporter'), 3);
$files = bulk::get_files((int) $USER->id);
if (!$files) {
    echo html_writer::tag('p', get_string('bulknone', 'report_evidencereporter'), ['class' => 'text-muted']);
} else {
    $table = new html_table();
    $table->head = [get_string('bulkfile', 'report_evidencereporter'), get_string('bulkcreated', 'report_evidencereporter'),
        get_string('bulkcontents', 'report_evidencereporter'), get_string('bulkexpires', 'report_evidencereporter'), ''];
    foreach ($files as $file) {
        $download = moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename(),
            true
        );
        $delete = new moodle_url($url, ['action' => 'delete', 'fileid' => $file->get_id(), 'sesskey' => sesskey()]);
        $table->data[] = [
            html_writer::link($download, s($file->get_filename())) . ' ' . html_writer::span(
                display_size($file->get_filesize()),
                'text-muted small'
            ),
            userdate($file->get_timecreated()),
            s((string) $file->get_source()),
            userdate($file->get_timecreated() + bulk::KEEP_DAYS * DAYSECS, get_string('strftimedate', 'core_langconfig')),
            html_writer::link($delete, get_string('delete'), ['class' => 'btn btn-sm btn-outline-danger']),
        ];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
