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
 * SCORM package: each attempt, graded the way the activity grades attempts, with status, score and time for each
 * SCO in the evidence pack.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scorm extends base {
    /** @var array SCORM activities by ID. */
    private static array $scorms = [];

    #[\Override]
    public function get_modname(): string {
        return 'scorm';
    }

    #[\Override]
    public function get_description(): string {
        return get_string('sourcedesc_scorm', 'report_evidencereporter');
    }

    #[\Override]
    public function get_url(stdClass $row): ?moodle_url {
        $context = \context_module::instance((int) $row->cmid, IGNORE_MISSING);
        if ($context && $this->can_view_unreleased($context)) {
            return new moodle_url('/mod/scorm/report/userreport.php', ['id' => (int) $row->cmid,
                'user' => (int) $row->userid]);
        }
        return new moodle_url('/mod/scorm/view.php', ['id' => (int) $row->cmid]);
    }

    #[\Override]
    public function can_view_unreleased(\context_module $context): bool {
        return has_capability('mod/scorm:viewreport', $context);
    }

    #[\Override]
    public function has_attempts(): bool {
        return true;
    }

    #[\Override]
    public function format_attempt(stdClass $row, stdClass $result, bool $canseeunreleased, bool $viewfullnames): stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');
        $scorm = self::get_scorm((int) $row->scormid);
        $result->released = feedback_visibility::is_released((object) ['gradeitemhidden' => $row->gradeitemhidden]);
        if ($canseeunreleased) {
            // Staff see the attempt report; learners can't open SCORM reports, so they get the activity.
            $result->url = new moodle_url('/mod/scorm/report/userreport.php', ['id' => (int) $row->cmid,
                'user' => (int) $row->userid, 'attempt' => (int) $row->attemptno]);
            $result->feedbacklink = true;
            $result->feedbacklabel = get_string('viewattemptreport', 'report_evidencereporter');
        } else {
            $result->url = new moodle_url('/mod/scorm/view.php', ['id' => (int) $row->cmid]);
        }
        $value = $scorm ? scorm_grade_user_attempt($scorm, (int) $row->userid, (int) $row->attemptno) : null;
        $showmarks = $result->released || $canseeunreleased;
        return (object) ['value' => $value === null ? null : (float) $value, 'showmarks' => $showmarks,
            'showfeedback' => $showmarks, 'overallfeedback' => ''];
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
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');
        $showmarks = $attempt->released || $attempt->canseeunreleased;
        $record = $pack->attempt_header_html($cm, $attempt, $compnames);

        // Each SCO: status, score and time, as the package reported them.
        $scoes = $DB->get_records('scorm_scoes', ['scorm' => (int) $row->scormid, 'scormtype' => 'sco'], 'sortorder, id');
        if ($scoes) {
            $record .= '<h3>' . s(get_string('packattemptdetails', 'report_evidencereporter')) . '</h3>'
                . '<table border="1" cellpadding="4" width="100%"><tr><th>'
                . s(get_string('packsco', 'report_evidencereporter')) . '</th><th>' . s(get_string('status')) . '</th>'
                . ($showmarks ? '<th>' . s(get_string('packscore', 'report_evidencereporter')) . '</th>' : '')
                . '<th>' . s(get_string('packduration', 'report_evidencereporter')) . '</th><th>'
                . s(get_string('lastaccess')) . '</th></tr>';
            foreach ($scoes as $sco) {
                $track = scorm_get_tracks($sco->id, (int) $row->userid, (int) $row->attemptno);
                $status = $track && $track->status !== '' ? $track->status : '-';
                $statuskey = str_replace(' ', '', $status);
                if (get_string_manager()->string_exists($statuskey, 'scorm')) {
                    $status = get_string($statuskey, 'scorm');
                }
                $record .= '<tr><td>' . s(format_string($sco->title)) . '</td><td>' . s($status) . '</td>'
                    . ($showmarks ? '<td>' . s($track && $track->score_raw !== '' ? (string) $track->score_raw : '-') . '</td>'
                        : '')
                    . '<td>' . s($track ? (string) $track->total_time : '-') . '</td><td>'
                    . s($track && $track->timemodified
                        ? \report_evidencereporter\local\dates::datetime((int) $track->timemodified, 'strftimedaydatetime') : '-')
                    . '</td></tr>';
            }
            $record .= '</table>';
        }
        $pack->add_content(
            $folder . '/' . get_string('packattemptrecord', 'report_evidencereporter') . '.pdf',
            $pack->html_to_pdf($cm->get_formatted_name(), $record)
        );
    }

    /**
     * SCORM record, cached.
     *
     * @param int $id
     * @return stdClass|null
     */
    private static function get_scorm(int $id): ?stdClass {
        global $DB;
        if (!array_key_exists($id, self::$scorms)) {
            self::$scorms[$id] = $DB->get_record('scorm', ['id' => $id]) ?: null;
        }
        return self::$scorms[$id];
    }

    /**
     * Reset the cache.
     */
    public static function reset_cache(): void {
        self::$scorms = [];
    }
}
