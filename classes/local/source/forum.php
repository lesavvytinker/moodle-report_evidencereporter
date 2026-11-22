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
use report_evidencereporter\local\forum_posts;
use report_evidencereporter\local\identity;
use report_evidencereporter\local\similarity;
use report_evidencereporter\local\terms;
use stdClass;

/**
 * Forums: links to the learner's posts in the course.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forum extends base {
    #[\Override]
    public function get_modname(): string {
        return 'forum';
    }

    #[\Override]
    public function get_description(): string {
        return get_string('sourcedesc_forum', 'report_evidencereporter');
    }

    /**
     * Staff who grade or rate in the forum see similarity results and hidden grades' context.
     *
     * @param \context_module $context
     * @return bool
     */
    #[\Override]
    public function can_view_unreleased(\context_module $context): bool {
        return has_any_capability(['mod/forum:grade', 'mod/forum:rate', 'moodle/grade:viewall'], $context);
    }

    #[\Override]
    public function get_records_heading(): string {
        return get_string('forumcontributions', 'report_evidencereporter');
    }

    #[\Override]
    public function get_records(int $userid, array $links, bool $viewfullnames, ?identity $identity): array {
        $result = [];
        foreach ($links as $link) {
            $data = forum_posts::for_user((int) $link->cmid, $userid);
            if (!$data) {
                continue;
            }
            $result[] = (object) [
                'competencyid' => (int) $link->competencyid,
                'activity' => $data->cm->get_formatted_name(),
                'cmid' => (int) $link->cmid,
                'count' => get_string('forumpostcount', 'report_evidencereporter', count($data->posts)),
                'html' => forum_posts::html(
                    $data,
                    $identity,
                    $viewfullnames,
                    false,
                    similarity::allowed((int) $link->cmid, 'forum')
                ),
            ];
        }
        return $result;
    }

    /**
     * Each post and its attachments, with what it replied to, ratings and similarity results, in the forum's folder.
     *
     * @param evidence_pack $pack
     * @param int[] $competencyids
     */
    #[\Override]
    public function add_records_to_pack(evidence_pack $pack, array $competencyids): void {
        $competenciesbycm = [];
        foreach (forum_posts::get_linked($pack->get_userid(), $competencyids) as $link) {
            $competenciesbycm[(int) $link->cmid][] = (int) $link->competencyid;
        }
        foreach ($competenciesbycm as $cmid => $compids) {
            $data = forum_posts::for_user($cmid, $pack->get_userid());
            if (!$data) {
                continue;
            }
            $name = $data->cm->get_formatted_name();
            $folder = '02 ' . get_string('packassessments', 'report_evidencereporter') . '/'
                . $pack->safe_name(format_string($data->course->shortname) . ' - ' . $name);
            $html = '<h2>' . s($name) . '</h2>'
                . '<table border="1" cellpadding="4" width="100%">'
                . $pack->learner_rows()
                . $pack->table_row(terms::get('competency'), s(implode('; ', $pack->competency_names($compids))))
                . $pack->table_row(get_string('activitytype', 'report_evidencereporter'), s(get_string('modulename', 'mod_forum')))
                . $pack->table_row('URL', s((new moodle_url('/mod/forum/view.php', ['id' => $cmid]))->out(false)))
                . '</table>'
                . forum_posts::html(
                    $data,
                    $pack->get_identity(),
                    $pack->can_view_fullnames(),
                    true,
                    similarity::allowed($cmid, 'forum', true),
                    $pack->includes_feedback()
                );
            if (!$pack->includes_feedback()) {
                $html .= '<p><em>' . s(get_string('feedbackexcluded', 'report_evidencereporter')) . '</em></p>';
            }
            $pack->add_content(
                $folder . '/' . get_string('packforumposts', 'report_evidencereporter') . '.pdf',
                $pack->html_to_pdf($name, $html)
            );
            foreach ($data->posts as $post) {
                foreach ($post->attachments as $file) {
                    $pack->add_file($folder . '/' . get_string('forumattachments', 'report_evidencereporter'), $file);
                }
            }
        }
    }

    #[\Override]
    public function get_url(stdClass $row): ?moodle_url {
        return new moodle_url('/mod/forum/user.php', ['id' => (int) ($row->userid ?? 0), 'course' => (int) $row->courseid]);
    }
}
