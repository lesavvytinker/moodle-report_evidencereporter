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
 * H5P activity: each tracked attempt, with its score, completion and success, and the learner's results per
 * interaction in the evidence pack.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class h5pactivity extends base {
    /** @var array H5P activities by ID. */
    private static array $activities = [];

    #[\Override]
    public function get_modname(): string {
        return 'h5pactivity';
    }

    #[\Override]
    public function get_description(): string {
        return get_string('sourcedesc_h5pactivity', 'report_evidencereporter');
    }

    #[\Override]
    public function get_row_fields_sql(string $instance, string $userid): array {
        return ['h5pinstance' => $instance];
    }

    #[\Override]
    public function get_url(stdClass $row): ?moodle_url {
        if (!empty($row->h5pinstance)) {
            return new moodle_url('/mod/h5pactivity/report.php', ['a' => (int) $row->h5pinstance,
                'userid' => (int) $row->userid]);
        }
        return new moodle_url('/mod/h5pactivity/view.php', ['id' => (int) $row->cmid]);
    }

    #[\Override]
    public function can_view_unreleased(\context_module $context): bool {
        return has_capability('mod/h5pactivity:reviewattempts', $context);
    }

    #[\Override]
    public function has_attempts(): bool {
        return true;
    }

    #[\Override]
    public function format_attempt(stdClass $row, stdClass $result, bool $canseeunreleased, bool $viewfullnames): stdClass {
        $activity = self::get_activity((int) $row->h5pactivityid);
        $result->url = new moodle_url('/mod/h5pactivity/report.php', ['a' => (int) $row->h5pactivityid,
            'attemptid' => (int) $row->attemptid]);
        $result->feedbacklink = true;
        $result->feedbacklabel = get_string('viewattemptreport', 'report_evidencereporter');
        $result->released = feedback_visibility::is_released((object) ['gradeitemhidden' => $row->gradeitemhidden]);
        $value = null;
        if ($activity && (float) $activity->grade > 0 && $row->h5pscaled !== null) {
            $value = (float) $row->h5pscaled * (float) $activity->grade;
        } else if ((int) $row->h5pmaxscore > 0) {
            // Not graded: the raw score is still worth showing.
            $result->grade = (int) $row->h5prawscore . ' / ' . (int) $row->h5pmaxscore;
        }
        $showmarks = $result->released || $canseeunreleased;
        return (object) ['value' => $value, 'showmarks' => $showmarks, 'showfeedback' => $showmarks,
            'overallfeedback' => ''];
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
        $showmarks = $attempt->released || $attempt->canseeunreleased;
        $record = $pack->attempt_header_html($cm, $attempt, $compnames);
        $yesno = fn($value) => $value === null ? '-' : get_string((int) $value ? 'yes' : 'no');
        $record .= '<h3>' . s(get_string('packattemptdetails', 'report_evidencereporter')) . '</h3>'
            . '<table border="1" cellpadding="4" width="100%">'
            . ($showmarks ? $pack->table_row(
                get_string('packscore', 'report_evidencereporter'),
                s((int) $row->h5prawscore . ' / ' . (int) $row->h5pmaxscore
                . ($row->h5pscaled !== null ? ' (' . format_float((float) $row->h5pscaled * 100, 0) . '%)' : ''))
            ) : '')
            . $pack->table_row(get_string('packcompleted', 'report_evidencereporter'), s($yesno($row->h5pcompletion)))
            . ($showmarks ? $pack->table_row(
                get_string('packsuccess', 'report_evidencereporter'),
                s($row->h5psuccess === null ? '-' : get_string(
                    (int) $row->h5psuccess ? 'packpassed' : 'packnotpassed',
                    'report_evidencereporter'
                ))
            ) : '')
            . $pack->table_row(
                get_string('packduration', 'report_evidencereporter'),
                s($row->h5pduration ? format_time((int) $row->h5pduration) : '-')
            )
            . '</table>';

        // Each interaction the learner answered.
        $results = $DB->get_records('h5pactivity_attempts_results', ['attemptid' => (int) $row->attemptid], 'id');
        if ($results) {
            $record .= '<h3>' . s(get_string('packinteractions', 'report_evidencereporter')) . '</h3>'
                . '<table border="1" cellpadding="4" width="100%"><tr><th>'
                . s(get_string('packquestion', 'report_evidencereporter')) . '</th>'
                . ($showmarks ? '<th>' . s(get_string('packscore', 'report_evidencereporter')) . '</th><th>'
                    . s(get_string('packsuccess', 'report_evidencereporter')) . '</th>' : '') . '</tr>';
            foreach ($results as $r) {
                $question = trim(preg_replace('/\s+/', ' ', html_to_text((string) $r->description, 0, false)));
                $record .= '<tr><td>' . s($question !== '' ? $question : (string) $r->interactiontype) . '</td>'
                    . ($showmarks ? '<td>' . s((int) $r->rawscore . ' / ' . (int) $r->maxscore) . '</td><td>'
                        . s($r->success === null ? '-' : get_string((int) $r->success ? 'yes' : 'no')) . '</td>' : '')
                    . '</tr>';
            }
            $record .= '</table>';
        }
        $pack->add_content(
            $folder . '/' . get_string('packattemptrecord', 'report_evidencereporter') . '.pdf',
            $pack->html_to_pdf($cm->get_formatted_name(), $record)
        );
    }

    /**
     * H5P activity record, cached.
     *
     * @param int $id
     * @return stdClass|null
     */
    private static function get_activity(int $id): ?stdClass {
        global $DB;
        if (!array_key_exists($id, self::$activities)) {
            self::$activities[$id] = $DB->get_record('h5pactivity', ['id' => $id], 'id, grade') ?: null;
        }
        return self::$activities[$id];
    }

    /**
     * Reset the cache.
     */
    public static function reset_cache(): void {
        self::$activities = [];
    }
}
