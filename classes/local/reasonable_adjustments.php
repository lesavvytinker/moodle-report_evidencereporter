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
use context_module;
use stdClass;

/**
 * Reasonable adjustments made for a learner in an assessment: what was adjusted, why, who approved it and who recorded
 * it. Moodle has nowhere to keep these, beyond quiz user overrides and assignment extensions, which have no reason;
 * those are offered as suggestions to confirm.
 *
 * Reasons often touch on disability or health, so only staff with report/evidencereporter:viewadjustments see them,
 * learners see their own only when the site allows it, and evidence packs and sample downloads leave reasons out unless
 * the site includes them.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reasonable_adjustments {
    /** @var string The table. */
    public const TABLE = 'report_evidencereporter_radj';

    /** @var string[] Kinds of adjustment. */
    public const TYPES = ['extratime', 'breaks', 'reader', 'scribe', 'verbal', 'format', 'technology', 'environment',
        'support', 'other'];

    /** @var string Recorded by hand. */
    public const SOURCE_MANUAL = 'manual';
    /** @var string Confirmed from a quiz user override. */
    public const SOURCE_QUIZOVERRIDE = 'quizoverride';
    /** @var string Confirmed from an assignment user override. */
    public const SOURCE_ASSIGNOVERRIDE = 'assignoverride';
    /** @var string Confirmed from an assignment extension. */
    public const SOURCE_ASSIGNEXTENSION = 'assignextension';

    /** @var array Per request: cmid => enabled. */
    private static array $enabledcms = [];

    /**
     * The profiles that record reasonable adjustments (site setting; by default Australian VET and UK apprenticeships).
     *
     * @return string[]
     */
    public static function get_enabled_profiles(): array {
        $value = get_config('report_evidencereporter', 'radjprofiles');
        if ($value === false || $value === null) {
            return [terms::AU_VET, terms::UK_APPRENTICESHIP];
        }
        return array_values(array_filter(explode(',', (string) $value)));
    }

    /**
     * Are reasonable adjustments recorded for these frameworks? Yes if any of them uses a profile that records them (a
     * framework without its own profile uses the site's).
     *
     * @param int[] $frameworkids None: the site's profile
     * @return bool
     */
    public static function enabled_for_frameworks(array $frameworkids): bool {
        $enabled = self::get_enabled_profiles();
        if (!$enabled) {
            return false;
        }
        $map = terms::get_framework_profiles();
        $site = terms::get_site_profile();
        if (!$frameworkids) {
            return in_array($site, $enabled);
        }
        foreach ($frameworkids as $id) {
            if (in_array($map[(int) $id] ?? $site, $enabled)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Are reasonable adjustments recorded in this activity? Decided by the frameworks of the competencies it's linked to
     * (or the site's profile when it isn't linked to any).
     *
     * @param int $cmid
     * @return bool
     */
    public static function enabled_for_activity(int $cmid): bool {
        global $DB;
        if (!PHPUNIT_TEST && isset(self::$enabledcms[$cmid])) {
            return self::$enabledcms[$cmid];
        }
        $frameworkids = $DB->get_fieldset_sql("SELECT DISTINCT c.competencyframeworkid
                                                 FROM {competency_modulecomp} mc
                                                 JOIN {competency} c ON c.id = mc.competencyid
                                                WHERE mc.cmid = :cmid", ['cmid' => $cmid]);
        return self::$enabledcms[$cmid] = self::enabled_for_frameworks(array_map('intval', $frameworkids));
    }

    /**
     * Kinds of adjustment, for a menu.
     *
     * @return array type => label
     */
    public static function get_types(): array {
        $types = [];
        foreach (self::TYPES as $type) {
            $types[$type] = get_string('ratype_' . $type, 'report_evidencereporter');
        }
        return $types;
    }

    /**
     * The label of a kind of adjustment.
     *
     * @param string $type
     * @return string
     */
    public static function type_label(string $type): string {
        return in_array($type, self::TYPES) ? get_string('ratype_' . $type, 'report_evidencereporter') : $type;
    }

    /**
     * Activity types adjustments can be recorded for: every type Evidence Reporter reads evidence from.
     *
     * @param string $modname
     * @return bool
     */
    public static function supports(string $modname): bool {
        return in_array($modname, tool_details::get_modnames());
    }

    /**
     * Can adjustments be recorded in this activity: a type Evidence Reporter reads, in a profile that records them?
     *
     * @param cm_info $cm
     * @return bool
     */
    public static function available(cm_info $cm): bool {
        return self::supports($cm->modname) && self::enabled_for_activity((int) $cm->id);
    }

    /**
     * May the current user see adjustments for this learner in this activity (reasons included)?
     *
     * @param context_module $context
     * @param int $userid The learner
     * @return bool
     */
    public static function can_view(context_module $context, int $userid): bool {
        global $USER;
        if (has_capability('report/evidencereporter:viewadjustments', $context)) {
            return true;
        }
        return (int) $USER->id === $userid && get_config('report_evidencereporter', 'radjlearner');
    }

    /**
     * May the current user record adjustments in this activity?
     *
     * @param context_module $context
     * @return bool
     */
    public static function can_manage(context_module $context): bool {
        return has_capability('report/evidencereporter:manageadjustments', $context);
    }

    /**
     * One adjustment.
     *
     * @param int $id
     * @return stdClass|null
     */
    public static function get(int $id): ?stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $id]) ?: null;
    }

    /**
     * Save an adjustment (insert when it has no id).
     *
     * @param stdClass $data cmid, userid, adjtype, details, reason, approvedby, source, sourceid; id to update
     * @return int The id
     */
    public static function save(stdClass $data): int {
        global $DB, $USER;
        $type = in_array($data->adjtype ?? '', self::TYPES) ? $data->adjtype : 'other';
        $record = (object) [
            'cmid' => (int) $data->cmid,
            'userid' => (int) $data->userid,
            'adjtype' => $type,
            'details' => trim((string) ($data->details ?? '')),
            'reason' => trim((string) ($data->reason ?? '')),
            'approvedby' => \core_text::substr(trim((string) ($data->approvedby ?? '')), 0, 255),
            'source' => in_array($data->source ?? '', [self::SOURCE_QUIZOVERRIDE, self::SOURCE_ASSIGNOVERRIDE,
                self::SOURCE_ASSIGNEXTENSION]) ? $data->source : self::SOURCE_MANUAL,
            'sourceid' => (int) ($data->sourceid ?? 0),
            'usermodified' => (int) $USER->id,
            'timemodified' => time(),
        ];
        if (!empty($data->id) && ($existing = self::get((int) $data->id))) {
            $record->id = (int) $existing->id;
            $record->cmid = (int) $existing->cmid;
            $record->userid = (int) $existing->userid;
            $record->source = $existing->source;
            $record->sourceid = (int) $existing->sourceid;
            $DB->update_record(self::TABLE, $record);
        } else {
            $record->timecreated = $record->timemodified;
            $record->id = $DB->insert_record(self::TABLE, $record);
        }
        \report_evidencereporter\event\adjustment_saved::create([
            'objectid' => $record->id,
            'context' => context_module::instance($record->cmid),
            'relateduserid' => $record->userid,
            'other' => ['adjtype' => $record->adjtype],
        ])->trigger();
        return (int) $record->id;
    }

    /**
     * Delete an adjustment.
     *
     * @param int $id
     */
    public static function delete(int $id): void {
        global $DB;
        $record = self::get($id);
        if (!$record) {
            return;
        }
        $DB->delete_records(self::TABLE, ['id' => $id]);
        $context = context_module::instance((int) $record->cmid, IGNORE_MISSING);
        if ($context) {
            \report_evidencereporter\event\adjustment_deleted::create([
                'objectid' => $id,
                'context' => $context,
                'relateduserid' => (int) $record->userid,
                'other' => ['adjtype' => $record->adjtype],
            ])->trigger();
        }
    }

    /**
     * Remove an activity's adjustments (when it's deleted).
     *
     * @param int $cmid
     */
    public static function delete_for_activity(int $cmid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['cmid' => $cmid]);
    }

    /**
     * An activity's adjustments, by learner.
     *
     * @param int $cmid
     * @return array userid => stdClass[] (oldest first)
     */
    public static function get_for_activity(int $cmid): array {
        global $DB;
        $result = [];
        foreach ($DB->get_records(self::TABLE, ['cmid' => $cmid], 'timecreated, id') as $record) {
            $result[(int) $record->userid][] = $record;
        }
        return $result;
    }

    /**
     * Moodle's own per-learner adjustments in an activity not yet recorded here: quiz user overrides, assignment user
     * overrides and assignment extensions. Each is offered to confirm, with a reason.
     *
     * @param cm_info $cm
     * @return array userid => stdClass[] (source, sourceid, adjtype, details)
     */
    public static function get_suggestions(cm_info $cm): array {
        global $DB;
        $confirmed = [];
        foreach ($DB->get_records(self::TABLE, ['cmid' => $cm->id], '', 'id, source, sourceid') as $record) {
            $confirmed[$record->source . ':' . $record->sourceid] = true;
        }
        $date = fn($time) => userdate((int) $time, get_string('strftimedatetimeshort', 'core_langconfig'));
        $result = [];
        $add = function (int $userid, string $source, int $sourceid, string $type, array $parts) use (&$result, $confirmed) {
            if (isset($confirmed[$source . ':' . $sourceid]) || !$parts) {
                return;
            }
            $result[$userid][] = (object) ['source' => $source, 'sourceid' => $sourceid, 'adjtype' => $type,
                'details' => implode('; ', $parts)];
        };
        $str = fn($id, $a = null) => get_string($id, 'report_evidencereporter', $a);
        if ($cm->modname === 'quiz') {
            foreach (
                $DB->get_records_select(
                    'quiz_overrides',
                    'quiz = :quiz AND userid IS NOT NULL',
                    ['quiz' => $cm->instance]
                ) as $override
            ) {
                $parts = [];
                if ($override->timelimit !== null) {
                    $parts[] = $str('rasuggest_timelimit', $override->timelimit ? format_time((int) $override->timelimit)
                        : get_string('none'));
                }
                if ($override->attempts !== null) {
                    $parts[] = $str('rasuggest_attempts', $override->attempts ?: get_string('unlimited'));
                }
                if (!empty($override->timeclose)) {
                    $parts[] = $str('rasuggest_closes', $date($override->timeclose));
                }
                if (!empty($override->timeopen)) {
                    $parts[] = $str('rasuggest_opens', $date($override->timeopen));
                }
                $type = $override->timelimit !== null || !empty($override->timeclose) ? 'extratime' : 'other';
                $add((int) $override->userid, self::SOURCE_QUIZOVERRIDE, (int) $override->id, $type, $parts);
            }
        } else if ($cm->modname === 'assign') {
            foreach (
                $DB->get_records_select(
                    'assign_overrides',
                    'assignid = :assign AND userid IS NOT NULL',
                    ['assign' => $cm->instance]
                ) as $override
            ) {
                $parts = [];
                if (!empty($override->duedate)) {
                    $parts[] = $str('rasuggest_due', $date($override->duedate));
                }
                if (!empty($override->cutoffdate)) {
                    $parts[] = $str('rasuggest_cutoff', $date($override->cutoffdate));
                }
                if (!empty($override->timelimit)) {
                    $parts[] = $str('rasuggest_timelimit', format_time((int) $override->timelimit));
                }
                $add((int) $override->userid, self::SOURCE_ASSIGNOVERRIDE, (int) $override->id, 'extratime', $parts);
            }
            foreach (
                $DB->get_records_select(
                    'assign_user_flags',
                    'assignment = :assign AND extensionduedate > 0',
                    ['assign' => $cm->instance]
                ) as $flags
            ) {
                $add(
                    (int) $flags->userid,
                    self::SOURCE_ASSIGNEXTENSION,
                    (int) $flags->id,
                    'extratime',
                    [$str('rasuggest_extension', $date($flags->extensionduedate))]
                );
            }
        }
        return $result;
    }

    /**
     * The learner's adjustments in activities linked to the given competencies, that the current user may see.
     *
     * @param int $userid
     * @param int[] $competencyids
     * @param identity|null $identity Redaction for staff names
     * @param bool $fordownload For an evidence pack or sample download: reasons only if the site includes them
     * @return stdClass[] cmid, activity, type, details, reason (null when left out), approvedby, recordedby, recorded,
     *                    source (label, '' when recorded by hand)
     */
    public static function get_for_user(
        int $userid,
        array $competencyids,
        ?identity $identity = null,
        bool $fordownload = false
    ): array {
        global $DB;
        if (!$competencyids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $competencyids), SQL_PARAMS_NAMED, 'rac');
        $params['userid'] = $userid;
        $records = $DB->get_records_sql("SELECT ra.*
                                           FROM {" . self::TABLE . "} ra
                                          WHERE ra.userid = :userid
                                            AND EXISTS (SELECT 1 FROM {competency_modulecomp} mc
                                                         WHERE mc.cmid = ra.cmid AND mc.competencyid {$insql})
                                       ORDER BY ra.timecreated, ra.id", $params);
        return self::format($records, $identity, $fordownload);
    }

    /**
     * Every assessment linked to the given competencies, for the learner, with its reasonable adjustments (none: "N/A").
     * Assessments are the learner's courses' activities linked to the competencies, plus any activity with an adjustment
     * for them; only those where the current user may see adjustments are listed, so "N/A" is never shown to someone
     * who couldn't see one.
     *
     * @param int $userid
     * @param int[] $competencyids
     * @param identity|null $identity Redaction for staff names
     * @param bool $fordownload See {@see format()}
     * @return stdClass[] cmid, activity, courseid, competencyids (int[]), items (see {@see get_for_user()}; with the
     *                    assessor's recorded RA when there's no detailed adjustment), text (one line: "N/A" or the
     *                    adjustments), lln (one line: "N/A", the details or "Not recorded"), llnrecorded, llnyes
     */
    public static function get_by_assessment(
        int $userid,
        array $competencyids,
        ?identity $identity = null,
        bool $fordownload = false
    ): array {
        global $DB;
        if (!$competencyids) {
            return [];
        }
        [$cinsql, $params] = $DB->get_in_or_equal(array_map('intval', $competencyids), SQL_PARAMS_NAMED, 'rbc');
        $params['userid'] = $userid;
        $courseids = array_map('intval', array_keys(enrol_get_all_users_courses($userid, false, 'id')));
        $coursesql = '1 = 0';
        if ($courseids) {
            [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'rbo');
            $coursesql = 'cm.course ' . $coursesql;
            $params += $courseparams;
        }
        $links = $DB->get_recordset_sql("SELECT mc.id, mc.cmid, mc.competencyid
                                           FROM {competency_modulecomp} mc
                                           JOIN {course_modules} cm ON cm.id = mc.cmid
                                          WHERE mc.competencyid {$cinsql} AND cm.deletioninprogress = 0
                                            AND ({$coursesql}
                                                 OR EXISTS (SELECT 1 FROM {" . self::TABLE . "} ra
                                                             WHERE ra.cmid = mc.cmid AND ra.userid = :userid))", $params);
        $bycm = [];
        foreach ($links as $link) {
            $bycm[(int) $link->cmid][] = (int) $link->competencyid;
        }
        $links->close();
        if (!$bycm) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($bycm), SQL_PARAMS_NAMED, 'rbm');
        $records = [];
        foreach (
            $DB->get_records_select(
                self::TABLE,
                "userid = :userid AND cmid {$insql}",
                $inparams + ['userid' => $userid],
                'timecreated, id'
            ) as $record
        ) {
            $records[(int) $record->cmid][] = $record;
        }
        $result = [];
        foreach ($bycm as $cmid => $compids) {
            try {
                [$course, $cm] = get_course_and_cm_from_cmid($cmid);
            } catch (\Throwable $e) {
                continue;
            }
            if (!self::available($cm) || (!$cm->visible && empty($records[$cmid]))) {
                continue;
            }
            if (!self::can_view(context_module::instance($cm->id), $userid)) {
                continue;
            }
            $items = self::format($records[$cmid] ?? [], $identity, $fordownload);
            $declared = declarations::get_effective($cm)[$userid] ?? null;
            if (!$items && $declared && $declared->ra && trim((string) $declared->radetails) !== '') {
                $items = [declarations::ra_item($declared, $cm, $identity)];
            }
            $lln = declarations::lln_line($declared);
            $result[] = (object) [
                'cmid' => (int) $cm->id,
                'activity' => $cm->get_formatted_name(),
                'courseid' => (int) $course->id,
                'coursename' => format_string($course->shortname, true, ['context' => \context_course::instance($course->id)]),
                'sortkey' => sprintf('%010d-%05d-%010d', $course->id, $cm->sectionnum, array_search(
                    $cm->id,
                    array_keys(get_fast_modinfo($course)->get_cms())
                )),
                'competencyids' => array_values(array_unique($compids)),
                'items' => $items,
                'text' => $items ? self::to_text($items, false, '; ') : get_string('radjna', 'report_evidencereporter'),
                'lln' => $lln->text,
                'llnrecorded' => $lln->recorded,
                'llnyes' => $lln->yes,
            ];
        }
        usort($result, fn($a, $b) => strcmp($a->sortkey, $b->sortkey));
        return $result;
    }

    /**
     * Format adjustments for display, keeping those the current user may see.
     *
     * @param stdClass[] $records
     * @param identity|null $identity
     * @param bool $fordownload
     * @return stdClass[] See {@see get_for_user()}
     */
    public static function format(array $records, ?identity $identity = null, bool $fordownload = false): array {
        $withreasons = !$fordownload || get_config('report_evidencereporter', 'radjreasons');
        $result = [];
        foreach ($records as $record) {
            try {
                [$course, $cm] = get_course_and_cm_from_cmid((int) $record->cmid);
            } catch (\Throwable $e) {
                continue;
            }
            if (
                !self::enabled_for_activity((int) $cm->id)
                    || !self::can_view(context_module::instance($cm->id), (int) $record->userid)
            ) {
                continue;
            }
            $recorder = $record->usermodified ? \core_user::get_user($record->usermodified) : null;
            $recordername = $recorder ? fullname($recorder) : '';
            $result[] = (object) [
                'id' => (int) $record->id,
                'cmid' => (int) $cm->id,
                'activity' => $cm->get_formatted_name(),
                'type' => self::type_label($record->adjtype),
                'details' => (string) $record->details,
                'reason' => $withreasons ? (string) $record->reason : null,
                'hasreason' => trim((string) $record->reason) !== '',
                'approvedby' => $identity ? $identity->staff((string) $record->approvedby) : (string) $record->approvedby,
                'recordedby' => $identity ? $identity->staff($recordername) : $recordername,
                'recorded' => userdate((int) $record->timemodified, get_string('strftimedate', 'core_langconfig')),
                'source' => $record->source === self::SOURCE_MANUAL ? ''
                    : get_string('rasource_' . $record->source, 'report_evidencereporter'),
            ];
        }
        return $result;
    }

    /**
     * One line per adjustment, for a spreadsheet cell or plain text.
     *
     * @param stdClass[] $formatted From {@see format()}
     * @param bool $withactivity Start each line with the activity
     * @param string $separator Between adjustments
     * @return string
     */
    public static function to_text(array $formatted, bool $withactivity = true, string $separator = "\n"): string {
        $lines = [];
        foreach ($formatted as $item) {
            $line = ($withactivity ? $item->activity . ': ' : '') . $item->type;
            if ($item->details !== '') {
                $line .= ' (' . $item->details . ')';
            }
            if ($item->reason !== null && $item->reason !== '') {
                $line .= '. ' . get_string('rareason', 'report_evidencereporter') . ': ' . $item->reason;
            } else if ($item->reason === null && $item->hasreason) {
                $line .= '. ' . get_string('rareasonleftout', 'report_evidencereporter');
            }
            if ($item->approvedby !== '') {
                $line .= '. ' . get_string('raapprovedby', 'report_evidencereporter') . ': ' . $item->approvedby;
            }
            $lines[] = $line;
        }
        return implode($separator, $lines);
    }

    /**
     * Adjustments for one sampled judgement: the learner's, in that course, in activities linked to the unit or
     * anything under it.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $unitid
     * @param identity|null $identity
     * @return string One line per adjustment, or "N/A"
     */
    public static function for_judgement(int $userid, int $courseid, int $unitid, ?identity $identity = null): string {
        global $DB;
        $unit = $DB->get_record('competency', ['id' => $unitid], 'id, path, competencyframeworkid');
        if (!$unit || !self::enabled_for_frameworks([(int) $unit->competencyframeworkid])) {
            return '';
        }
        $records = $DB->get_records_sql("SELECT ra.*
                                           FROM {" . self::TABLE . "} ra
                                           JOIN {course_modules} cm ON cm.id = ra.cmid AND cm.course = :courseid
                                          WHERE ra.userid = :userid
                                            AND EXISTS (SELECT 1 FROM {competency_modulecomp} mc
                                                          JOIN {competency} c ON c.id = mc.competencyid
                                                         WHERE mc.cmid = ra.cmid
                                                           AND (c.id = :unitid OR " . $DB->sql_like('c.path', ':path') . "))
                                       ORDER BY ra.timecreated, ra.id", ['courseid' => $courseid, 'userid' => $userid,
            'unitid' => $unitid, 'path' => '%/' . $unitid . '/%']);
        $items = self::format($records, $identity, true);
        // What assessors recorded, for activities with no detailed adjustment.
        $withitems = array_map(fn($item) => (int) $item->cmid, $items);
        foreach (declarations::for_judgement($userid, $courseid, $unitid, $identity)->ra as $cmid => $item) {
            if (!in_array($cmid, $withitems)) {
                $items[] = $item;
            }
        }
        $text = self::to_text($items);
        return $text !== '' ? $text : get_string('radjna', 'report_evidencereporter');
    }

    /**
     * Activities linked to the given competencies, in the learner's courses, where the current user may record
     * adjustments (for the "Record an adjustment" menu on the summary).
     *
     * @param int $userid
     * @param int[] $competencyids
     * @return array cmid => activity name
     */
    public static function get_manageable_activities(int $userid, array $competencyids): array {
        global $DB;
        if (!$competencyids) {
            return [];
        }
        $courseids = array_keys(enrol_get_all_users_courses($userid, true, 'id'));
        if (!$courseids) {
            return [];
        }
        [$cinsql, $params] = $DB->get_in_or_equal(array_map('intval', $competencyids), SQL_PARAMS_NAMED, 'rmc');
        [$courseinsql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'rmo');
        $cmids = $DB->get_fieldset_sql("SELECT DISTINCT mc.cmid
                                          FROM {competency_modulecomp} mc
                                          JOIN {course_modules} cm ON cm.id = mc.cmid
                                         WHERE mc.competencyid {$cinsql} AND cm.course {$courseinsql}
                                           AND cm.deletioninprogress = 0", $params + $courseparams);
        $result = [];
        foreach ($cmids as $cmid) {
            try {
                [, $cm] = get_course_and_cm_from_cmid((int) $cmid);
            } catch (\Throwable $e) {
                continue;
            }
            if (self::available($cm) && self::can_manage(context_module::instance($cm->id))) {
                $result[(int) $cm->id] = $cm->get_formatted_name();
            }
        }
        \core_collator::asort($result);
        return $result;
    }
}
