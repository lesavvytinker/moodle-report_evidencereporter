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
use report_evidencereporter\local\checklist_items;
use report_evidencereporter\local\evidence_pack;
use report_evidencereporter\local\identity;
use stdClass;

/**
 * Checklists (the mod_checklist add-on): links to the learner's checklist report, each item's mark and comments as
 * the feedback, and a checklist record in the pack.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checklist extends base {
    #[\Override]
    public function get_modname(): string {
        return 'checklist';
    }

    #[\Override]
    public function is_installed(): bool {
        return checklist_items::is_installed();
    }

    #[\Override]
    public function get_description(): string {
        return get_string('sourcedesc_checklist', 'report_evidencereporter');
    }

    #[\Override]
    public function get_url(stdClass $row): ?moodle_url {
        // The learner's checklist report: each item's mark and the comments on it.
        return checklist_items::url((int) $row->cmid, (int) ($row->userid ?? 0));
    }

    #[\Override]
    public function shows_current_state(): bool {
        return true;
    }

    #[\Override]
    public function has_own_feedback(stdClass $row): bool {
        return true;
    }

    #[\Override]
    public function get_feedback_html(stdClass $row, bool $viewfullnames, ?identity $identity = null): string {
        $checklist = checklist_items::get_for_user((int) $row->cmid, (int) $row->userid, $viewfullnames);
        return $checklist ? checklist_items::feedback_html($checklist, $identity) : '';
    }

    #[\Override]
    public function add_records_to_pack(evidence_pack $pack, array $competencyids): void {
        $competenciesbycm = [];
        foreach (checklist_items::get_linked($pack->get_userid(), null, $competencyids) as $link) {
            $competenciesbycm[$link->cmid][] = (int) $link->competencyid;
        }
        foreach ($competenciesbycm as $cmid => $competencyids) {
            try {
                [$course, $cm] = get_course_and_cm_from_cmid($cmid);
            } catch (\Throwable $e) {
                continue;
            }
            $checklist = checklist_items::get_for_user((int) $cmid, $pack->get_userid(), $pack->can_view_fullnames());
            if (!$checklist) {
                continue;
            }
            $folder = '02 ' . get_string('packassessments', 'report_evidencereporter') . '/'
                . $pack->safe_name(format_string($course->shortname) . ' - ' . $cm->get_formatted_name());
            $url = checklist_items::url((int) $cmid, $pack->get_userid());
            $html = '<h2>' . s($cm->get_formatted_name()) . '</h2>'
                . '<table border="1" cellpadding="4" width="100%">'
                . $pack->learner_rows()
                . $pack->table_row(
                    \report_evidencereporter\local\terms::get('competency'),
                    s(implode('; ', $pack->competency_names($competencyids)))
                )
                . $pack->table_row(
                    get_string('activitytype', 'report_evidencereporter'),
                    s(get_string('modulename', 'mod_checklist'))
                )
                . $pack->table_row('URL', s($url->out(false)))
                . '</table>'
                . '<h3>' . s(get_string('checklistitems', 'report_evidencereporter')) . '</h3>'
                . checklist_items::record_table($checklist, $pack->get_identity(), $pack->includes_feedback());
            if (!$pack->includes_feedback()) {
                $html .= '<p><em>' . s(get_string('feedbackexcluded', 'report_evidencereporter')) . '</em></p>';
            }
            $pack->add_content(
                $folder . '/' . get_string('packchecklistrecord', 'report_evidencereporter') . '.pdf',
                $pack->html_to_pdf($cm->get_formatted_name(), $html)
            );
        }
    }
}
