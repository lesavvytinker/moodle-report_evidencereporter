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

use core_competency\evidence;
use stdClass;

/**
 * What a judgement was made against, kept as it was at the time.
 *
 * Moodle's competency frameworks are live: editing a competency's ID number, name or description changes every course,
 * plan and learner record that uses it, including past judgements. So when a judgement is recorded (a rating, or a
 * competency completed), this keeps the competency's and framework's wording at that moment, and the assessor's
 * credentials from the user profile fields chosen in the settings (typically kept up to date from the student
 * management system). Documents then show what the learner was actually assessed against, and flag where the framework
 * has changed since.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class judgement_snapshot {
    /** @var string The table. */
    public const TABLE = 'report_evidencereporter_jsnap';

    /** @var int[] Evidence actions that are judgements. */
    public const ACTIONS = [evidence::ACTION_COMPLETE, evidence::ACTION_OVERRIDE];

    /**
     * Record the snapshot for a piece of evidence, if it's a judgement and has none yet.
     *
     * @param int $evidenceid
     * @return int|null The snapshot's ID, or null when nothing was recorded
     */
    public static function capture(int $evidenceid): ?int {
        global $DB;
        if ($DB->record_exists(self::TABLE, ['evidenceid' => $evidenceid])) {
            return null;
        }
        $ev = $DB->get_record_sql(self::source_sql() . ' WHERE ev.id = :id', ['id' => $evidenceid]);
        if (!$ev || !in_array((int) $ev->action, self::ACTIONS)) {
            return null;
        }
        $record = self::make_record($ev, time(), false);
        $record->assessorid = self::find_assessor($ev);
        $credentials = self::read_credentials($record->assessorid);
        $record->credentials = $credentials ? json_encode($credentials) : null;
        return (int) $DB->insert_record(self::TABLE, $record);
    }

    /**
     * Record snapshots for judgements made before snapshots were kept: the wording as it is now (when this runs), and
     * no credentials (today's credentials say nothing about the day of the judgement). Marked as backfilled, so
     * documents can say when the wording was recorded.
     *
     * @return int How many were recorded
     */
    public static function backfill(): int {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(self::ACTIONS, SQL_PARAMS_NAMED, 'act');
        $rs = $DB->get_recordset_sql(self::source_sql() . "
             LEFT JOIN {" . self::TABLE . "} js ON js.evidenceid = ev.id
                 WHERE js.id IS NULL AND ev.action {$insql}
              ORDER BY ev.id", $params);
        $now = time();
        $batch = [];
        $count = 0;
        foreach ($rs as $ev) {
            $batch[] = self::make_record($ev, $now, true);
            if (count($batch) >= 1000) {
                $DB->insert_records(self::TABLE, $batch);
                $count += count($batch);
                $batch = [];
            }
        }
        $rs->close();
        if ($batch) {
            $DB->insert_records(self::TABLE, $batch);
            $count += count($batch);
        }
        return $count;
    }

    /**
     * Snapshots for these pieces of evidence, keyed by evidence ID.
     *
     * @param int[] $evidenceids
     * @return stdClass[]
     */
    public static function get_many(array $evidenceids): array {
        global $DB;
        $evidenceids = array_values(array_unique(array_filter(array_map('intval', $evidenceids))));
        if (!$evidenceids) {
            return [];
        }
        $result = [];
        foreach (array_chunk($evidenceids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'jse');
            foreach ($DB->get_records_select(self::TABLE, "evidenceid {$insql}", $params) as $snapshot) {
                $result[(int) $snapshot->evidenceid] = $snapshot;
            }
        }
        return $result;
    }

    /**
     * Prepare snapshots for display: the assessor's credentials, and whether (and how) the competency or its framework
     * has changed since.
     *
     * @param stdClass[] $snapshots From {@see get_many()}
     * @param bool $withcredentials Include credentials (see {@see can_view_credentials()})
     * @return stdClass[] keyed by evidence ID: credentials (one line, '' when none), changed (bool), wording (ID number
     *                    and name when judged), description (plain text, when judged), framework (name when judged),
     *                    recorded (date the wording was recorded), backfilled (bool: recorded after the judgement)
     */
    public static function describe(array $snapshots, bool $withcredentials = true): array {
        global $DB;
        if (!$snapshots) {
            return [];
        }
        $compids = array_values(array_unique(array_map(fn($s) => (int) $s->competencyid, $snapshots)));
        $fwids = array_values(array_unique(array_map(fn($s) => (int) $s->frameworkid, $snapshots)));
        $live = $DB->get_records_list('competency', 'id', $compids, '', 'id, idnumber, shortname, description');
        $livefw = $DB->get_records_list('competency_framework', 'id', $fwids, '', 'id, idnumber, shortname');
        $syscontext = \context_system::instance();
        $result = [];
        foreach ($snapshots as $snapshot) {
            $comp = $live[$snapshot->competencyid] ?? null;
            $fw = $livefw[$snapshot->frameworkid] ?? null;
            $changed = !$comp || !$fw
                || (string) $comp->idnumber !== (string) $snapshot->compidnumber
                || (string) $comp->shortname !== (string) $snapshot->compshortname
                || trim((string) $comp->description) !== trim((string) $snapshot->compdescription)
                || (string) $fw->idnumber !== (string) $snapshot->fwidnumber
                || (string) $fw->shortname !== (string) $snapshot->fwshortname;
            $credentials = $withcredentials && $snapshot->credentials
                ? self::format_credentials((array) json_decode($snapshot->credentials)) : '';
            $result[(int) $snapshot->evidenceid] = (object) [
                'credentials' => $credentials,
                'changed' => $changed,
                'wording' => trim($snapshot->compidnumber . ' '
                    . format_string((string) $snapshot->compshortname, true, ['context' => $syscontext])),
                'description' => trim(html_to_text(format_text(
                    (string) $snapshot->compdescription,
                    (int) $snapshot->compdescriptionformat,
                    ['context' => $syscontext]
                ), 0, false)),
                'framework' => format_string((string) $snapshot->fwshortname, true, ['context' => $syscontext]),
                'recorded' => userdate((int) $snapshot->timecaptured, get_string('strftimedate', 'core_langconfig')),
                'backfilled' => !empty($snapshot->backfilled),
            ];
        }
        return $result;
    }

    /**
     * Profile fields that hold assessor credentials, chosen in the settings (short names).
     *
     * @return string[]
     */
    public static function credential_fields(): array {
        $config = (string) get_config('report_evidencereporter', 'credentialfields');
        return array_values(array_filter(array_map('trim', explode(',', $config))));
    }

    /**
     * Profile fields that could hold credentials, for the setting: short name => name.
     *
     * @return string[]
     */
    public static function field_choices(): array {
        global $DB;
        $choices = [];
        try {
            foreach ($DB->get_records('user_info_field', null, 'sortorder', 'id, shortname, name') as $field) {
                $choices[$field->shortname] = format_string($field->name, true, ['context' => \context_system::instance()]);
            }
        } catch (\dml_exception $e) {
            // During installation.
            return [];
        }
        return $choices;
    }

    /**
     * May the current user see assessors' credentials in this learner's documents? Staff always may; the learner only
     * when the site allows it.
     *
     * @param int $learnerid
     * @return bool
     */
    public static function can_view_credentials(int $learnerid): bool {
        global $USER;
        return (int) $USER->id !== $learnerid || get_config('report_evidencereporter', 'credentialslearner');
    }

    /**
     * Credentials as one line: "Name: value; Name: value".
     *
     * @param array $credentials From the snapshot (each with name, datatype, value)
     * @return string
     */
    public static function format_credentials(array $credentials): string {
        $parts = [];
        foreach ($credentials as $item) {
            $item = (object) $item;
            $value = trim((string) ($item->value ?? ''));
            switch ($item->datatype ?? '') {
                case 'datetime':
                    $value = (int) $value > 0 ? userdate((int) $value, get_string('strftimedate', 'core_langconfig')) : '';
                    break;
                case 'checkbox':
                    $value = $value === '1' ? get_string('yes') : '';
                    break;
                case 'textarea':
                    $value = trim(html_to_text($value, 0, false));
                    break;
            }
            if ($value === '') {
                continue;
            }
            $name = format_string((string) ($item->name ?? ''), true, ['context' => \context_system::instance()]);
            $parts[] = ($name !== '' ? $name . ': ' : '') . $value;
        }
        return implode('; ', $parts);
    }

    /**
     * A user's credentials from the chosen profile fields, as stored (raw values, formatted on display).
     *
     * @param int $userid
     * @return array[] each with shortname, name, datatype, value; empty values left out
     */
    public static function read_credentials(int $userid): array {
        global $DB;
        $shortnames = self::credential_fields();
        if (!$userid || !$shortnames) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($shortnames, SQL_PARAMS_NAMED, 'cf');
        $params['userid'] = $userid;
        $rows = $DB->get_records_sql("SELECT f.id, f.shortname, f.name, f.datatype, d.data
                                        FROM {user_info_field} f
                                        JOIN {user_info_data} d ON d.fieldid = f.id AND d.userid = :userid
                                       WHERE f.shortname {$insql}
                                    ORDER BY f.categoryid, f.sortorder", $params);
        $result = [];
        foreach ($rows as $row) {
            if (trim((string) $row->data) === '' || ($row->datatype === 'checkbox' && $row->data !== '1')) {
                continue;
            }
            $result[] = ['shortname' => $row->shortname, 'name' => $row->name, 'datatype' => $row->datatype,
                'value' => (string) $row->data];
        }
        return $result;
    }

    /**
     * Remove snapshots for these learners.
     *
     * @param int[] $userids
     */
    public static function delete_for_learners(array $userids): void {
        global $DB;
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $userids), SQL_PARAMS_NAMED);
        $DB->delete_records_select(self::TABLE, "userid {$insql}", $params);
    }

    /**
     * Remove these assessors from snapshots: the record of what was judged stays, their identity and credentials go.
     *
     * @param int[] $userids
     */
    public static function anonymise_assessors(array $userids): void {
        global $DB;
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $userids), SQL_PARAMS_NAMED);
        $DB->set_field_select(self::TABLE, 'credentials', null, "assessorid {$insql}", $params);
        $DB->set_field_select(self::TABLE, 'assessorid', 0, "assessorid {$insql}", $params);
    }

    /**
     * SELECT ... FROM for a judgement and what it was made against (add the WHERE).
     *
     * @return string
     */
    private static function source_sql(): string {
        return "SELECT ev.id, ev.action, ev.actionuserid, ev.contextid, uc.userid, uc.competencyid,
                       c.idnumber, c.shortname, c.description, c.descriptionformat, c.competencyframeworkid,
                       cf.idnumber AS fwidnumber, cf.shortname AS fwshortname
                  FROM {competency_evidence} ev
                  JOIN {competency_usercomp} uc ON uc.id = ev.usercompetencyid
                  JOIN {competency} c ON c.id = uc.competencyid
                  JOIN {competency_framework} cf ON cf.id = c.competencyframeworkid";
    }

    /**
     * The table record for a judgement.
     *
     * @param stdClass $ev From {@see source_sql()}
     * @param int $time
     * @param bool $backfilled
     * @return stdClass
     */
    private static function make_record(stdClass $ev, int $time, bool $backfilled): stdClass {
        return (object) [
            'evidenceid' => (int) $ev->id,
            'userid' => (int) $ev->userid,
            'competencyid' => (int) $ev->competencyid,
            'frameworkid' => (int) $ev->competencyframeworkid,
            'compidnumber' => (string) $ev->idnumber,
            'compshortname' => (string) $ev->shortname,
            'compdescription' => $ev->description,
            'compdescriptionformat' => (int) $ev->descriptionformat,
            'fwidnumber' => (string) $ev->fwidnumber,
            'fwshortname' => (string) $ev->fwshortname,
            'assessorid' => 0,
            'credentials' => null,
            'backfilled' => (int) $backfilled,
            'timecaptured' => $time,
        ];
    }

    /**
     * Who made the judgement: the user recorded on the evidence (ratings), or for activity completion (where core
     * records none) whoever graded the activity for the learner, unless the learner did it themselves.
     *
     * @param stdClass $ev From {@see source_sql()}
     * @return int 0 when unknown
     */
    private static function find_assessor(stdClass $ev): int {
        global $DB;
        if (!empty($ev->actionuserid) && (int) $ev->actionuserid !== (int) $ev->userid) {
            return (int) $ev->actionuserid;
        }
        $grader = $DB->get_field_sql("SELECT gg.usermodified
                                        FROM {context} ctx
                                        JOIN {course_modules} cm ON cm.id = ctx.instanceid
                                        JOIN {modules} m ON m.id = cm.module
                                        JOIN {grade_items} gi ON gi.courseid = cm.course AND gi.itemtype = 'mod'
                                                             AND gi.itemmodule = m.name AND gi.iteminstance = cm.instance
                                                             AND gi.itemnumber = 0
                                        JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid
                                       WHERE ctx.id = :contextid AND ctx.contextlevel = :modlevel", [
            'userid' => $ev->userid, 'contextid' => $ev->contextid, 'modlevel' => CONTEXT_MODULE]);
        return $grader && (int) $grader !== (int) $ev->userid ? (int) $grader : 0;
    }
}
