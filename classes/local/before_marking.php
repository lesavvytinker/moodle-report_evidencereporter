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

namespace report_evidencereporter\local;

use cm_info;
use context_course;
use context_module;
use moodle_url;
use stdClass;

/**
 * What should be done before marking a learner's assessment: the assessment tool's details filled in (the fields the
 * site requires), then the learner's RA and LLN recorded, then marking.
 *
 * Markers who choose "Check before marking" on the marking page go through a short checklist when something's missing.
 * Marking is never blocked: starting before the checks are done is logged. An assessor who can't edit the activity can
 * let the course's editing teachers know the tool details are missing.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class before_marking {
    /** @var string Notifications sent about missing tool details. */
    public const TABLE = 'report_evidencereporter_ntf';

    /** @var string The user preference for the guided view. */
    public const PREFERENCE = 'report_evidencereporter_checkbeforemarking';

    /**
     * Has the current user chosen "Check before marking"?
     *
     * @return bool
     */
    public static function is_on(): bool {
        return (bool) get_user_preferences(self::PREFERENCE, 0);
    }

    /**
     * Turn "Check before marking" on or off for the current user.
     *
     * @param bool $on
     */
    public static function set(bool $on): void {
        set_user_preference(self::PREFERENCE, $on ? 1 : 0);
    }

    /**
     * The assessment tool's state for an activity.
     *
     * @param cm_info $cm
     * @return stdClass missing (field labels), complete (bool), canedit (bool: the current user can fill them in),
     *                  editurl, notified (text: who let the editing teachers know, and when; '' if no one has since the
     *                  details last changed)
     */
    public static function tool_status(cm_info $cm): stdClass {
        global $DB;
        $missing = array_map(fn($f) => tool_details::field_label($f), tool_details::get_missing_fields((int) $cm->id));
        $notified = '';
        if ($missing) {
            $since = (int) tool_details::get((int) $cm->id)->timemodified;
            $records = $DB->get_records_select(
                self::TABLE,
                'cmid = :cmid AND timecreated >= :since',
                ['cmid' => $cm->id, 'since' => $since],
                'timecreated DESC',
                '*',
                0,
                1
            );
            if ($record = reset($records)) {
                $sender = \core_user::get_user($record->userid);
                $notified = get_string('beforetoolnotified', 'report_evidencereporter', (object) [
                    'by' => $sender ? fullname($sender) : '',
                    'date' => dates::datetime((int) $record->timecreated),
                ]);
            }
        }
        return (object) [
            'missing' => $missing,
            'complete' => !$missing,
            'canedit' => has_capability('moodle/course:manageactivities', context_module::instance($cm->id)),
            'editurl' => (new moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 1]))->out(false),
            'notified' => $notified,
        ];
    }

    /**
     * Is everything done before marking this learner? Tool details complete, and RA and LLN recorded (where the
     * activity records them).
     *
     * @param cm_info $cm
     * @param int $userid
     * @param array|null $declared From {@see declarations::get_effective()}, if already loaded
     * @return stdClass tool (see {@see tool_status()}), declapplies, declcomplete, ready (bool), missing (what's not
     *                  done, as text)
     */
    public static function status(cm_info $cm, int $userid, ?array $declared = null): stdClass {
        $tool = self::tool_status($cm);
        $declapplies = declarations::available($cm);
        $declared ??= $declapplies ? declarations::get_effective($cm) : [];
        $declcomplete = !$declapplies || declarations::is_complete($declared[$userid] ?? null);
        $missing = [];
        if (!$tool->complete) {
            $missing[] = get_string('beforemissingtool', 'report_evidencereporter');
        }
        if (!$declcomplete) {
            $missing[] = get_string('beforemissingdecl', 'report_evidencereporter');
        }
        return (object) ['tool' => $tool, 'declapplies' => $declapplies, 'declcomplete' => $declcomplete,
            'ready' => !$missing, 'missing' => implode(', ', $missing)];
    }

    /**
     * The people who can fill in an activity's tool details: active, enrolled, and able to edit the activity.
     *
     * @param cm_info $cm
     * @return stdClass[] users
     */
    public static function get_editors(cm_info $cm): array {
        global $USER;
        $context = context_module::instance($cm->id);
        $users = get_enrolled_users(
            context_course::instance($cm->course),
            'moodle/course:manageactivities',
            0,
            'u.*',
            null,
            0,
            0,
            true
        );
        return array_filter(
            $users,
            fn($user) => (int) $user->id !== (int) $USER->id && has_capability('moodle/course:manageactivities', $context, $user)
        );
    }

    /**
     * Let the editing teachers know the activity's tool details are missing.
     *
     * @param cm_info $cm
     * @return int How many were told (0: no one can edit the activity)
     */
    public static function notify_tool(cm_info $cm): int {
        global $DB, $USER;
        $editors = self::get_editors($cm);
        if (!$editors) {
            return 0;
        }
        $course = get_course($cm->course);
        $a = (object) [
            'sender' => fullname($USER),
            'activity' => $cm->get_formatted_name(),
            'course' => format_string($course->fullname, true, ['context' => context_course::instance($course->id)]),
            'fields' => implode(', ', array_map(
                fn($f) => tool_details::field_label($f),
                tool_details::get_missing_fields((int) $cm->id)
            )),
            'url' => (new moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 1]))->out(false),
        ];
        foreach ($editors as $editor) {
            $message = new \core\message\message();
            $message->component = 'report_evidencereporter';
            $message->name = 'toolgap';
            $message->userfrom = $USER;
            $message->userto = $editor;
            $message->subject = get_string('toolgapsubject', 'report_evidencereporter', $a);
            $message->fullmessage = get_string('toolgapbody', 'report_evidencereporter', $a);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = get_string('toolgapsubject', 'report_evidencereporter', $a);
            $message->notification = 1;
            $message->contexturl = $a->url;
            $message->contexturlname = $a->activity;
            $message->courseid = $course->id;
            message_send($message);
        }
        $id = $DB->insert_record(self::TABLE, ['cmid' => (int) $cm->id, 'kind' => 'tool', 'userid' => (int) $USER->id,
            'recipients' => count($editors), 'timecreated' => time()]);
        \report_evidencereporter\event\tool_gap_reported::create([
            'objectid' => $id,
            'context' => context_module::instance($cm->id),
            'other' => ['recipients' => count($editors)],
        ])->trigger();
        return count($editors);
    }

    /**
     * Log that marking started before the checks were done.
     *
     * @param cm_info $cm
     * @param int $userid The learner
     * @param string $missing What wasn't done
     */
    public static function log_unchecked(cm_info $cm, int $userid, string $missing): void {
        \report_evidencereporter\event\marking_started_unchecked::create([
            'context' => context_module::instance($cm->id),
            'relateduserid' => $userid,
            'other' => ['missing' => $missing],
        ])->trigger();
    }

    /**
     * Remove an activity's notifications (when it's deleted).
     *
     * @param int $cmid
     */
    public static function delete_for_activity(int $cmid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['cmid' => $cmid]);
    }
}
