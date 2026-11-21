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

    /** @var string[] Where learners come from, and the form field holding the choice. */
    public const SOURCE_FIELDS = ['template' => 'templateid', 'cohort' => 'cohortid', 'course' => 'courseid',
        'category' => 'categoryid'];

    /**
     * Learners for a learning plan template (anyone with a plan from it), a cohort, a course or a course category,
     * sorted by name. For courses, learners are users with an active enrolment who can be rated on competencies.
     *
     * @param string $sourcetype 'template', 'cohort', 'course' or 'category'
     * @param int $sourceid
     * @param bool $subcategories For a category, include courses in its subcategories
     * @return stdClass[] user records, keyed by id
     */
    public static function get_learners(string $sourcetype, int $sourceid, bool $subcategories = true): array {
        global $DB;
        $fields = 'u.id, u.username, u.idnumber, u.email, ' . implode(', ', array_map(
            fn($f) => "u.{$f}",
            \core_user\fields::get_name_fields()
        ));
        if ($sourcetype === 'course' || $sourcetype === 'category') {
            $users = [];
            foreach (self::get_source_courseids($sourcetype, $sourceid, $subcategories) as $courseid) {
                [$enrolsql, $params] = get_enrolled_sql(
                    \context_course::instance($courseid),
                    'moodle/competency:coursecompetencygradable',
                    0,
                    true
                );
                $users += $DB->get_records_sql("SELECT {$fields} FROM {user} u
                    JOIN ({$enrolsql}) je ON je.id = u.id WHERE u.deleted = 0", $params);
            }
            uasort($users, fn($a, $b) => strcmp(fullname($a), fullname($b)));
            return $users;
        }
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
     * Courses for a course or category source.
     *
     * @param string $sourcetype 'course' or 'category'
     * @param int $sourceid
     * @param bool $subcategories
     * @return int[]
     */
    private static function get_source_courseids(string $sourcetype, int $sourceid, bool $subcategories): array {
        if ($sourcetype === 'course') {
            return $sourceid && $sourceid !== SITEID ? [$sourceid] : [];
        }
        // Straight from the database: the bulk tool is for managers, and core_course_category::get_courses() filters by
        // what the current user can see (more strictly in Moodle 5.2), which would silently drop learners.
        global $DB;
        $path = $DB->get_field('course_categories', 'path', ['id' => $sourceid]);
        if ($path === false) {
            return [];
        }
        $where = 'category = :categoryid';
        $params = ['categoryid' => $sourceid];
        if ($subcategories) {
            $where .= ' OR category IN (SELECT id FROM {course_categories} WHERE ' . $DB->sql_like('path', ':path') . ')';
            $params['path'] = $path . '/%';
        }
        return array_map('intval', $DB->get_fieldset_select('course', 'id', $where, $params));
    }

    /**
     * Name of the template, cohort, course or category.
     *
     * @param string $sourcetype
     * @param int $sourceid
     * @param bool $subcategories
     * @return string
     */
    public static function get_source_name(string $sourcetype, int $sourceid, bool $subcategories = true): string {
        global $DB;
        $context = \context_system::instance();
        switch ($sourcetype) {
            case 'template':
                $name = $DB->get_field('competency_template', 'shortname', ['id' => $sourceid]);
                break;
            case 'course':
                $name = $DB->get_field('course', 'fullname', ['id' => $sourceid]);
                break;
            case 'category':
                $name = $DB->get_field('course_categories', 'name', ['id' => $sourceid]);
                return get_string(
                    $subcategories ? 'bulkcategorywithsubs' : 'bulkcategoryonly',
                    'report_evidencereporter',
                    format_string((string) $name, true, ['context' => $context])
                );
            default:
                $name = $DB->get_field('cohort', 'name', ['id' => $sourceid]);
        }
        return format_string((string) $name, true, ['context' => $context]);
    }

    /**
     * Build the bulk download as the current user and store it in their file area.
     *
     * @param array $options sourcetype, sourceid, frameworkid (0: each learner's learning plans), competencyid (one unit,
     *     instead of a framework), mode, includefeedback,
     *     redactname, redactusername, redactstaff
     * @return \stored_file
     */
    public static function build(array $options): \stored_file {
        global $USER;
        \core_php_time_limit::raise(0);
        raise_memory_limit(MEMORY_HUGE);

        $sourcetype = isset(self::SOURCE_FIELDS[$options['sourcetype']]) ? $options['sourcetype'] : 'template';
        $sourceid = (int) $options['sourceid'];
        $subcategories = (bool) ($options['subcategories'] ?? true);
        $frameworkid = (int) $options['frameworkid'];
        $competencyid = (int) ($options['competencyid'] ?? 0);
        $mode = $options['mode'] === self::MODE_PACKS ? self::MODE_PACKS : self::MODE_SUMMARIES;
        $includefeedback = !empty($options['includefeedback']);
        $redact = [
            'redactname' => !empty($options['redactname']),
            'redactusername' => !empty($options['redactusername']),
            'redactstaff' => !empty($options['redactstaff']),
        ];

        $learners = array_slice(self::get_learners($sourcetype, $sourceid, $subcategories), 0, self::MAX_LEARNERS, true);
        $framework = $frameworkid ? \core_competency\competency_framework::get_record(['id' => $frameworkid], MUST_EXIST)
            : null;
        $unit = $competencyid ? \core_competency\competency::get_record(['id' => $competencyid], MUST_EXIST)->to_record()
            : null;
        $coveringname = $unit ? terms::get('unit') . ': ' . scope::unit_label($unit)
            : ($framework ? format_string($framework->get('shortname')) : get_string('bulkplans', 'report_evidencereporter'));
        $sourcename = self::get_source_name($sourcetype, $sourceid, $subcategories);

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
            if ($unit) {
                $scope = isset(scope::get_units((int) $learner->id, $frameworks)[$competencyid])
                    ? scope::for_unit((int) $learner->id, $competencyid) : null;
            } else if ($framework) {
                $scope = isset($frameworks[$frameworkid]) ? scope::for_framework((int) $learner->id, $frameworkid) : null;
            } else {
                // The same default as the summary page: their active plans, otherwise their latest plan, otherwise the
                // units they have evidence in.
                $scope = scope::get_default((int) $learner->id, $frameworks);
                if ($scope && $scope->type === scope::FRAMEWORK) {
                    // A whole framework is a choice, not a default: without a plan or evidence there's nothing to cover.
                    $scope = null;
                }
            }
            if (!$scope) {
                $status = get_string($framework || $unit ? 'bulkskipped' : 'bulknoplan', 'report_evidencereporter');
                $index[] = array_merge($row, [$status, '']);
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
            forum_posts::reset_cache();
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
                'competencyid' => $competencyid, 'mode' => $mode, 'learners' => $included] + array_map('intval', $redact),
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
