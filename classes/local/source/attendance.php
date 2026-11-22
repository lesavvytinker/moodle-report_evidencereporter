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
use report_evidencereporter\local\summary;
use report_evidencereporter\local\terms;
use stdClass;

/**
 * Attendance (mod_attendance, when installed): every session recorded for the learner, with its date, length,
 * status, points, remarks and who took it, and a summary of attendance across sessions.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attendance extends base {
    /** @var array Sessions cached by cmid and userid. */
    private static array $cache = [];

    #[\Override]
    public function get_modname(): string {
        return 'attendance';
    }

    #[\Override]
    public function get_description(): string {
        return get_string('sourcedesc_attendance', 'report_evidencereporter');
    }

    #[\Override]
    public function get_url(stdClass $row): ?moodle_url {
        return new moodle_url('/mod/attendance/view.php', ['id' => (int) $row->cmid, 'studentid' => (int) $row->userid]);
    }

    #[\Override]
    public function can_view_unreleased(\context_module $context): bool {
        return has_capability('mod/attendance:viewreports', $context);
    }

    #[\Override]
    public function get_activity_done_sql(string $instance, int $userid): ?string {
        return "EXISTS (SELECT 1 FROM {attendance_log} lal
                          JOIN {attendance_sessions} las ON las.id = lal.sessionid
                         WHERE las.attendanceid = {$instance} AND lal.studentid = {$userid})";
    }

    #[\Override]
    public function get_records_heading(): string {
        return get_string('attendancerecord', 'report_evidencereporter');
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
                'count' => self::summary_text($data),
                'html' => self::html($data, $identity, $viewfullnames),
            ];
        }
        return $result;
    }

    #[\Override]
    public function add_records_to_pack(evidence_pack $pack, array $competencyids): void {
        $compsbycm = [];
        foreach (summary::get_linked_activities($pack->get_userid(), null, $competencyids) as $link) {
            if ($link->modname === 'attendance') {
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
                    s(get_string('modulename', 'mod_attendance'))
                )
                . $pack->table_row('URL', s((new moodle_url('/mod/attendance/view.php', ['id' => $cmid]))->out(false)))
                . '</table>'
                . self::html($data, $pack->get_identity(), $pack->can_view_fullnames());
            $pack->add_content(
                $folder . '/' . get_string('attendancerecord', 'report_evidencereporter') . '.pdf',
                $pack->html_to_pdf($name, $html)
            );
        }
    }

    /**
     * The learner's recorded sessions, or null if there are none.
     *
     * @param int $cmid
     * @param int $userid
     * @return stdClass|null (cm, course, sessions)
     */
    private static function load(int $cmid, int $userid): ?stdClass {
        global $DB;
        $key = $cmid . '-' . $userid;
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        self::$cache[$key] = null;
        try {
            [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'attendance');
        } catch (\Throwable $e) {
            return null;
        }
        $sessions = $DB->get_records_sql(
            "SELECT l.id, l.timetaken, l.takenby, l.remarks, s.sessdate, s.duration, s.description, s.descriptionformat,
                    st.acronym, st.description AS status, st.grade AS points,
                    (SELECT MAX(st2.grade) FROM {attendance_statuses} st2
                      WHERE st2.attendanceid = s.attendanceid AND st2.setnumber = s.statusset AND st2.deleted = 0) AS maxpoints
               FROM {attendance_log} l
               JOIN {attendance_sessions} s ON s.id = l.sessionid
               JOIN {attendance_statuses} st ON st.id = l.statusid
              WHERE s.attendanceid = :attendanceid AND l.studentid = :userid
           ORDER BY s.sessdate, l.id",
            ['attendanceid' => $cm->instance, 'userid' => $userid]
        );
        if (!$sessions) {
            return null;
        }
        $takers = [];
        foreach ($sessions as $session) {
            if ($session->takenby && !isset($takers[$session->takenby])) {
                $takers[$session->takenby] = \core_user::get_user($session->takenby);
            }
        }
        return self::$cache[$key] = (object) ['cm' => $cm, 'course' => $course, 'sessions' => array_values($sessions),
            'takers' => $takers, 'context' => \context_module::instance($cm->id)];
    }

    /**
     * Short summary, e.g. "6 sessions, 83% (Present 5, Absent 1)".
     *
     * @param stdClass $data
     * @return string
     */
    private static function summary_text(stdClass $data): string {
        $points = $max = 0.0;
        $counts = [];
        foreach ($data->sessions as $session) {
            $points += (float) $session->points;
            $max += (float) $session->maxpoints;
            $counts[$session->status] = ($counts[$session->status] ?? 0) + 1;
        }
        $parts = [];
        foreach ($counts as $status => $count) {
            $parts[] = format_string($status) . ' ' . $count;
        }
        return get_string('attendancesummary', 'report_evidencereporter', [
            'sessions' => count($data->sessions),
            'percent' => $max > 0 ? format_float($points / $max * 100, 0) . '%' : '-',
            'statuses' => implode(', ', $parts),
        ]);
    }

    /**
     * The sessions as a table.
     *
     * @param stdClass $data
     * @param identity|null $identity
     * @param bool $viewfullnames
     * @return string
     */
    private static function html(stdClass $data, ?identity $identity, bool $viewfullnames): string {
        $datefmt = get_string('strftimedatetimeshort', 'core_langconfig');
        $html = '<p class="mb-1">' . s(self::summary_text($data)) . '</p>'
            . '<table class="table table-sm table-bordered" border="1" cellpadding="3" width="100%"><thead><tr>';
        foreach (['date', 'duration', 'description', 'status', 'packpoints', 'remarks', 'takenby'] as $head) {
            $html .= '<th>' . s(get_string('attendance' . $head, 'report_evidencereporter')) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($data->sessions as $session) {
            $description = trim(preg_replace('/\s+/', ' ', html_to_text(format_text(
                $session->description,
                $session->descriptionformat,
                ['context' => $data->context]
            ), 0, false)));
            $taker = isset($data->takers[$session->takenby]) ? fullname($data->takers[$session->takenby], $viewfullnames) : '';
            $remarks = (string) $session->remarks;
            $html .= '<tr><td>' . s(userdate($session->sessdate, $datefmt)) . '</td>'
                . '<td>' . s($session->duration ? format_time((int) $session->duration) : '-') . '</td>'
                . '<td>' . s(shorten_text($description, 80)) . '</td>'
                . '<td>' . s(format_string($session->status)) . '</td>'
                . '<td>' . s(format_float((float) $session->points, -1) . ' / ' . format_float((float) $session->maxpoints, -1))
                . '</td><td>' . s($identity ? $identity->text($remarks) : $remarks) . '</td>'
                . '<td>' . s($taker !== '' && $identity ? $identity->staff($taker) : $taker) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    /**
     * Reset the cache.
     */
    public static function reset_cache(): void {
        self::$cache = [];
    }
}
