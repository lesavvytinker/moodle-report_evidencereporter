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

use context_user;
use stdClass;

/**
 * Bulk downloads: evidence summaries or evidence packs for every learner on a learning plan template or in a cohort.
 *
 * The work runs in a background task as the person who asked for it, so their access and the release rules apply to
 * every learner. The result is one ZIP kept in that person's own file area for {@see self::KEEP_DAYS} days.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk {
    /** @var int Most learners in one request. */
    public const MAX_LEARNERS = 200;

    /** @var int Days a finished download is kept. */
    public const KEEP_DAYS = 7;

    /** @var string Capability needed to request bulk downloads. */
    public const CAPABILITY = 'report/evidencereporter:bulkdownload';

    /** @var string File area for finished downloads (in the requester's user context). */
    public const FILEAREA = 'bulk';

    /** @var string Summaries only: one PDF per learner. */
    public const MODE_SUMMARIES = 'summaries';

    /** @var string Full evidence packs: one ZIP per learner. */
    public const MODE_PACKS = 'packs';

    /**
     * Learners for a learning plan template (anyone with a plan from it) or a cohort, sorted by name.
     *
     * @param string $sourcetype 'template' or 'cohort'
     * @param int $sourceid
     * @return stdClass[] user records, keyed by id
     */
    public static function get_learners(string $sourcetype, int $sourceid): array {
        global $DB;
        $fields = 'u.id, u.username, u.idnumber, u.email, ' . implode(', ', array_map(
            fn($f) => "u.{$f}",
            \core_user\fields::get_name_fields()
        ));
        if ($sourcetype === 'template') {
            $sql = "SELECT DISTINCT {$fields}
                      FROM {competency_plan} p
                      JOIN {user} u ON u.id = p.userid
                     WHERE p.templateid = :sourceid AND u.deleted = 0";
        } else {
            $sql = "SELECT DISTINCT {$fields}
                      FROM {cohort_members} cm
                      JOIN {user} u ON u.id = cm.userid
                     WHERE cm.cohortid = :sourceid AND u.deleted = 0";
        }
        $users = $DB->get_records_sql($sql, ['sourceid' => $sourceid]);
        uasort($users, fn($a, $b) => strcmp(fullname($a), fullname($b)));
        return $users;
    }

    /**
     * Name of the template or cohort.
     *
     * @param string $sourcetype
     * @param int $sourceid
     * @return string
     */
    public static function get_source_name(string $sourcetype, int $sourceid): string {
        global $DB;
        $name = $sourcetype === 'template'
            ? $DB->get_field('competency_template', 'shortname', ['id' => $sourceid])
            : $DB->get_field('cohort', 'name', ['id' => $sourceid]);
        return format_string((string) $name, true, ['context' => \context_system::instance()]);
    }

    /**
     * Build the bulk download as the current user and store it in their file area.
     *
     * @param array $options sourcetype, sourceid, frameworkid (0: each learner's learning plans), mode, includefeedback,
     *     redactname, redactusername, redactstaff
     * @return \stored_file
     */
    public static function build(array $options): \stored_file {
        global $USER;
        \core_php_time_limit::raise(0);
        raise_memory_limit(MEMORY_HUGE);

        $sourcetype = $options['sourcetype'] === 'cohort' ? 'cohort' : 'template';
        $sourceid = (int) $options['sourceid'];
        $frameworkid = (int) $options['frameworkid'];
        $mode = $options['mode'] === self::MODE_PACKS ? self::MODE_PACKS : self::MODE_SUMMARIES;
        $includefeedback = !empty($options['includefeedback']);
        $redact = [
            'redactname' => !empty($options['redactname']),
            'redactusername' => !empty($options['redactusername']),
            'redactstaff' => !empty($options['redactstaff']),
        ];

        $learners = array_slice(self::get_learners($sourcetype, $sourceid), 0, self::MAX_LEARNERS, true);
        $framework = $frameworkid ? \core_competency\competency_framework::get_record(['id' => $frameworkid], MUST_EXIST)
            : null;
        $coveringname = $framework ? format_string($framework->get('shortname'))
            : get_string('bulkplans', 'report_evidencereporter');
        $sourcename = self::get_source_name($sourcetype, $sourceid);

        $dir = make_request_directory();
        $files = [];
        $index = [[terms::get('learner'), get_string('username'),
            get_string('idnumber'), get_string('learnerreference', 'report_evidencereporter'),
            get_string('bulkstatus', 'report_evidencereporter'),
            get_string('bulkfile', 'report_evidencereporter')]];
        $included = 0;
        foreach ($learners as $learner) {
            $identity = identity::from_flags($learner, $redact);
            $row = [$identity->name(), $identity->username(), $identity->idnumber(), $identity->reference()];
            $frameworks = summary::get_frameworks((int) $learner->id);
            if ($framework) {
                $scope = isset($frameworks[$frameworkid]) ? scope::for_framework((int) $learner->id, $frameworkid) : null;
            } else {
                // The same default as the summary page: their active plans, otherwise their latest plan.
                $scope = scope::get_default((int) $learner->id, []);
            }
            if (!$scope) {
                $index[] = array_merge($row, [get_string($framework ? 'bulkskipped' : 'bulknoplan', 'report_evidencereporter'),
                    '']);
                continue;
            }
            $base = clean_filename($identity->file_label());
            $pack = new evidence_pack((int) $learner->id, $scope, $includefeedback, $redact);
            try {
                if ($mode === self::MODE_PACKS) {
                    $filename = $base . '.zip';
                    $files[$filename] = $pack->build();
                } else {
                    $filename = $base . '.pdf';
                    $path = $dir . '/' . count($files) . '.pdf';
                    file_put_contents($path, $pack->build_summary_pdf());
                    $files[$filename] = $path;
                }
                $index[] = array_merge($row, [get_string('bulkincluded', 'report_evidencereporter'), $filename]);
                $included++;
            } catch (\Throwable $e) {
                $index[] = array_merge($row, [get_string('bulkerror', 'report_evidencereporter', $e->getMessage()), '']);
            }
            // Evidence packs hold whole requests' worth of caches; free them between learners. Each pack used its
            // learner's terminology; the index and README use the site's.
            terms::use_profile(null);
            attempts::reset_cache();
            grade_adjustment::reset_cache();
            similarity::reset_cache();
            gc_collect_cycles();
        }

        $csv = '';
        foreach ($index as $line) {
            $csv .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string) $v) . '"', $line)) . "\r\n";
        }
        $files['index.csv'] = [$csv];
        $redactidentity = identity::from_flags((object) ['id' => 0], $redact);
        $files['README.txt'] = [implode("\r\n", array_filter([
            get_string($mode === self::MODE_PACKS ? 'bulkpacks' : 'bulksummaries', 'report_evidencereporter'),
            str_repeat('=', 40),
            get_string('bulksource', 'report_evidencereporter') . ': ' . $sourcename,
            get_string('scopechoice', 'report_evidencereporter') . ': ' . $coveringname,
            get_string('generated', 'report_evidencereporter') . ': ' . userdate(time()) . ' · '
                . $redactidentity->staff(fullname($USER)),
            get_string('includefeedback', 'report_evidencereporter') . ': ' . get_string($includefeedback ? 'yes' : 'no'),
            get_string('bulkcounts', 'report_evidencereporter', ['included' => $included, 'total' => count($learners)]),
            $redactidentity->redaction_note() ?: null,
            $redactidentity->is_redacted() ? get_string('redactionfilesnote', 'report_evidencereporter') : null,
            '',
            get_string('bulkreadme', 'report_evidencereporter'),
        ], fn($line) => $line !== null)) . "\r\n"];

        $zippath = $dir . '/bulk.zip';
        if (!get_file_packer('application/zip')->archive_to_pathname($files, $zippath)) {
            throw new \moodle_exception('packfailed', 'report_evidencereporter');
        }

        $filename = clean_filename(implode(' - ', [
            get_string($mode === self::MODE_PACKS ? 'bulkpacks' : 'bulksummaries', 'report_evidencereporter'),
            $sourcename,
            $framework ? ($framework->get('idnumber') ?: $framework->get('shortname'))
                : get_string('bulkplans', 'report_evidencereporter'),
            userdate(time(), '%Y-%m-%d %H%M'),
        ])) . '.zip';
        // Each download gets its own item ID, so two downloads with the same name never clash.
        $contextid = context_user::instance($USER->id)->id;
        $fs = get_file_storage();
        $itemid = time();
        while (!$fs->is_area_empty($contextid, 'report_evidencereporter', self::FILEAREA, $itemid)) {
            $itemid++;
        }
        $file = $fs->create_file_from_pathname([
            'contextid' => $contextid,
            'component' => 'report_evidencereporter',
            'filearea' => self::FILEAREA,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $USER->id,
            'source' => get_string('bulkcounts', 'report_evidencereporter', ['included' => $included,
                'total' => count($learners)]),
        ], $zippath);

        \report_evidencereporter\event\bulk_created::create([
            'context' => \context_system::instance(),
            'other' => ['sourcetype' => $sourcetype, 'sourceid' => $sourceid, 'frameworkid' => $frameworkid,
                'mode' => $mode, 'learners' => $included] + array_map('intval', $redact),
        ])->trigger();
        return $file;
    }

    /**
     * The user's finished downloads, newest first.
     *
     * @param int $userid
     * @return \stored_file[]
     */
    public static function get_files(int $userid): array {
        $files = get_file_storage()->get_area_files(
            context_user::instance($userid)->id,
            'report_evidencereporter',
            self::FILEAREA,
            false,
            'timecreated DESC',
            false
        );
        return array_values($files);
    }

    /**
     * The user's queued or running requests.
     *
     * @param int $userid
     * @return \core\task\adhoc_task[]
     */
    public static function get_pending(int $userid): array {
        return array_values(array_filter(
            \core\task\manager::get_adhoc_tasks(\report_evidencereporter\task\bulk_download::class),
            fn($task) => (int) $task->get_userid() === $userid
        ));
    }

    /**
     * Delete finished downloads older than {@see self::KEEP_DAYS} days.
     *
     * @return int Files deleted
     */
    public static function cleanup(): int {
        global $DB;
        $cutoff = time() - self::KEEP_DAYS * DAYSECS;
        $fs = get_file_storage();
        $records = $DB->get_records_select(
            'files',
            'component = ? AND filearea = ? AND filename <> ? AND timecreated < ?',
            ['report_evidencereporter', self::FILEAREA, '.', $cutoff],
            '',
            'id'
        );
        foreach ($records as $record) {
            $fs->get_file_by_id($record->id)->delete();
        }
        return count($records);
    }
}
