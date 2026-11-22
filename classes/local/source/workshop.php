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
use report_evidencereporter\local\identity;
use report_evidencereporter\local\similarity;
use report_evidencereporter\local\summary;
use report_evidencereporter\local\terms;
use stdClass;

/**
 * Workshop: the learner's submission (with its grade, any override and feedback), the assessments it received, and
 * the grade for the assessments the learner gave. Peer reviewers are other learners, so they're shown as "Peer
 * reviewer" rather than by name; staff reviewers are named (unless redacted). Learners see grades once the workshop
 * is closed, as the workshop itself does.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class workshop extends base {
    /** Workshop phase: closed, when grades are shown to learners. */
    private const PHASE_CLOSED = 50;

    /** @var array Records cached by cmid and userid. */
    private static array $cache = [];

    #[\Override]
    public function get_modname(): string {
        return 'workshop';
    }

    #[\Override]
    public function get_description(): string {
        return get_string('sourcedesc_workshop', 'report_evidencereporter');
    }

    #[\Override]
    public function can_view_unreleased(\context_module $context): bool {
        return has_capability('mod/workshop:viewallassessments', $context);
    }

    #[\Override]
    public function get_activity_done_sql(string $instance, int $userid): ?string {
        return "EXISTS (SELECT 1 FROM {workshop_submissions} lws
                         WHERE lws.workshopid = {$instance} AND lws.authorid = {$userid} AND lws.example = 0)";
    }

    #[\Override]
    public function get_records_heading(): string {
        return get_string('workshoprecord', 'report_evidencereporter');
    }

    #[\Override]
    public function get_records(int $userid, array $links, bool $viewfullnames, ?identity $identity): array {
        $result = [];
        foreach ($links as $link) {
            $data = self::load((int) $link->cmid, $userid);
            if (!$data) {
                continue;
            }
            $result[] = (object) [
                'competencyid' => (int) $link->competencyid,
                'activity' => $data->cm->get_formatted_name(),
                'cmid' => (int) $link->cmid,
                'count' => get_string('workshopsummary', 'report_evidencereporter', count($data->received)),
                'html' => self::html($data, $identity, $viewfullnames, false, similarity::allowed(
                    (int) $link->cmid,
                    'workshop'
                )),
            ];
        }
        return $result;
    }

    #[\Override]
    public function add_records_to_pack(evidence_pack $pack, array $competencyids): void {
        $compsbycm = [];
        foreach (summary::get_linked_activities($pack->get_userid(), null, $competencyids) as $link) {
            if ($link->modname === 'workshop') {
                $compsbycm[(int) $link->cmid][] = (int) $link->competencyid;
            }
        }
        foreach ($compsbycm as $cmid => $compids) {
            $data = self::load($cmid, $pack->get_userid());
            if (!$data) {
                continue;
            }
            $name = $data->cm->get_formatted_name();
            $folder = '02 ' . get_string('packassessments', 'report_evidencereporter') . '/'
                . $pack->safe_name(format_string($data->course->shortname) . ' - ' . $name);
            $html = '<h2>' . s($name) . '</h2><table border="1" cellpadding="4" width="100%">'
                . $pack->learner_rows()
                . $pack->table_row(terms::get('competency'), s(implode('; ', $pack->competency_names($compids))))
                . $pack->table_row(
                    get_string('activitytype', 'report_evidencereporter'),
                    s(get_string('modulename', 'mod_workshop'))
                )
                . $pack->table_row('URL', s((new moodle_url('/mod/workshop/view.php', ['id' => $cmid]))->out(false)))
                . '</table>'
                . self::html(
                    $data,
                    $pack->get_identity(),
                    $pack->can_view_fullnames(),
                    true,
                    similarity::allowed($cmid, 'workshop', true),
                    $pack->includes_feedback()
                );
            $pack->add_content(
                $folder . '/' . get_string('workshoprecord', 'report_evidencereporter') . '.pdf',
                $pack->html_to_pdf($name, $html)
            );
            foreach ($data->attachments as $file) {
                $pack->add_file($folder . '/' . get_string('packsubmittedfiles', 'report_evidencereporter'), $file);
            }
        }
    }

    /**
     * The learner's submission and its assessments, or null if they didn't submit.
     *
     * @param int $cmid
     * @param int $userid
     * @return stdClass|null
     */
    private static function load(int $cmid, int $userid): ?stdClass {
        global $DB;
        $key = $cmid . '-' . $userid;
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        self::$cache[$key] = null;
        try {
            [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'workshop');
        } catch (\Throwable $e) {
            return null;
        }
        $workshop = $DB->get_record('workshop', ['id' => $cm->instance]);
        $submission = $DB->get_record('workshop_submissions', ['workshopid' => $cm->instance, 'authorid' => $userid,
            'example' => 0], '*', IGNORE_MULTIPLE);
        if (!$workshop || !$submission) {
            return null;
        }
        $context = \context_module::instance($cm->id);
        $received = $DB->get_records_select(
            'workshop_assessments',
            'submissionid = :sid AND grade IS NOT NULL',
            ['sid' => $submission->id],
            'timemodified, id'
        );
        foreach ($received as $assessment) {
            $reviewer = \core_user::get_user($assessment->reviewerid);
            $assessment->reviewer = $reviewer;
            $assessment->isstaff = $reviewer && has_any_capability(['mod/workshop:overridegrades',
                'mod/workshop:viewallassessments'], $context, $reviewer);
        }
        $given = $DB->count_records_sql(
            "SELECT COUNT(1) FROM {workshop_assessments} wa
                                           JOIN {workshop_submissions} ws ON ws.id = wa.submissionid
                                          WHERE ws.workshopid = :w AND wa.reviewerid = :u AND wa.grade IS NOT NULL",
            ['w' => $workshop->id, 'u' => $userid]
        );
        $gradinggrade = $DB->get_field('workshop_aggregations', 'gradinggrade', ['workshopid' => $workshop->id,
            'userid' => $userid]);
        $attachments = array_values(get_file_storage()->get_area_files(
            $context->id,
            'mod_workshop',
            'submission_attachment',
            $submission->id,
            'filename',
            false
        ));
        return self::$cache[$key] = (object) ['cm' => $cm, 'course' => $course, 'workshop' => $workshop,
            'submission' => $submission, 'received' => array_values($received), 'given' => $given,
            'gradinggrade' => $gradinggrade === false ? null : $gradinggrade, 'attachments' => $attachments,
            'context' => $context, 'userid' => $userid];
    }

    /**
     * The submission, its grade and the assessments, as HTML.
     *
     * @param stdClass $data
     * @param identity|null $identity
     * @param bool $viewfullnames
     * @param bool $forpdf
     * @param bool $similarity
     * @param bool $includefeedback
     * @return string
     */
    private static function html(
        stdClass $data,
        ?identity $identity,
        bool $viewfullnames,
        bool $forpdf = false,
        bool $similarity = false,
        bool $includefeedback = true
    ): string {
        $sub = $data->submission;
        $context = $data->context;
        $redact = fn(string $html) => $identity ? $identity->text($html) : $html;
        $showgrades = (int) $data->workshop->phase >= self::PHASE_CLOSED || has_capability(
            'mod/workshop:viewallassessments',
            $context
        );
        $scaled = fn($value, $max) => format_float((float) $value * (float) $max / 100, 2) . ' / '
            . format_float((float) $max, 2);
        $datefmt = get_string('strftimedatetimeshort', 'core_langconfig');

        // The submission.
        $title = format_string($sub->title, true, ['context' => $context]);
        $html = '<p class="mb-1"><strong>' . s($redact($title)) . '</strong><br><span class="small text-muted">'
            . s(get_string('workshopsubmitted', 'report_evidencereporter', userdate($sub->timemodified, $datefmt)))
            . '</span></p>';
        $content = file_rewrite_pluginfile_urls(
            $sub->content,
            'pluginfile.php',
            $context->id,
            'mod_workshop',
            'submission_content',
            $sub->id
        );
        $content = $redact(format_text($content, $sub->contentformat, ['context' => $context]));
        if (trim(strip_tags($content)) !== '') {
            $html .= $forpdf
                ? '<div>' . preg_replace(
                    '/<img\b[^>]*>/i',
                    '[' . s(get_string('image', 'report_evidencereporter')) . ']',
                    $content
                ) . '</div>'
                : '<details><summary class="small">' . s(get_string('workshopshowsubmission', 'report_evidencereporter'))
                    . '</summary>' . $content . '</details>';
        }
        if ($data->attachments) {
            $names = [];
            foreach ($data->attachments as $file) {
                $name = s($file->get_filename());
                $names[] = $forpdf ? $name : \html_writer::link(
                    moodle_url::make_pluginfile_url(
                        $context->id,
                        'mod_workshop',
                        'submission_attachment',
                        $sub->id,
                        $file->get_filepath(),
                        $file->get_filename()
                    ),
                    $name,
                    ['target' => '_blank', 'rel' => 'noopener']
                );
            }
            $html .= '<p class="small mb-1">' . s(get_string('forumattachments', 'report_evidencereporter')) . ': '
                . implode(', ', $names) . '</p>';
        }
        if ($similarity) {
            $base = ['userid' => (int) $data->userid, 'cmid' => (int) $data->cm->id, 'course' => (int) $data->course->id];
            $parts = [];
            if (($links = similarity::get_links($base + ['content' => $sub->content])) !== '') {
                $parts[] = s(get_string('workshopsubmissiontext', 'report_evidencereporter')) . ': ' . $links;
            }
            foreach ($data->attachments as $file) {
                if (($links = similarity::get_links($base + ['file' => $file])) !== '') {
                    $parts[] = s($file->get_filename()) . ': ' . $links;
                }
            }
            if ($parts) {
                $text = implode(' | ', $parts);
                $html .= '<p class="small mb-1">' . s(get_string('similaritycheck', 'report_evidencereporter')) . ': '
                    . ($forpdf ? s(trim(preg_replace('/\s+/', ' ', html_to_text($text, 0, false)))) : $text) . '</p>';
            }
        }

        // Grades.
        if ($showgrades) {
            $final = $sub->gradeover !== null ? $sub->gradeover : $sub->grade;
            if ($final !== null) {
                $html .= '<p class="mb-1">' . s(get_string('workshopsubmissiongrade', 'report_evidencereporter')) . ': <strong>'
                    . s($scaled($final, $data->workshop->grade)) . '</strong>'
                    . ($sub->gradeover !== null ? ' ' . s(get_string('workshopoverridden', 'report_evidencereporter')) : '')
                    . '</p>';
            }
            if ($includefeedback && trim(strip_tags((string) $sub->feedbackauthor)) !== '') {
                $html .= '<div class="er-feedback">' . $redact(format_text(
                    $sub->feedbackauthor,
                    $sub->feedbackauthorformat,
                    ['context' => $context]
                )) . '</div>';
            }
            if ($data->gradinggrade !== null) {
                $html .= '<p class="mb-1">' . s(get_string('workshopgradinggrade', 'report_evidencereporter', $data->given))
                    . ': <strong>' . s($scaled($data->gradinggrade, $data->workshop->gradinggrade)) . '</strong></p>';
            }
        } else {
            $html .= '<p class="mb-1 small text-muted">' . s(get_string('workshopgradespending', 'report_evidencereporter'))
                . '</p>';
        }

        // Assessments received.
        if ($data->received && $showgrades) {
            $html .= '<p class="mb-1 mt-2"><strong>' . s(get_string('workshopreceived', 'report_evidencereporter'))
                . '</strong></p>';
            foreach ($data->received as $assessment) {
                $who = $assessment->isstaff && $assessment->reviewer
                    ? ($identity ? $identity->staff(fullname($assessment->reviewer, $viewfullnames))
                        : fullname($assessment->reviewer, $viewfullnames))
                    : get_string('workshoppeer', 'report_evidencereporter');
                $html .= '<div class="border-top pt-1 mt-1"><p class="mb-1">' . s($who) . ': <strong>'
                    . s($scaled($assessment->grade, $data->workshop->grade)) . '</strong>'
                    . ((float) $assessment->weight === 0.0 ? ' ' . s(get_string('workshopnotcounted', 'report_evidencereporter'))
                        : '') . '</p>';
                if ($includefeedback && trim(strip_tags((string) $assessment->feedbackauthor)) !== '') {
                    $html .= '<div class="er-feedback small">' . $redact(format_text(
                        $assessment->feedbackauthor,
                        $assessment->feedbackauthorformat,
                        ['context' => $context]
                    )) . '</div>';
                }
                $html .= '</div>';
            }
        }
        if (!$forpdf) {
            $url = new moodle_url('/mod/workshop/submission.php', ['cmid' => $data->cm->id, 'id' => $sub->id]);
            $html .= '<p class="small mb-0 mt-1"><a href="' . $url->out() . '" target="_blank" rel="noopener">'
                . s(get_string('workshopviewsubmission', 'report_evidencereporter')) . '<span class="visually-hidden"> '
                . s(get_string('opensinnewwindowbracketed')) . '</span></a></p>';
        }
        return $html;
    }

    /**
     * Reset the cache.
     */
    public static function reset_cache(): void {
        self::$cache = [];
    }
}
