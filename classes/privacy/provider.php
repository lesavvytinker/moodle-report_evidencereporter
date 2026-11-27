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

namespace report_evidencereporter\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\provider as metadata_provider;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\plugin\provider as plugin_provider;
use core_privacy\local\request\{approved_contextlist, approved_userlist, contextlist, userlist, writer};
use report_evidencereporter\local\bulk;
use report_evidencereporter\local\reasonable_adjustments;
use report_evidencereporter\local\attempt_snapshots;
use report_evidencereporter\local\declarations;
use report_evidencereporter\local\release_hold;
use report_evidencereporter\local\sampling;

/**
 * Privacy provider.
 *
 * The plugin stores bulk evidence downloads (ZIP files kept in the requesting user's own file area for
 * {@see bulk::KEEP_DAYS} days) and validation samples (in the system context): which learners' judgements were drawn,
 * who drew them, who assessed them and who swapped them out; and reasonable adjustments made for learners (in each
 * activity's context): what, why, who approved and who recorded them. Everything else it shows comes from the competency
 * and gradebook subsystems.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements core_userlist_provider, metadata_provider, plugin_provider {
    /**
     * Describe stored data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:bulkfiles');
        $collection->add_database_table(sampling::TABLE, [
            'name' => 'privacy:metadata:samp:name',
            'filters' => 'privacy:metadata:samp:filters',
            'createdby' => 'privacy:metadata:samp:createdby',
            'timecreated' => 'privacy:metadata:samp:timecreated',
        ], 'privacy:metadata:samp');
        $collection->add_database_table(sampling::ITEMS, [
            'userid' => 'privacy:metadata:sitem:userid',
            'competencyid' => 'privacy:metadata:sitem:competencyid',
            'assessorid' => 'privacy:metadata:sitem:assessorid',
            'result' => 'privacy:metadata:sitem:result',
            'mark' => 'privacy:metadata:sitem:mark',
            'removedreason' => 'privacy:metadata:sitem:removedreason',
            'removedby' => 'privacy:metadata:sitem:removedby',
        ], 'privacy:metadata:sitem');
        $collection->add_database_table(reasonable_adjustments::TABLE, [
            'userid' => 'privacy:metadata:radj:userid',
            'adjtype' => 'privacy:metadata:radj:adjtype',
            'details' => 'privacy:metadata:radj:details',
            'reason' => 'privacy:metadata:radj:reason',
            'approvedby' => 'privacy:metadata:radj:approvedby',
            'usermodified' => 'privacy:metadata:radj:usermodified',
            'timemodified' => 'privacy:metadata:radj:timemodified',
        ], 'privacy:metadata:radj');
        $collection->add_database_table(attempt_snapshots::TABLE, [
            'userid' => 'privacy:metadata:snap:userid',
            'grade' => 'privacy:metadata:snap:grade',
            'assessorid' => 'privacy:metadata:snap:assessorid',
            'feedback' => 'privacy:metadata:snap:feedback',
            'timemarked' => 'privacy:metadata:snap:timemarked',
        ], 'privacy:metadata:snap');
        $collection->add_database_table(declarations::TABLE, [
            'userid' => 'privacy:metadata:decl:userid',
            'ra' => 'privacy:metadata:decl:ra',
            'radetails' => 'privacy:metadata:decl:radetails',
            'lln' => 'privacy:metadata:decl:lln',
            'llndetails' => 'privacy:metadata:decl:llndetails',
            'usermodified' => 'privacy:metadata:decl:usermodified',
            'timemodified' => 'privacy:metadata:decl:timemodified',
        ], 'privacy:metadata:decl');
        $collection->add_database_table(release_hold::TABLE, [
            'userid' => 'privacy:metadata:hold:userid',
        ], 'privacy:metadata:hold');
        return $collection;
    }

    /**
     * Contexts with data for a user: their user context, if they have bulk downloads.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {files} f ON f.contextid = ctx.id
                 WHERE ctx.contextlevel = :contextlevel AND ctx.instanceid = :userid
                   AND f.component = :component AND f.filearea = :filearea";
        $contextlist->add_from_sql($sql, ['contextlevel' => CONTEXT_USER, 'userid' => $userid,
            'component' => 'report_evidencereporter', 'filearea' => bulk::FILEAREA]);
        if (self::has_sample_data($userid)) {
            $contextlist->add_system_context();
        }
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {" . reasonable_adjustments::TABLE . "} ra ON ra.cmid = ctx.instanceid
                 WHERE ctx.contextlevel = :modlevel AND (ra.userid = :u1 OR ra.usermodified = :u2)";
        $contextlist->add_from_sql($sql, ['modlevel' => CONTEXT_MODULE, 'u1' => $userid, 'u2' => $userid]);
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {" . attempt_snapshots::TABLE . "} sn ON sn.cmid = ctx.instanceid
                 WHERE ctx.contextlevel = :modlevel AND (sn.userid = :u1 OR sn.assessorid = :u2)";
        $contextlist->add_from_sql($sql, ['modlevel' => CONTEXT_MODULE, 'u1' => $userid, 'u2' => $userid]);
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {" . declarations::TABLE . "} d ON d.cmid = ctx.instanceid
                 WHERE ctx.contextlevel = :modlevel AND (d.userid = :u1 OR d.usermodified = :u2)";
        $contextlist->add_from_sql($sql, ['modlevel' => CONTEXT_MODULE, 'u1' => $userid, 'u2' => $userid]);
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $userlist->add_from_sql('createdby', 'SELECT createdby FROM {' . sampling::TABLE . '}', []);
            foreach (['userid', 'assessorid', 'removedby'] as $field) {
                $userlist->add_from_sql($field, "SELECT $field FROM {" . sampling::ITEMS . "} WHERE $field > 0", []);
            }
            return;
        }
        if ($context->contextlevel == CONTEXT_MODULE) {
            foreach (['userid', 'usermodified'] as $field) {
                $userlist->add_from_sql(
                    $field,
                    "SELECT $field FROM {" . reasonable_adjustments::TABLE . "} WHERE cmid = :cmid AND $field > 0",
                    ['cmid' => $context->instanceid]
                );
            }
            foreach (['userid', 'assessorid'] as $field) {
                $userlist->add_from_sql(
                    $field,
                    "SELECT $field FROM {" . attempt_snapshots::TABLE . "} WHERE cmid = :cmid AND $field > 0",
                    ['cmid' => $context->instanceid]
                );
            }
            foreach (['userid', 'usermodified'] as $field) {
                $userlist->add_from_sql(
                    $field,
                    "SELECT $field FROM {" . declarations::TABLE . "} WHERE cmid = :cmid AND $field > 0",
                    ['cmid' => $context->instanceid]
                );
            }
            return;
        }
        if ($context->contextlevel != CONTEXT_USER) {
            return;
        }
        if (get_file_storage()->get_area_files($context->id, 'report_evidencereporter', bulk::FILEAREA, false, 'id', false)) {
            $userlist->add_user($context->instanceid);
        }
    }

    /**
     * Export a user's bulk downloads.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_SYSTEM) {
                self::export_samples((int) $contextlist->get_user()->id);
            }
            if ($context->contextlevel == CONTEXT_MODULE) {
                self::export_adjustments($context, (int) $contextlist->get_user()->id);
                self::export_snapshots($context, (int) $contextlist->get_user()->id);
            }
            if ($context->contextlevel == CONTEXT_USER && $context->instanceid == $contextlist->get_user()->id) {
                foreach (bulk::get_files((int) $context->instanceid) as $file) {
                    writer::with_context($context)->export_file([get_string('bulkdownloads', 'report_evidencereporter')], $file);
                }
            }
        }
    }

    /**
     * Delete all data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $DB->delete_records(sampling::ITEMS);
            $DB->delete_records(sampling::TABLE);
        }
        if ($context->contextlevel == CONTEXT_MODULE) {
            $DB->delete_records(reasonable_adjustments::TABLE, ['cmid' => $context->instanceid]);
            $DB->delete_records(attempt_snapshots::TABLE, ['cmid' => $context->instanceid]);
            $DB->delete_records(declarations::TABLE, ['cmid' => $context->instanceid]);
        }
        if ($context->contextlevel == CONTEXT_USER) {
            get_file_storage()->delete_area_files($context->id, 'report_evidencereporter', bulk::FILEAREA);
        }
    }

    /**
     * Delete a user's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_SYSTEM) {
                self::delete_sample_data([(int) $contextlist->get_user()->id]);
            }
            if ($context->contextlevel == CONTEXT_MODULE) {
                self::delete_adjustments((int) $context->instanceid, [(int) $contextlist->get_user()->id]);
            }
            if ($context->contextlevel == CONTEXT_USER && $context->instanceid == $contextlist->get_user()->id) {
                get_file_storage()->delete_area_files($context->id, 'report_evidencereporter', bulk::FILEAREA);
            }
        }
    }

    /**
     * Delete data for the approved users in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            self::delete_sample_data(array_map('intval', $userlist->get_userids()));
        }
        if ($context->contextlevel == CONTEXT_MODULE) {
            self::delete_adjustments((int) $context->instanceid, array_map('intval', $userlist->get_userids()));
        }
        if ($context->contextlevel == CONTEXT_USER && in_array($context->instanceid, $userlist->get_userids())) {
            get_file_storage()->delete_area_files($context->id, 'report_evidencereporter', bulk::FILEAREA);
        }
    }

    /**
     * Whether a user appears in any validation sample.
     *
     * @param int $userid
     * @return bool
     */
    protected static function has_sample_data(int $userid): bool {
        global $DB;
        return $DB->record_exists(sampling::TABLE, ['createdby' => $userid])
            || $DB->record_exists_select(
                sampling::ITEMS,
                'userid = :u1 OR assessorid = :u2 OR removedby = :u3',
                ['u1' => $userid, 'u2' => $userid, 'u3' => $userid]
            );
    }

    /**
     * Export a user's part in validation samples: judgements of theirs that were drawn, samples they drew, judgements
     * they assessed and swaps they made.
     *
     * @param int $userid
     */
    protected static function export_samples(int $userid): void {
        global $DB;
        $context = \context_system::instance();
        $folder = [get_string('sampling', 'report_evidencereporter')];
        $time = fn($t) => $t ? \core_privacy\local\request\transform::datetime($t) : null;
        $item = fn($i) => [
            'sample' => format_string((string) $DB->get_field(sampling::TABLE, 'name', ['id' => $i->sampleid])),
            'unit' => (string) $DB->get_field('competency', 'shortname', ['id' => $i->competencyid]),
            'result' => $i->result,
            'mark' => $i->mark,
            'judged' => $time((int) $i->judged),
            'swappedout' => \core_privacy\local\request\transform::yesno($i->removed),
            'swapreason' => $i->removedreason,
            'timeswapped' => $time((int) $i->timeremoved),
        ];
        $data = [
            'drawn' => array_values(array_map($item, $DB->get_records(sampling::ITEMS, ['userid' => $userid], 'id'))),
            'assessed' => array_values(array_map($item, $DB->get_records(sampling::ITEMS, ['assessorid' => $userid], 'id'))),
            'swapped' => array_values(array_map($item, $DB->get_records(sampling::ITEMS, ['removedby' => $userid], 'id'))),
            'samplesdrawn' => array_values(array_map(
                fn($s) => ['name' => format_string($s->name),
                'timecreated' => $time((int) $s->timecreated)],
                $DB->get_records(sampling::TABLE, ['createdby' => $userid], 'id')
            )),
        ];
        if (array_filter($data)) {
            writer::with_context($context)->export_data($folder, (object) $data);
        }
    }

    /**
     * Delete users' sample data: their own judgements leave the samples; as staff they're anonymised, so the record
     * of the sample stays intact.
     *
     * @param int[] $userids
     */
    protected static function delete_sample_data(array $userids): void {
        global $DB;
        if (!$userids) {
            return;
        }
        [$in, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select(sampling::ITEMS, "userid $in", $params);
        $DB->set_field_select(sampling::ITEMS, 'assessorid', 0, "assessorid $in", $params);
        $DB->set_field_select(sampling::ITEMS, 'removedby', 0, "removedby $in", $params);
        $DB->set_field_select(sampling::TABLE, 'createdby', 0, "createdby $in", $params);
    }

    /**
     * Export reasonable adjustments in an activity: those made for the user, and those they recorded.
     *
     * @param \context $context Module context
     * @param int $userid
     */
    protected static function export_adjustments(\context $context, int $userid): void {
        global $DB;
        $item = fn($r) => [
            'adjustment' => reasonable_adjustments::type_label($r->adjtype),
            'details' => $r->details,
            'reason' => $r->reason,
            'approvedby' => $r->approvedby,
            'timemodified' => \core_privacy\local\request\transform::datetime($r->timemodified),
        ];
        $data = [
            'madeforyou' => array_values(array_map($item, $DB->get_records(
                reasonable_adjustments::TABLE,
                ['cmid' => $context->instanceid, 'userid' => $userid],
                'id'
            ))),
            'recordedbyyou' => array_values(array_map($item, $DB->get_records(
                reasonable_adjustments::TABLE,
                ['cmid' => $context->instanceid, 'usermodified' => $userid],
                'id'
            ))),
        ];
        $answers = fn($r) => [
            'reasonableadjustment' => \core_privacy\local\request\transform::yesno($r->ra),
            'radetails' => $r->radetails,
            'lln' => \core_privacy\local\request\transform::yesno($r->lln),
            'llndetails' => $r->llndetails,
            'timemodified' => \core_privacy\local\request\transform::datetime($r->timemodified),
        ];
        $foryou = $DB->get_record(declarations::TABLE, ['cmid' => $context->instanceid, 'userid' => $userid]);
        $data['raandllnforyou'] = $foryou ? $answers($foryou) : [];
        $data['raandllnrecordedbyyou'] = array_values(array_map($answers, $DB->get_records(
            declarations::TABLE,
            ['cmid' => $context->instanceid, 'usermodified' => $userid],
            'id'
        )));
        if (array_filter($data)) {
            writer::with_context($context)->export_data([get_string('adjustments', 'report_evidencereporter')], (object) $data);
        }
    }

    /**
     * Delete users' reasonable adjustment data in an activity: adjustments made for them go; as the staff member who
     * recorded one, they're anonymised.
     *
     * @param int $cmid
     * @param int[] $userids
     */
    protected static function delete_adjustments(int $cmid, array $userids): void {
        global $DB;
        if (!$userids) {
            return;
        }
        [$in, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['cmid'] = $cmid;
        $DB->delete_records_select(reasonable_adjustments::TABLE, "cmid = :cmid AND userid $in", $params);
        $DB->set_field_select(reasonable_adjustments::TABLE, 'usermodified', 0, "cmid = :cmid AND usermodified $in", $params);
        $DB->delete_records_select(attempt_snapshots::TABLE, "cmid = :cmid AND userid $in", $params);
        $DB->set_field_select(attempt_snapshots::TABLE, 'assessorid', 0, "cmid = :cmid AND assessorid $in", $params);
        $DB->delete_records_select(declarations::TABLE, "cmid = :cmid AND userid $in", $params);
        $DB->set_field_select(declarations::TABLE, 'usermodified', 0, "cmid = :cmid AND usermodified $in", $params);
        $DB->delete_records_select(release_hold::TABLE, "cmid = :cmid AND userid $in", $params);
    }

    /**
     * Export attempt snapshots in an activity: the user's own attempts, and those they marked.
     *
     * @param \context $context Module context
     * @param int $userid
     */
    protected static function export_snapshots(\context $context, int $userid): void {
        global $DB;
        $item = fn($r) => [
            'attempt' => (int) $r->attemptno,
            'grade' => $r->grade,
            'feedback' => array_map(fn($p) => $p['text'], json_decode((string) $r->feedback, true) ?: []),
            'timemarked' => \core_privacy\local\request\transform::datetime($r->timemarked),
        ];
        $data = [
            'yourattempts' => array_values(array_map($item, $DB->get_records(
                attempt_snapshots::TABLE,
                ['cmid' => $context->instanceid, 'userid' => $userid],
                'attemptno'
            ))),
            'attemptsyoumarked' => array_values(array_map($item, $DB->get_records(
                attempt_snapshots::TABLE,
                ['cmid' => $context->instanceid, 'assessorid' => $userid],
                'attemptno'
            ))),
        ];
        if (array_filter($data)) {
            writer::with_context($context)->export_data([get_string('snapattempts', 'report_evidencereporter')], (object) $data);
        }
    }
}
