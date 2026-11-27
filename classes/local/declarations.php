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
use stdClass;

/**
 * The assessor's reasonable adjustment (RA) and language, literacy and numeracy (LLN) answers for each learner in an
 * assessment: yes (with details) or no. A learner is "recorded" once both are answered. Assessments that count toward
 * the final grade are held from release until every learner is recorded (see {@see release_hold}).
 *
 * Detailed adjustments (type, reason, approver) are still recorded on the Reasonable adjustments page; a learner with
 * one counts as RA "yes" until the assessor answers.
 *
 * Attempts of one assessment share a record: activities in the same gradebook category, when it has no subcategories,
 * holds two or more activities, and either takes the highest grade or has every activity linked to the same
 * competencies. A learner recorded in one is recorded in all of them (the record carries over); a record is kept for a
 * later attempt only when its answers differ.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class declarations {
    /** @var string The table. */
    public const TABLE = 'report_evidencereporter_decl';

    /** @var array Per request: cmid => int[] the activities sharing its record. */
    private static array $groups = [];

    /**
     * Can RA and LLN be recorded in this activity? Wherever reasonable adjustments can.
     *
     * @param cm_info $cm
     * @return bool
     */
    public static function available(cm_info $cm): bool {
        return reasonable_adjustments::available($cm);
    }

    /**
     * The learners who need RA and LLN recorded in an activity: enrolled, active, gradable in the course and able to
     * access the activity.
     *
     * @param cm_info $cm
     * @param int $groupid Only this group (0: everyone)
     * @return stdClass[] userid => user (names)
     */
    public static function get_learners(cm_info $cm, int $groupid = 0): array {
        $fields = 'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename';
        $users = get_enrolled_users(
            context_course::instance($cm->course),
            'moodle/competency:coursecompetencygradable',
            $groupid,
            $fields,
            'u.lastname, u.firstname',
            0,
            0,
            true
        );
        if ($users && !empty($cm->availability)) {
            $info = new \core_availability\info_module($cm);
            $users = $info->filter_user_list($users);
        }
        return $users;
    }

    /**
     * An activity's answers, by learner.
     *
     * @param int $cmid
     * @return stdClass[] userid => record
     */
    public static function get_for_activity(int $cmid): array {
        global $DB;
        $result = [];
        foreach ($DB->get_records(self::TABLE, ['cmid' => $cmid]) as $record) {
            $result[(int) $record->userid] = $record;
        }
        return $result;
    }

    /**
     * The activities that share this one's record (itself included, first): attempts of one assessment, in one gradebook
     * category (see the class description). Otherwise just this activity.
     *
     * @param cm_info $cm
     * @return int[] cmids, in course order
     */
    public static function get_group(cm_info $cm): array {
        global $CFG, $DB;
        $cmid = (int) $cm->id;
        if (!PHPUNIT_TEST && isset(self::$groups[$cmid])) {
            return self::$groups[$cmid];
        }
        require_once($CFG->libdir . '/gradelib.php');
        $group = [$cmid];
        $items = release_hold::get_grade_items($cm);
        usort($items, fn($a, $b) => (int) $a->itemnumber <=> (int) $b->itemnumber);
        $item = $items[0] ?? null;
        $category = $item && $item->categoryid ? \grade_category::fetch(['id' => $item->categoryid]) : null;
        if ($category && !empty($category->parent) && !\grade_category::fetch_all(['parent' => $category->id])) {
            $modinfo = get_fast_modinfo($cm->course, -1);
            $members = [];
            foreach (\grade_item::fetch_all(['categoryid' => $category->id, 'itemtype' => 'mod']) ?: [] as $other) {
                foreach ($modinfo->get_instances_of((string) $other->itemmodule) as $othercm) {
                    if (
                        (int) $othercm->instance === (int) $other->iteminstance && !$othercm->deletioninprogress
                            && reasonable_adjustments::supports($othercm->modname)
                    ) {
                        $members[(int) $othercm->id] = $othercm;
                    }
                }
            }
            if (count($members) >= 2 && isset($members[$cmid])) {
                $shared = (int) $category->aggregation === GRADE_AGGREGATE_MAX;
                if (!$shared) {
                    [$insql, $params] = $DB->get_in_or_equal(array_keys($members), SQL_PARAMS_NAMED, 'dgm');
                    $sets = [];
                    $links = $DB->get_records_select(
                        'competency_modulecomp',
                        "cmid {$insql}",
                        $params,
                        '',
                        'id, cmid, competencyid'
                    );
                    foreach ($links as $link) {
                        $sets[(int) $link->cmid][] = (int) $link->competencyid;
                    }
                    $keys = [];
                    foreach (array_keys($members) as $memberid) {
                        $set = array_unique($sets[$memberid] ?? []);
                        sort($set);
                        $keys[] = implode(',', $set);
                    }
                    $shared = count(array_unique($keys)) === 1 && $keys[0] !== '';
                }
                if ($shared) {
                    $order = array_flip(array_keys($modinfo->get_cms()));
                    $ids = array_keys($members);
                    usort($ids, fn($a, $b) => ($order[$a] ?? 0) <=> ($order[$b] ?? 0));
                    $group = array_merge([$cmid], array_values(array_diff($ids, [$cmid])));
                }
            }
        }
        return self::$groups[$cmid] = $group;
    }

    /**
     * Each learner's answers that apply in an activity: its own record, or else one carried over from another attempt
     * sharing its record (the most recently saved).
     *
     * @param cm_info $cm
     * @return stdClass[] userid => record, with carriedfrom (cmid, 0 for its own) and carriedname
     */
    public static function get_effective(cm_info $cm): array {
        global $DB;
        $group = self::get_group($cm);
        $result = [];
        foreach (self::get_for_activity((int) $cm->id) as $userid => $record) {
            $record->carriedfrom = 0;
            $record->carriedname = '';
            $result[$userid] = $record;
        }
        if (count($group) > 1) {
            $others = array_slice($group, 1);
            [$insql, $params] = $DB->get_in_or_equal($others, SQL_PARAMS_NAMED, 'dge');
            $modinfo = get_fast_modinfo($cm->course, -1);
            foreach ($DB->get_records_select(self::TABLE, "cmid {$insql}", $params, 'timemodified DESC, id DESC') as $record) {
                $userid = (int) $record->userid;
                if (isset($result[$userid])) {
                    continue;
                }
                $record->carriedfrom = (int) $record->cmid;
                $record->carriedname = $modinfo->get_cm((int) $record->cmid)->get_formatted_name();
                $result[$userid] = $record;
            }
        }
        return $result;
    }

    /**
     * One learner's answers in an activity.
     *
     * @param int $cmid
     * @param int $userid
     * @return stdClass|null
     */
    public static function get(int $cmid, int $userid): ?stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['cmid' => $cmid, 'userid' => $userid]) ?: null;
    }

    /**
     * Learners still to record in an activity.
     *
     * @param cm_info $cm
     * @return int[] userids
     */
    public static function get_outstanding(cm_info $cm): array {
        $recorded = self::get_effective($cm);
        return array_values(array_filter(
            array_map('intval', array_keys(self::get_learners($cm))),
            fn($userid) => !isset($recorded[$userid])
        ));
    }

    /**
     * Check answers before saving.
     *
     * @param array $answers userid => ['ra' => bool, 'radetails' => string, 'lln' => bool, 'llndetails' => string]
     * @return array userid => ['ra' => error, 'lln' => error] for answers that are "yes" without details
     */
    public static function validate(array $answers): array {
        $errors = [];
        foreach ($answers as $userid => $answer) {
            foreach (['ra', 'lln'] as $field) {
                if (!empty($answer[$field]) && trim((string) ($answer[$field . 'details'] ?? '')) === '') {
                    $errors[$userid][$field] = get_string('decl' . $field . 'detailsrequired', 'report_evidencereporter');
                }
            }
        }
        return $errors;
    }

    /**
     * Save answers for learners in an activity (each saved learner is then recorded), then update the release hold.
     *
     * @param cm_info $cm
     * @param array $answers See {@see validate()}; must be valid
     * @return int How many learners' answers changed
     */
    public static function save(cm_info $cm, array $answers): int {
        global $DB, $USER;
        $existing = self::get_for_activity((int) $cm->id);
        $effective = self::get_effective($cm);
        $context = context_module::instance($cm->id);
        $changed = 0;
        $now = time();
        foreach ($answers as $userid => $answer) {
            $userid = (int) $userid;
            $record = (object) [
                'cmid' => (int) $cm->id,
                'userid' => $userid,
                'ra' => empty($answer['ra']) ? 0 : 1,
                'radetails' => empty($answer['ra']) ? '' : trim((string) $answer['radetails']),
                'lln' => empty($answer['lln']) ? 0 : 1,
                'llndetails' => empty($answer['lln']) ? '' : trim((string) $answer['llndetails']),
                'usermodified' => (int) $USER->id,
                'timemodified' => $now,
            ];
            $old = $existing[$userid] ?? null;
            $carried = !$old ? ($effective[$userid] ?? null) : null;
            if ($carried && self::same($carried, $record)) {
                // The same as the attempt it carries over from: nothing new to keep.
                continue;
            }
            if ($old) {
                if (self::same($old, $record)) {
                    continue;
                }
                $record->id = (int) $old->id;
                $DB->update_record(self::TABLE, $record);
            } else {
                $record->timecreated = $now;
                $record->id = $DB->insert_record(self::TABLE, $record);
            }
            $changed++;
            \report_evidencereporter\event\declaration_saved::create([
                'objectid' => $record->id,
                'context' => $context,
                'relateduserid' => $userid,
                'other' => ['ra' => $record->ra, 'lln' => $record->lln],
            ])->trigger();
        }
        $modinfo = get_fast_modinfo($cm->course, -1);
        foreach (self::get_group($cm) as $groupcmid) {
            release_hold::update($modinfo->get_cm($groupcmid));
        }
        return $changed;
    }

    /**
     * Do two records give the same answers?
     *
     * @param stdClass $a
     * @param stdClass $b
     * @return bool
     */
    private static function same(stdClass $a, stdClass $b): bool {
        return (int) $a->ra === (int) $b->ra && trim((string) $a->radetails) === trim((string) $b->radetails)
            && (int) $a->lln === (int) $b->lln && trim((string) $a->llndetails) === trim((string) $b->llndetails);
    }

    /**
     * Remove an activity's answers (when it's deleted).
     *
     * @param int $cmid
     */
    public static function delete_for_activity(int $cmid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['cmid' => $cmid]);
    }

    /**
     * The LLN line for a learner in an assessment.
     *
     * @param stdClass|null $record
     * @return stdClass recorded (bool), yes (bool), text ("N/A", the details, or "Not recorded")
     */
    public static function lln_line(?stdClass $record): stdClass {
        if (!$record) {
            return (object) ['recorded' => false, 'yes' => false,
                'text' => get_string('declnotrecorded', 'report_evidencereporter')];
        }
        return (object) ['recorded' => true, 'yes' => (bool) $record->lln,
            'text' => $record->lln ? (string) $record->llndetails : get_string('radjna', 'report_evidencereporter')];
    }

    /**
     * For one sampled judgement: the learner's answers in that course's activities linked to the unit or anything under
     * it.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $unitid
     * @param identity|null $identity
     * @return stdClass ra (adjustment lines, see {@see ra_item()}, for activities whose RA is yes), lln (one line per
     *                  activity: "Activity: N/A / details / Not recorded"), cmids (the activities)
     */
    public static function for_judgement(int $userid, int $courseid, int $unitid, ?identity $identity = null): stdClass {
        global $DB;
        $result = (object) ['ra' => [], 'lln' => '', 'cmids' => []];
        $sql = "SELECT DISTINCT mc.cmid
                  FROM {competency_modulecomp} mc
                  JOIN {competency} c ON c.id = mc.competencyid
                  JOIN {course_modules} cm ON cm.id = mc.cmid AND cm.course = :courseid
                 WHERE cm.deletioninprogress = 0
                   AND (c.id = :unitid OR " . $DB->sql_like('c.path', ':path') . ")";
        $cmids = $DB->get_fieldset_sql($sql, ['courseid' => $courseid, 'unitid' => $unitid, 'path' => '%/' . $unitid . '/%']);
        if (!$cmids) {
            return $result;
        }
        $modinfo = get_fast_modinfo($courseid, -1);
        $lines = [];
        $cms = [];
        foreach ($cmids as $cmid) {
            $cms[] = $modinfo->get_cm((int) $cmid);
        }
        $order = array_flip(array_keys($modinfo->get_cms()));
        usort($cms, fn($a, $b) => ($order[$a->id] ?? 0) <=> ($order[$b->id] ?? 0));
        foreach ($cms as $cm) {
            if (!self::available($cm) || !reasonable_adjustments::can_view(context_module::instance($cm->id), $userid)) {
                continue;
            }
            $result->cmids[] = (int) $cm->id;
            $record = self::get_effective($cm)[$userid] ?? null;
            if ($record && $record->ra && trim((string) $record->radetails) !== '') {
                $result->ra[(int) $cm->id] = self::ra_item($record, $cm, $identity);
            }
            $lines[] = $cm->get_formatted_name() . ': ' . self::lln_line($record)->text;
        }
        $result->lln = implode("\n", $lines);
        return $result;
    }

    /**
     * The RA answer as an adjustment line, for learners with no detailed adjustment recorded.
     *
     * @param stdClass $record
     * @param cm_info $cm
     * @param identity|null $identity
     * @return stdClass Like {@see reasonable_adjustments::format()}
     */
    public static function ra_item(stdClass $record, cm_info $cm, ?identity $identity = null): stdClass {
        $recorder = $record->usermodified ? \core_user::get_user($record->usermodified) : null;
        $name = $recorder ? fullname($recorder) : '';
        return (object) [
            'id' => 0,
            'cmid' => (int) $cm->id,
            'activity' => $cm->get_formatted_name(),
            'type' => get_string('declratype', 'report_evidencereporter'),
            'details' => (string) $record->radetails,
            'reason' => '',
            'hasreason' => false,
            'approvedby' => '',
            'recordedby' => $identity ? $identity->staff($name) : $name,
            'recorded' => userdate((int) $record->timemodified, get_string('strftimedate', 'core_langconfig')),
            'source' => '',
        ];
    }
}
