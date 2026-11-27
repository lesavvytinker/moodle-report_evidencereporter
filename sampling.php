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
 * Validation sampling: draw a reproducible random sample of judgements, swap items with a recorded reason, and build
 * the evidence for it.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use report_evidencereporter\local\bulk;
use report_evidencereporter\local\dates;
use report_evidencereporter\local\sampling;
use report_evidencereporter\local\scope;
use report_evidencereporter\local\terms;

$id = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$itemid = optional_param('itemid', 0, PARAM_INT);

admin_externalpage_setup('report_evidencereporter_sampling');
$context = context_system::instance();
require_capability(bulk::CAPABILITY, $context);
$listurl = new moodle_url('/report/evidencereporter/sampling.php');

if (!\core_competency\api::is_enabled()) {
    throw new moodle_exception('competenciesarenotenabled', 'core_competency');
}
$str = fn($key, $a = null) => get_string($key, 'report_evidencereporter', $a);

// The list of samples, and a form to draw a new one.
if (!$id) {
    $form = new \report_evidencereporter\form\sample_form($listurl);
    $notice = '';
    if ($data = $form->get_data()) {
        $filters = \report_evidencereporter\form\sample_form::to_filters($data);
        if (!sampling::get_population($filters)) {
            $notice = $str('samplenopopulation');
        } else {
            $newid = sampling::draw(trim((string) $data->name), $filters, $data->seed ? (int) $data->seed : null);
            redirect(
                new moodle_url($listurl, ['id' => $newid]),
                $str('sampledrawn'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading($str('sampling'));
    echo html_writer::tag('p', $str('samplingintro', sampling::MAX_SIZE));
    if ($notice) {
        echo $OUTPUT->notification($notice, \core\output\notification::NOTIFY_WARNING);
    }

    $samples = sampling::get_samples();
    if ($samples) {
        echo $OUTPUT->heading($str('samplesdrawn'), 3);
        $table = new html_table();
        $table->head = [$str('samplename'), $str('sampledrawnon'), get_string('user'), $str('samplesize'),
            $str('samplepopulationcol'), $str('sampleswapscol')];
        foreach ($samples as $sample) {
            $creator = core_user::get_user($sample->createdby);
            $table->data[] = [
                html_writer::link(new moodle_url($listurl, ['id' => $sample->id]), format_string($sample->name)),
                \report_evidencereporter\local\dates::datetime($sample->timecreated),
                $creator ? fullname($creator) : '-',
                (int) $sample->active,
                (int) $sample->population,
                (int) $sample->swapped,
            ];
        }
        echo html_writer::table($table);
    }
    echo $OUTPUT->heading($str('samplenew'), 3);
    $form->display();
    echo $OUTPUT->footer();
    die;
}

// One sample.
$sample = $DB->get_record(sampling::TABLE, ['id' => $id], '*', MUST_EXIST);
$url = new moodle_url($listurl, ['id' => $id]);
$PAGE->set_url($url);
$PAGE->navbar->add(format_string($sample->name), $url);
$items = sampling::get_items($id);
$number = [];
foreach ($items as $n => $item) {
    $number[(int) $item->id] = $n + 1;
}

// Swap a judgement out.
$swapform = null;
if ($action === 'swap' && $itemid && isset($number[$itemid])) {
    $item = $items[$number[$itemid] - 1];
    if ($item->removed) {
        redirect($url);
    }
    $learner = core_user::get_user($item->userid);
    $unit = $DB->get_record('competency', ['id' => $item->competencyid], 'id, shortname, idnumber');
    $label = '#' . $number[$itemid] . ' ' . ($learner ? fullname($learner) : '') . ' — '
        . ($unit ? scope::unit_label($unit) : '');
    $swapform = new \report_evidencereporter\form\sample_swap_form($url, ['label' => $label]);
    $swapform->set_data(['id' => $id, 'itemid' => $itemid]);
    if ($swapform->is_cancelled()) {
        redirect($url);
    }
    if ($data = $swapform->get_data()) {
        $replacement = sampling::swap($itemid, trim($data->reason));
        redirect(
            $url,
            $replacement ? $str('sampleswapped') : $str('sampleswappednone'),
            null,
            $replacement ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
        );
    }
}

// Build the download.
$downloadform = new \report_evidencereporter\form\sample_download_form($url);
$downloadform->set_data(['id' => $id]);
if (!$swapform && ($data = $downloadform->get_data())) {
    $task = new \report_evidencereporter\task\bulk_download();
    $task->set_userid($USER->id);
    $task->set_custom_data([
        'sampleid' => $id,
        'sourcetype' => 'course',
        'sourceid' => 0,
        'subcategories' => 0,
        'frameworkid' => 0,
        'competencyid' => 0,
        'mode' => $data->mode,
        'includefeedback' => (int) $data->includefeedback,
        'redactname' => (int) $data->redactname,
        'redactusername' => (int) $data->redactusername,
        'redactstaff' => (int) $data->redactstaff,
    ]);
    \core\task\manager::queue_adhoc_task($task);
    redirect(
        new moodle_url('/report/evidencereporter/bulk.php'),
        get_string('bulkqueued', 'report_evidencereporter'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($sample->name));

if ($swapform) {
    echo $OUTPUT->heading($str('sampleswapheading'), 3);
    echo html_writer::tag('p', $str('sampleswapintro'));
    $swapform->display();
    echo $OUTPUT->footer();
    die;
}

// The sampling record.
$filters = sampling::normalise_filters(json_decode($sample->filters, true) ?: []);
$active = array_filter($items, fn($i) => !$i->removed);
$creator = core_user::get_user($sample->createdby);
$record = [
    $str('sampledrawnbylabel') => ($creator ? fullname($creator) : '-') . ', '
        . dates::datetime((int) $sample->timecreated, 'strftimedaydatetime'),
];
$record += sampling::describe_filters($filters);
$record[$str('samplepopulationcol')] = $str('samplepopulation', ['size' => count($active),
    'population' => (int) $sample->population]);
$record[$str('sampledrawcodefield')] = $str('sampledrawcode', ['code' => $sample->seed,
    'check' => substr($sample->populationhash, 0, 8)]);
$dl = '';
foreach ($record as $label => $value) {
    $dl .= html_writer::tag('dt', s($label), ['class' => 'col-sm-3']) . html_writer::tag(
        'dd',
        s($value),
        ['class' => 'col-sm-9']
    );
}
echo html_writer::tag('dl', $dl, ['class' => 'row report_evidencereporter-samplerecord']);

// The judgements.
$table = new html_table();
$table->attributes['class'] = 'generaltable report_evidencereporter-sample';
$table->head = ['#', terms::get('learner'), terms::get('unit'), get_string('course'), terms::get('assessor'),
    $str('sampleresult'), $str('samplemark'), $str('samplejudged'), $str('samplegroup'), $str('samplestatus'), ''];
$units = [];
foreach ($items as $item) {
    $learner = core_user::get_user($item->userid);
    $units[$item->competencyid] ??= $DB->get_record(
        'competency',
        ['id' => $item->competencyid],
        'id, shortname, idnumber'
    ) ?: null;
    $assessor = $item->assessorid ? core_user::get_user($item->assessorid) : null;
    $summary = new moodle_url('/report/evidencereporter/print.php', ['userid' => $item->userid,
        'scope' => scope::UNIT, 'competencyid' => $item->competencyid]);
    if ($item->removed) {
        $remover = $item->removedby ? core_user::get_user($item->removedby) : null;
        $status = html_writer::span($str('sampleswappedout'), 'badge bg-secondary') . ' ' . html_writer::div(
            s($item->removedreason) . ' — ' . ($remover ? fullname($remover) : '') . ', '
                . \report_evidencereporter\local\dates::datetime($item->timeremoved),
            'small text-muted'
        );
        $actions = '';
    } else {
        $status = $item->reason === 'replacement'
            ? html_writer::span($str('samplehowreplacement', $number[(int) $item->replacesid] ?? '?'), 'badge bg-info')
            : html_writer::span($str('samplehowdrawn'), 'badge bg-light text-dark');
        $actions = html_writer::link(
            new moodle_url($url, ['action' => 'swap', 'itemid' => $item->id]),
            $str('sampleswap'),
            ['class' => 'btn btn-sm btn-outline-secondary']
        );
    }
    $row = new html_table_row([
        $number[(int) $item->id],
        $learner ? html_writer::link($summary, fullname($learner)) : '-',
        $units[$item->competencyid] ? s(scope::unit_label($units[$item->competencyid])) : '-',
        format_string((string) $DB->get_field('course', 'shortname', ['id' => $item->courseid])),
        $assessor ? fullname($assessor) : '-',
        s($item->result),
        $item->mark === null ? '-' : format_float((float) $item->mark, 1) . '%',
        $item->judged ? \report_evidencereporter\local\dates::date($item->judged) : '-',
        s($item->groupkey),
        $status,
        $actions,
    ]);
    if ($item->removed) {
        $row->attributes['class'] = 'dimmed_text';
    }
    $table->data[] = $row;
}
echo html_writer::table($table);

echo $OUTPUT->heading($str('samplebuild'), 3);
echo html_writer::tag('p', $str('samplebuildintro'));
$downloadform->display();
echo html_writer::link($listurl, $str('samplebacktolist'));
echo $OUTPUT->footer();
