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

namespace report_evidencereporter;

use moodle_url;

/**
 * Hook callbacks for Evidence Reporter.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /** @var string[] Activity pages where markers mark (page types; assignment's view only with these actions). */
    private const GRADING_PAGES = ['mod-assign-grader', 'mod-assign-grading', 'mod-assign-view', 'mod-quiz-report',
        'mod-data-view', 'mod-workshop-view', 'mod-workshop-assessment', 'mod-checklist-report', 'mod-forum-view'];

    /**
     * Before an activity's marking page is shown: if its assessment tool details or learners' RA and LLN are missing,
     * say so to the marker, with links to fix it (marking isn't blocked).
     *
     * @param \core\hook\output\before_http_headers $hook
     */
    public static function before_http_headers(\core\hook\output\before_http_headers $hook): void {
        global $PAGE;
        try {
            if (during_initial_install() || !$PAGE->cm || !in_array($PAGE->pagetype, self::GRADING_PAGES)) {
                return;
            }
            if (
                $PAGE->pagetype === 'mod-assign-view'
                    && !in_array(optional_param('action', '', PARAM_ALPHA), ['grading', 'grader', 'grade'])
            ) {
                return;
            }
            $cm = $PAGE->cm;
            if (!local\declarations::available($cm)) {
                return;
            }
            $context = \context_module::instance($cm->id);
            if (
                !local\reasonable_adjustments::can_manage($context)
                    || !has_capability('report/evidencereporter:marking', \context_course::instance($cm->course))
            ) {
                return;
            }
            if (
                $PAGE->pagetype === 'mod-assign-grader'
                    || ($PAGE->pagetype === 'mod-assign-view' && optional_param('action', '', PARAM_ALPHA) === 'grader')
            ) {
                // The grader is a full-screen layout that switches learners without reloading: the warning goes in its
                // grade panel, about the assessment rather than one learner.
                $html = self::warning_html($cm, 0);
                if ($html !== '') {
                    $PAGE->requires->js_init_code('(function() {
                        var html = ' . json_encode(\html_writer::div(
                        $html,
                        'alert alert-warning er-grader-warning',
                        ['role' => 'status']
                    )) . ';
                        var place = function() {
                            var panel = document.querySelector(\'[data-region="grade-panel"]\');
                            if (!panel) {
                                return false;
                            }
                            panel.insertAdjacentHTML(\'afterbegin\', html);
                            return true;
                        };
                        if (!place()) {
                            document.addEventListener(\'DOMContentLoaded\', place);
                        }
                    })();');
                }
                return;
            }
            $html = self::warning_html($cm, optional_param('userid', 0, PARAM_INT));
            if ($html !== '') {
                \core\notification::add($html, \core\notification::WARNING);
            }
        } catch (\Throwable $e) {
            debugging('Evidence Reporter before-marking warning: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * The warning: missing tool details, and RA and LLN still to record (for one learner on a page about them).
     *
     * @param \cm_info $cm
     * @param int $userid The learner the page is about, or 0
     * @return string HTML ('' when nothing's missing)
     */
    public static function warning_html(\cm_info $cm, int $userid): string {
        global $PAGE;
        $parts = [];
        $tool = local\before_marking::tool_status($cm);
        if (!$tool->complete) {
            $link = $tool->canedit
                ? \html_writer::link($tool->editurl, get_string('beforetooledit', 'report_evidencereporter'))
                : \html_writer::link(
                    new moodle_url('/report/evidencereporter/beforemarking.php', ['cmid' => $cm->id,
                    'action' => 'notify', 'sesskey' => sesskey(), 'returnurl' => $PAGE->url->out_as_local_url(false)]),
                    get_string('beforetoolnotify', 'report_evidencereporter')
                );
            $parts[] = s(get_string('beforetoolmissing', 'report_evidencereporter', implode(', ', $tool->missing)))
                . ($tool->notified !== '' ? ' ' . s($tool->notified) : '') . ' ' . $link;
        }
        $outstanding = local\declarations::get_outstanding($cm);
        $outstanding = array_values(array_filter($outstanding, fn($id) => local\declarations::can_see_learner($cm, $id)));
        if ($userid && in_array($userid, $outstanding)) {
            $learner = \core_user::get_user($userid);
            $parts[] = s(get_string('bannerdecllearner', 'report_evidencereporter', fullname($learner))) . ' '
                . \html_writer::link(new moodle_url('/report/evidencereporter/beforemarking.php', ['cmid' => $cm->id,
                    'userid' => $userid]), get_string('bannerrecordnow', 'report_evidencereporter'));
        } else if (!$userid && $outstanding) {
            $parts[] = s(get_string('bannerdecl', 'report_evidencereporter', count($outstanding))) . ' '
                . \html_writer::link(
                    new moodle_url('/report/evidencereporter/declarations.php', ['cmid' => $cm->id]),
                    get_string('bannerrecordnow', 'report_evidencereporter')
                );
        }
        if (!$parts) {
            return '';
        }
        return \html_writer::tag('strong', s(get_string('bannerheading', 'report_evidencereporter'))) . ' '
            . implode(' ', array_map(fn($p) => \html_writer::span($p, 'd-block'), $parts));
    }
}
