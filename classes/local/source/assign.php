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

namespace report_evidencereporter\local\source;

use moodle_url;
use report_evidencereporter\local\evidence_pack;
use report_evidencereporter\local\feedback_visibility;
use stdClass;

/**
 * Assignments: links to the grader on the learner, every graded attempt with its feedback, and in the pack the
 * submitted files, online text, feedback files and annotated PDF.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign extends base {
    #[\Override]
    public function get_modname(): string {
        return 'assign';
    }

    #[\Override]
    public function get_description(): string {
        return get_string('sourcedesc_assign', 'report_evidencereporter');
    }

    #[\Override]
    public function get_url(stdClass $row): ?moodle_url {
        // The grader opens on this learner: submission, mark and feedback together.
        return new moodle_url('/mod/assign/view.php', ['id' => (int) $row->cmid, 'action' => 'grader',
            'userid' => (int) ($row->userid ?? 0)]);
    }

    #[\Override]
    public function can_view_unreleased(\context_module $context): bool {
        return has_capability('mod/assign:grade', $context);
    }

    #[\Override]
    public function get_similarity_html(stdClass $row): string {
        global $DB;
        $cm = get_coursemodule_from_id('assign', (int) $row->cmid);
        if (!$cm) {
            return '';
        }
        $submission = $DB->get_record('assign_submission', ['assignment' => $cm->instance, 'userid' => (int) $row->userid,
            'groupid' => 0, 'latest' => 1]);
        if (!$submission) {
            return '';
        }
        $context = \context_module::instance($cm->id);
        $base = ['userid' => (int) $row->userid, 'cmid' => (int) $cm->id, 'course' => (int) $cm->course];
        $parts = [];
        $files = get_file_storage()->get_area_files(
            $context->id,
            'assignsubmission_file',
            'submission_files',
            $submission->id,
            'filename',
            false
        );
        foreach ($files as $file) {
            $links = \report_evidencereporter\local\similarity::get_links($base + ['file' => $file]);
            if ($links !== '') {
                $parts[] = s($file->get_filename()) . ': ' . $links;
            }
        }
        $onlinetext = $DB->get_field('assignsubmission_onlinetext', 'onlinetext', ['submission' => $submission->id]);
        if ($onlinetext !== false && trim(strip_tags((string) $onlinetext)) !== '') {
            $links = \report_evidencereporter\local\similarity::get_links($base + ['content' => trim($onlinetext),
                'assignment' => (int) $cm->instance]);
            if ($links !== '') {
                $parts[] = s(get_string('packonlinetext', 'report_evidencereporter')) . ': ' . $links;
            }
        }
        return implode('<br>', $parts);
    }

    #[\Override]
    public function has_attempts(): bool {
        return true;
    }

    #[\Override]
    public function format_attempt(stdClass $row, stdClass $result, bool $canseeunreleased, bool $viewfullnames): stdClass {
        $value = null;
        $overallfeedback = '';
        $showmarks = true;
        $showfeedback = true;
        $value = $row->assigngrade === null ? null : (float) $row->assigngrade;
        $result->url = new moodle_url(
            '/mod/assign/view.php',
            ['id' => $row->cmid, 'action' => 'grader', 'userid' => $row->userid]
        );
        // Earlier attempts were released when the attempt was reopened; the latest follows workflow and hiding.
        if (!empty($row->islatest)) {
            $released = feedback_visibility::is_released($row);
        } else {
            $released = feedback_visibility::is_released((object) ['gradeitemhidden' => $row->gradeitemhidden]);
        }
        $result->released = $released;
        if (!$released && !$canseeunreleased) {
            $showmarks = $showfeedback = false;
        }
        if (!empty($row->graderid)) {
            $person = new stdClass();
            foreach (\core_user\fields::get_name_fields() as $namefield) {
                $person->{$namefield} = $row->{'g_' . $namefield} ?? '';
            }
            $result->marker = fullname($person, $viewfullnames);
        }
        if ($row->feedbacktext !== null && trim(strip_tags($row->feedbacktext)) !== '') {
            $context = \context_module::instance((int) $row->cmid, IGNORE_MISSING) ?: \context_system::instance();
            $overallfeedback = format_text($row->feedbacktext, (int) $row->feedbackformat, ['context' => $context]);
        }
        return (object) ['value' => $value, 'showmarks' => $showmarks, 'showfeedback' => $showfeedback,
            'overallfeedback' => $overallfeedback];
    }

    #[\Override]
    public function add_attempt_to_pack(
        evidence_pack $pack,
        string $folder,
        \cm_info $cm,
        stdClass $row,
        stdClass $attempt,
        array $compnames
    ): void {
        global $DB;
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();
        $showfeedback = $pack->includes_feedback() && ($attempt->released || $attempt->canseeunreleased);

        $record = $pack->attempt_header_html($cm, $attempt, $compnames);
        if ($pack->includes_feedback()) {
            $record .= '<h3>' . s(get_string('feedback', 'report_evidencereporter')) . '</h3>'
                . ($attempt->feedback !== '' ? $attempt->feedback : '<p>-</p>');
        } else {
            $record .= '<p><em>' . s(get_string('feedbackexcluded', 'report_evidencereporter')) . '</em></p>';
        }

        $submission = $DB->get_record('assign_submission', ['assignment' => $cm->instance, 'userid' => $pack->get_userid(),
            'attemptnumber' => $attempt->attemptno - 1, 'groupid' => 0]);
        if ($submission) {
            $record .= '<h3>' . s(get_string('packsubmission', 'report_evidencereporter')) . '</h3><p>'
                . s(get_string('submissionstatus_' . $submission->status, 'mod_assign')) . '</p>';
            $onlinetext = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submission->id]);
            if ($onlinetext && trim(strip_tags((string) $onlinetext->onlinetext)) !== '') {
                $text = format_text(
                    $onlinetext->onlinetext,
                    (int) $onlinetext->onlineformat,
                    ['context' => $context, 'noclean' => false]
                );
                $text = $pack->get_identity()->text($text);
                $pack->add_content(
                    $folder . '/' . get_string('packonlinetext', 'report_evidencereporter') . '.html',
                    $pack->html_document(get_string('packonlinetext', 'report_evidencereporter'), $text)
                );
            }
            foreach (
                $fs->get_area_files(
                    $context->id,
                    'assignsubmission_file',
                    'submission_files',
                    $submission->id,
                    'filepath, filename',
                    false
                ) as $file
            ) {
                $pack->add_file($folder . '/' . get_string('packsubmittedfiles', 'report_evidencereporter'), $file);
            }
        }

        if ($showfeedback) {
            foreach (
                [['assignfeedback_file', 'feedback_files'], ['assignfeedback_editpdf', 'download']] as [$component,
                    $area]
            ) {
                foreach (
                    $fs->get_area_files(
                        $context->id,
                        $component,
                        $area,
                        (int) $row->attemptid,
                        'filepath, filename',
                        false
                    ) as $file
                ) {
                    $pack->add_file($folder . '/' . get_string('packfeedbackfiles', 'report_evidencereporter'), $file);
                }
            }
        }

        $pack->add_content(
            $folder . '/' . get_string('packattemptrecord', 'report_evidencereporter') . '.pdf',
            $pack->html_to_pdf($cm->get_formatted_name(), $record)
        );
    }
}
