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

use stdClass;

/**
 * Validation and moderation samples: a random, reproducible selection of assessment judgements for an auditor.
 *
 * A judgement is one learner's result for one unit in one course: the course-level rating of the unit, or, where only
 * the criteria below it were rated, those. The eligible judgements (the population) come from the filters: courses,
 * units, judgement dates, result and marks. The sample is drawn from them at random, optionally spread across groups
 * (assessors, results, mark bands or courses), from a draw code, so the same filters and population always give the same
 * sample. Every draw is kept with how it was made. A judgement can be swapped out (e.g. a withdrawn learner) with a
 * reason; it stays on record, and a replacement is drawn from the same group.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sampling {
    /** Samples table. */
    public const TABLE = 'report_evidencereporter_samp';
    /** Sampled judgements table. */
    public const ITEMS = 'report_evidencereporter_sitem';
    /** Largest sample. */
    public const MAX_SIZE = 200;
    /** Ways to spread a sample. */
    public const SPREADS = ['none', 'assessor', 'result', 'markband', 'course'];
    /** Mark bands for spreading: lowest percentage in the band => name, highest band first. */
    public const MARK_BANDS = [80 => 'band80', 65 => 'band65', 50 => 'band50', 0 => 'band0'];

    /**
     * Filters with defaults and types.
     *
     * @param array $filters
     * @return array
     */
    public static function normalise_filters(array $filters): array {
        $mark = fn($value) => ($value === '' || $value === null) ? null : max(0.0, min(100.0, (float) $value));
        $spread = in_array($filters['spread'] ?? 'none', self::SPREADS, true) ? ($filters['spread'] ?? 'none') : 'none';
        $result = in_array($filters['result'] ?? 'any', ['any', 'proficient', 'notproficient'], true)
            ? ($filters['result'] ?? 'any') : 'any';
        return [
            'courseid' => (int) ($filters['courseid'] ?? 0),
            'categoryid' => (int) ($filters['categoryid'] ?? 0),
            'subcategories' => (int) !empty($filters['subcategories'] ?? 1),
            'frameworkid' => (int) ($filters['frameworkid'] ?? 0),
            'unitid' => (int) ($filters['unitid'] ?? 0),
            'from' => (int) ($filters['from'] ?? 0),
            'to' => (int) ($filters['to'] ?? 0),
            'result' => $result,
            'markmin' => $mark($filters['markmin'] ?? null),
            'markmax' => $mark($filters['markmax'] ?? null),
            'spread' => $spread,
            'size' => max(1, min(self::MAX_SIZE, (int) ($filters['size'] ?? 10))),
            'pergroup' => (int) ($spread !== 'none' && !empty($filters['pergroup'])),
        ];
    }

    /**
     * The courses the filters cover.
     *
     * @param array $filters
     * @return int[]
     */
    public static function get_courseids(array $filters): array {
        if ($filters['courseid']) {
            return [$filters['courseid']];
        }
        if ($filters['categoryid']) {
            return bulk::get_source_courseids('category', $filters['categoryid'], (bool) $filters['subcategories']);
        }
        return [];
    }

    /**
     * The eligible judgements, keyed "userid-unitid-courseid" and sorted by key, so a draw from them is reproducible.
     *
     * @param array $filters Normalised
     * @return stdClass[] (key, userid, competencyid, courseid, assessorid, result, proficiency, mark, judged)
     */
    public static function get_population(array $filters): array {
        global $DB;
        $courseids = self::get_courseids($filters);
        if (!$courseids) {
            return [];
        }

        // Units: the one chosen, a framework's, or those of every framework used in the courses.
        [$cinsql, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'pc');
        if ($filters['unitid']) {
            $unitids = [$filters['unitid']];
        } else {
            $frameworkids = $filters['frameworkid'] ? [$filters['frameworkid']] : $DB->get_fieldset_sql(
                "SELECT DISTINCT c.competencyframeworkid FROM {competency_coursecomp} cc
                   JOIN {competency} c ON c.id = cc.competencyid
                  WHERE cc.courseid {$cinsql}",
                $cparams
            );
            $unitids = [];
            foreach ($frameworkids as $frameworkid) {
                $unitids = array_merge($unitids, array_keys(scope::framework_units((int) $frameworkid)));
            }
        }
        $unitids = array_map('intval', $unitids);
        if (!$unitids) {
            return [];
        }

        // Each unit and everything below it.
        [$uinsql, $uparams] = $DB->get_in_or_equal($unitids, SQL_PARAMS_NAMED, 'pu');
        $likes = [];
        $params = $uparams;
        foreach ($unitids as $i => $unitid) {
            $likes[] = $DB->sql_like('path', ":pl{$i}");
            $params["pl{$i}"] = '%/' . $unitid . '/%';
        }
        $comps = $DB->get_records_select(
            'competency',
            "id {$uinsql} OR " . implode(' OR ', $likes),
            $params,
            '',
            'id, path'
        );
        $unitof = [];
        $unitset = array_flip($unitids);
        foreach ($comps as $comp) {
            if (isset($unitset[(int) $comp->id])) {
                $unitof[(int) $comp->id] = (int) $comp->id;
                continue;
            }
            foreach (array_filter(explode('/', (string) $comp->path)) as $ancestor) {
                if (isset($unitset[(int) $ancestor])) {
                    $unitof[(int) $comp->id] = (int) $ancestor;
                }
            }
        }
        if (!$unitof) {
            return [];
        }

        // Course-level ratings of the units or anything below them.
        [$compsql, $compparams] = $DB->get_in_or_equal(array_keys($unitof), SQL_PARAMS_NAMED, 'pk');
        $rs = $DB->get_recordset_sql(
            "SELECT ucc.id, ucc.userid, ucc.competencyid, ucc.courseid, ucc.proficiency, ucc.grade, ucc.timemodified
               FROM {competency_usercompcourse} ucc
               JOIN {user} u ON u.id = ucc.userid AND u.deleted = 0
              WHERE ucc.courseid {$cinsql} AND ucc.competencyid {$compsql} AND ucc.grade IS NOT NULL
           ORDER BY ucc.id",
            $cparams + $compparams
        );
        $pairs = [];
        foreach ($rs as $row) {
            $unitid = $unitof[(int) $row->competencyid];
            $key = $row->userid . '-' . $unitid . '-' . $row->courseid;
            $pair = $pairs[$key] ?? (object) ['key' => $key, 'userid' => (int) $row->userid, 'competencyid' => $unitid,
                'courseid' => (int) $row->courseid, 'assessorid' => 0, 'grade' => null, 'proficiency' => null,
                'mark' => null, 'judged' => 0, 'result' => ''];
            $pair->judged = max($pair->judged, (int) $row->timemodified);
            if ((int) $row->competencyid === $unitid) {
                $pair->grade = (int) $row->grade;
                $pair->proficiency = (int) $row->proficiency;
            }
            $pairs[$key] = $pair;
        }
        $rs->close();
        if (!$pairs) {
            return [];
        }

        self::add_results($pairs);
        self::add_assessors($pairs, $courseids);
        self::add_marks($pairs, $courseids, $unitof);

        // Filters on the judgement.
        $to = $filters['to'] ? $filters['to'] + DAYSECS - 1 : 0;
        $pairs = array_filter($pairs, function (stdClass $pair) use ($filters, $to): bool {
            if (($filters['from'] && $pair->judged < $filters['from']) || ($to && $pair->judged > $to)) {
                return false;
            }
            if ($filters['result'] === 'proficient' && $pair->proficiency !== 1) {
                return false;
            }
            if ($filters['result'] === 'notproficient' && $pair->proficiency !== 0) {
                return false;
            }
            if ($filters['markmin'] !== null || $filters['markmax'] !== null) {
                if (
                    $pair->mark === null || ($filters['markmin'] !== null && $pair->mark < $filters['markmin'])
                        || ($filters['markmax'] !== null && $pair->mark > $filters['markmax'])
                ) {
                    return false;
                }
            }
            return true;
        });
        ksort($pairs, SORT_NATURAL);
        return $pairs;
    }

    /**
     * Result labels from each unit's scale, e.g. "Competent", or "Not yet rated" when there is no rating ratings.
     *
     * @param stdClass[] $pairs
     */
    private static function add_results(array $pairs): void {
        $scales = [];
        foreach ($pairs as $pair) {
            if ($pair->grade === null) {
                $pair->result = get_string('samplenotrated', 'report_evidencereporter');
                continue;
            }
            if (!isset($scales[$pair->competencyid])) {
                try {
                    $scale = \core_competency\competency::get_record(['id' => $pair->competencyid])->get_scale();
                    $scale->load_items();
                    $scales[$pair->competencyid] = $scale->scale_items;
                } catch (\Throwable $e) {
                    $scales[$pair->competencyid] = [];
                }
            }
            $pair->result = (string) ($scales[$pair->competencyid][$pair->grade - 1] ?? $pair->grade);
        }
    }

    /**
     * Who made each judgement: the latest rating of the unit in the course, otherwise the latest anywhere.
     *
     * @param stdClass[] $pairs
     * @param int[] $courseids
     */
    private static function add_assessors(array $pairs, array $courseids): void {
        global $DB;
        $unitids = array_unique(array_map(fn($p) => $p->competencyid, $pairs));
        [$insql, $params] = $DB->get_in_or_equal($unitids, SQL_PARAMS_NAMED, 'au');
        $coursebyctx = [];
        foreach ($courseids as $courseid) {
            $coursebyctx[\context_course::instance($courseid)->id] = $courseid;
        }
        $rs = $DB->get_recordset_sql(
            "SELECT ev.id, uc.userid, uc.competencyid, ev.actionuserid, ev.contextid
               FROM {competency_evidence} ev
               JOIN {competency_usercomp} uc ON uc.id = ev.usercompetencyid
              WHERE ev.grade IS NOT NULL AND ev.actionuserid IS NOT NULL AND uc.competencyid {$insql}
           ORDER BY ev.timecreated, ev.id",
            $params
        );
        $anywhere = [];
        foreach ($rs as $ev) {
            $courseid = $coursebyctx[(int) $ev->contextid] ?? null;
            if ($courseid !== null) {
                $key = $ev->userid . '-' . $ev->competencyid . '-' . $courseid;
                if (isset($pairs[$key])) {
                    $pairs[$key]->assessorid = (int) $ev->actionuserid;
                }
            }
            $anywhere[$ev->userid . '-' . $ev->competencyid] = (int) $ev->actionuserid;
        }
        $rs->close();
        foreach ($pairs as $pair) {
            if (!$pair->assessorid) {
                $pair->assessorid = $anywhere[$pair->userid . '-' . $pair->competencyid] ?? 0;
            }
        }
    }

    /**
     * Each judgement's mark: the average percentage across the course's activities linked to the unit or below it.
     *
     * @param stdClass[] $pairs
     * @param int[] $courseids
     * @param int[] $unitof competencyid => unit ID
     */
    private static function add_marks(array $pairs, array $courseids, array $unitof): void {
        global $DB;
        [$cinsql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'mc');
        [$kinsql, $kparams] = $DB->get_in_or_equal(array_keys($unitof), SQL_PARAMS_NAMED, 'mk');
        $rs = $DB->get_recordset_sql(
            "SELECT gg.id, gg.userid, mc.competencyid, cm.course, gg.finalgrade, gi.grademin, gi.grademax
               FROM {competency_modulecomp} mc
               JOIN {course_modules} cm ON cm.id = mc.cmid
               JOIN {modules} m ON m.id = cm.module
               JOIN {grade_items} gi ON gi.courseid = cm.course AND gi.itemtype = 'mod' AND gi.itemmodule = m.name
                                    AND gi.iteminstance = cm.instance
                                    AND gi.itemnumber = " . activity_grade::itemnumber_sql('m.name', 'cm.instance', 'smgi') . "
               JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.finalgrade IS NOT NULL
              WHERE cm.course {$cinsql} AND mc.competencyid {$kinsql} AND gi.grademax > gi.grademin",
            $params + $kparams
        );
        $sums = [];
        foreach ($rs as $row) {
            $key = $row->userid . '-' . $unitof[(int) $row->competencyid] . '-' . $row->course;
            if (!isset($pairs[$key])) {
                continue;
            }
            // Each graded activity counts once, even when linked to several criteria of the unit.
            $sums[$key][$row->id] = ((float) $row->finalgrade - (float) $row->grademin)
                / ((float) $row->grademax - (float) $row->grademin) * 100;
        }
        $rs->close();
        foreach ($sums as $key => $values) {
            $pairs[$key]->mark = array_sum($values) / count($values);
        }
    }

    /**
     * The group a judgement belongs to when spreading.
     *
     * @param string $spread
     * @param stdClass $pair
     * @return string
     */
    public static function group_label(string $spread, stdClass $pair): string {
        global $DB;
        switch ($spread) {
            case 'assessor':
                $user = $pair->assessorid ? \core_user::get_user($pair->assessorid) : null;
                return $user ? fullname($user) : get_string('samplenoassessor', 'report_evidencereporter');
            case 'result':
                return $pair->result;
            case 'markband':
                if ($pair->mark === null) {
                    return get_string('samplenomark', 'report_evidencereporter');
                }
                foreach (self::MARK_BANDS as $min => $name) {
                    if ($pair->mark >= $min) {
                        return get_string('sample' . $name, 'report_evidencereporter');
                    }
                }
                return '';
            case 'course':
                return format_string((string) $DB->get_field('course', 'shortname', ['id' => $pair->courseid]));
            default:
                return '';
        }
    }

    /**
     * Pick up to $count keys at random (Fisher-Yates on the seeded generator), in a stable order.
     *
     * @param string[] $keys
     * @param int $count
     * @return string[]
     */
    private static function pick(array $keys, int $count): array {
        $keys = array_values($keys);
        for ($i = count($keys) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$keys[$i], $keys[$j]] = [$keys[$j], $keys[$i]];
        }
        return array_slice($keys, 0, $count);
    }

    /**
     * Draw the sample from the population: at random, or spread across groups (a set number from each, or the total
     * shared across them in turn).
     *
     * @param stdClass[] $population
     * @param array $filters
     * @param int $seed
     * @return array key => group label
     */
    public static function choose(array $population, array $filters, int $seed): array {
        mt_srand($seed, MT_RAND_MT19937);
        if ($filters['spread'] === 'none') {
            return array_fill_keys(self::pick(array_keys($population), $filters['size']), '');
        }
        $groups = [];
        foreach ($population as $key => $pair) {
            $groups[self::group_label($filters['spread'], $pair)][] = $key;
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        $chosen = [];
        if ($filters['pergroup']) {
            foreach ($groups as $label => $keys) {
                foreach (self::pick($keys, $filters['size']) as $key) {
                    $chosen[$key] = (string) $label;
                }
            }
            return $chosen;
        }
        // Share the total across the groups in turn, so every group is represented before any gets a second.
        $queues = [];
        foreach ($groups as $label => $keys) {
            $queues[(string) $label] = self::pick($keys, count($keys));
        }
        while (count($chosen) < $filters['size'] && $queues) {
            foreach (array_keys($queues) as $label) {
                if (count($chosen) >= $filters['size']) {
                    break;
                }
                $key = array_shift($queues[$label]);
                if ($key === null) {
                    unset($queues[$label]);
                    continue;
                }
                $chosen[$key] = $label;
            }
        }
        return $chosen;
    }

    /**
     * Draw and save a sample.
     *
     * @param string $name
     * @param array $filters
     * @param int|null $seed A draw code to reproduce, or null for a new one
     * @return int Sample ID
     */
    public static function draw(string $name, array $filters, ?int $seed = null): int {
        global $DB, $USER;
        $filters = self::normalise_filters($filters);
        $seed = $seed ?: random_int(100000, 999999999);
        $population = self::get_population($filters);
        $chosen = self::choose($population, $filters, $seed);
        mt_srand();
        $now = time();
        $sampleid = $DB->insert_record(self::TABLE, [
            'name' => $name !== '' ? $name : get_string(
                'sampledefaultname',
                'report_evidencereporter',
                dates::datetime($now)
            ),
            'filters' => json_encode($filters),
            'seed' => $seed,
            'population' => count($population),
            'populationhash' => sha1(implode(',', array_keys($population))),
            'createdby' => $USER->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        // In the order they were drawn, so the register reads the way the draw went.
        foreach ($chosen as $key => $label) {
            self::insert_item($sampleid, $population[$key], $label, 'drawn', 0);
        }
        \report_evidencereporter\event\sample_created::create([
            'context' => \context_system::instance(),
            'objectid' => $sampleid,
            'other' => ['population' => count($population), 'size' => count($chosen)],
        ])->trigger();
        return $sampleid;
    }

    /**
     * Save a sampled judgement.
     *
     * @param int $sampleid
     * @param stdClass $pair
     * @param string $group
     * @param string $reason
     * @param int $replacesid
     * @return int
     */
    private static function insert_item(int $sampleid, stdClass $pair, string $group, string $reason, int $replacesid): int {
        global $DB;
        return $DB->insert_record(self::ITEMS, [
            'sampleid' => $sampleid,
            'userid' => $pair->userid,
            'competencyid' => $pair->competencyid,
            'courseid' => $pair->courseid,
            'assessorid' => $pair->assessorid,
            'result' => \core_text::substr($pair->result, 0, 255),
            'proficiency' => $pair->proficiency,
            'mark' => $pair->mark,
            'judged' => $pair->judged,
            'groupkey' => \core_text::substr($group, 0, 255),
            'reason' => $reason,
            'replacesid' => $replacesid,
            'removed' => 0,
            'timecreated' => time(),
        ]);
    }

    /**
     * Swap a judgement out of a sample, with a reason, and draw a replacement from the same group if there is one (any
     * group otherwise). The swapped-out judgement stays on record.
     *
     * @param int $itemid
     * @param string $reason
     * @return stdClass|null The replacement, or null when none is left to draw
     */
    public static function swap(int $itemid, string $reason): ?stdClass {
        global $DB, $USER;
        $item = $DB->get_record(self::ITEMS, ['id' => $itemid, 'removed' => 0], '*', MUST_EXIST);
        $sample = $DB->get_record(self::TABLE, ['id' => $item->sampleid], '*', MUST_EXIST);
        $filters = self::normalise_filters(json_decode($sample->filters, true) ?: []);
        $transaction = $DB->start_delegated_transaction();
        $DB->update_record(self::ITEMS, ['id' => $item->id, 'removed' => 1, 'removedreason' => $reason,
            'removedby' => $USER->id, 'timeremoved' => time()]);

        // Anything not already in the sample (or swapped out of it), preferably from the same group.
        $used = [];
        foreach ($DB->get_records(self::ITEMS, ['sampleid' => $sample->id], '', 'id, userid, competencyid, courseid') as $i) {
            $used[$i->userid . '-' . $i->competencyid . '-' . $i->courseid] = true;
        }
        $candidates = array_diff_key(self::get_population($filters), $used);
        $same = array_filter($candidates, fn($pair) => self::group_label($filters['spread'], $pair) === $item->groupkey);
        $pool = $same ?: $candidates;
        $replacement = null;
        if ($pool) {
            // Seeded from the draw code and how many judgements the sample already has, so replaying the same swaps in
            // the same order against the same population picks the same replacements.
            $keys = array_keys($pool);
            mt_srand((int) $sample->seed + count($used) * 7919, MT_RAND_MT19937);
            $pair = $pool[$keys[mt_rand(0, count($keys) - 1)]];
            mt_srand();
            $label = self::group_label($filters['spread'], $pair);
            $newid = self::insert_item($sample->id, $pair, $label, 'replacement', $item->id);
            $replacement = $DB->get_record(self::ITEMS, ['id' => $newid]);
        }
        $DB->set_field(self::TABLE, 'timemodified', time(), ['id' => $sample->id]);
        $transaction->allow_commit();

        \report_evidencereporter\event\sample_item_swapped::create([
            'context' => \context_system::instance(),
            'objectid' => $item->id,
            'relateduserid' => $item->userid,
            'other' => ['sampleid' => (int) $sample->id, 'replacementid' => $replacement ? (int) $replacement->id : 0],
        ])->trigger();
        return $replacement;
    }

    /**
     * A sample's judgements, in the order they were added.
     *
     * @param int $sampleid
     * @param bool $includeremoved
     * @return stdClass[]
     */
    public static function get_items(int $sampleid, bool $includeremoved = true): array {
        global $DB;
        $conditions = ['sampleid' => $sampleid];
        if (!$includeremoved) {
            $conditions['removed'] = 0;
        }
        return array_values($DB->get_records(self::ITEMS, $conditions, 'id'));
    }

    /**
     * Saved samples, newest first.
     *
     * @return stdClass[]
     */
    public static function get_samples(): array {
        global $DB;
        $sql = "SELECT s.*,
                       (SELECT COUNT(1) FROM {" . self::ITEMS . "} i WHERE i.sampleid = s.id AND i.removed = 0) AS active,
                       (SELECT COUNT(1) FROM {" . self::ITEMS . "} i WHERE i.sampleid = s.id AND i.removed = 1) AS swapped
                  FROM {" . self::TABLE . "} s
              ORDER BY s.timecreated DESC, s.id DESC";
        return array_values($DB->get_records_sql($sql));
    }

    /**
     * The filters in words, for the sampling record.
     *
     * @param array $filters Normalised
     * @return array label => value
     */
    public static function describe_filters(array $filters): array {
        global $DB;
        $rows = [];
        $str = fn($id, $a = null) => get_string($id, 'report_evidencereporter', $a);
        if ($filters['courseid']) {
            $rows[get_string('course')] = format_string((string) $DB->get_field(
                'course',
                'fullname',
                ['id' => $filters['courseid']]
            ));
        } else if ($filters['categoryid']) {
            $rows[get_string('category')] = bulk::get_source_name(
                'category',
                $filters['categoryid'],
                (bool) $filters['subcategories']
            );
        }
        if ($filters['unitid']) {
            $unit = $DB->get_record('competency', ['id' => $filters['unitid']], 'id, shortname, idnumber');
            $rows[terms::get('unit')] = $unit ? scope::unit_label($unit) : '-';
        } else if ($filters['frameworkid']) {
            $rows[terms::get('framework')] = format_string((string) $DB->get_field(
                'competency_framework',
                'shortname',
                ['id' => $filters['frameworkid']]
            ));
        } else {
            $rows[terms::get('units')] = $str('sampleallunits');
        }
        $date = fn(int $time) => dates::date($time);
        if ($filters['from'] || $filters['to']) {
            $rows[$str('samplejudged')] = ($filters['from'] ? $date($filters['from']) : '…') . ' – '
                . ($filters['to'] ? $date($filters['to']) : '…');
        }
        $rows[$str('sampleresult')] = $str('sampleresult_' . $filters['result']);
        if ($filters['markmin'] !== null || $filters['markmax'] !== null) {
            $rows[$str('samplemark')] = format_float($filters['markmin'] ?? 0, -1) . '% – '
                . format_float($filters['markmax'] ?? 100, -1) . '%';
        }
        $rows[$str('samplespread')] = $str('samplespread_' . $filters['spread']);
        $rows[$str('samplesize')] = $filters['pergroup']
            ? $str('samplesizepergroup', $filters['size']) : (string) $filters['size'];
        return $rows;
    }

    /**
     * The sample register: one row per judgement, swapped-out ones included with their reason, and blank columns for
     * the validator's findings.
     *
     * @param int $sampleid
     * @param array $redact Redaction flags, as for evidence packs
     * @param array $fileforitem itemid => file name in the download
     * @return string CSV
     */
    public static function register_csv(int $sampleid, array $redact = [], array $fileforitem = []): string {
        global $DB;
        $str = fn($id, $a = null) => get_string($id, 'report_evidencereporter', $a);
        $rows = [['#', terms::get('learner'), get_string('username'), get_string('idnumber'), terms::get('unit'),
            get_string('course'), terms::get('assessor'), $str('sampleresult'), $str('samplemark'), $str('samplejudged'),
            $str('samplegroup'), $str('samplehow'), $str('samplestatus'), $str('sampleadjustments'), $str('samplelln'),
            $str('bulkfile'), $str('samplefindings'), $str('sampleconfirmed')]];
        $items = self::get_items($sampleid);
        $number = [];
        foreach ($items as $n => $item) {
            $number[(int) $item->id] = $n + 1;
        }
        $date = fn(int $time) => $time ? dates::date($time) : '';
        $units = [];
        foreach ($items as $item) {
            $learner = \core_user::get_user($item->userid) ?: (object) ['id' => $item->userid, 'firstname' => '',
                'lastname' => '', 'username' => '', 'idnumber' => ''];
            $identity = identity::from_flags($learner, $redact);
            $units[$item->competencyid] ??= $DB->get_record(
                'competency',
                ['id' => $item->competencyid],
                'id, shortname, idnumber'
            ) ?: null;
            $assessor = $item->assessorid ? \core_user::get_user($item->assessorid) : null;
            $remover = $item->removedby ? \core_user::get_user($item->removedby) : null;
            $how = $item->reason === 'replacement'
                ? $str('samplehowreplacement', $number[(int) $item->replacesid] ?? '?') : $str('samplehowdrawn');
            $status = $item->removed
                ? $str('samplestatusswapped', ['reason' => (string) $item->removedreason,
                    'by' => $remover ? $identity->staff(fullname($remover)) : '', 'date' => $date((int) $item->timeremoved)])
                : $str('samplestatusin');
            $rows[] = [
                $number[(int) $item->id],
                $identity->name(),
                $identity->username(),
                $identity->idnumber(),
                $units[$item->competencyid] ? scope::unit_label($units[$item->competencyid]) : '',
                format_string((string) $DB->get_field('course', 'shortname', ['id' => $item->courseid])),
                $assessor ? $identity->staff(fullname($assessor)) : '',
                $item->result,
                $item->mark === null ? '' : format_float((float) $item->mark, 1) . '%',
                $date((int) $item->judged),
                $item->groupkey,
                $how,
                $status,
                reasonable_adjustments::for_judgement(
                    (int) $item->userid,
                    (int) $item->courseid,
                    (int) $item->competencyid,
                    $identity
                ),
                declarations::for_judgement(
                    (int) $item->userid,
                    (int) $item->courseid,
                    (int) $item->competencyid,
                    $identity
                )->lln,
                $fileforitem[(int) $item->id] ?? '',
                '',
                '',
            ];
        }
        $csv = '';
        foreach ($rows as $line) {
            $csv .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string) $v) . '"', $line)) . "\r\n";
        }
        return $csv;
    }

    /**
     * The sampling record: who drew the sample, when, how, from how many, its draw code, and every swap.
     *
     * @param int $sampleid
     * @param array $redact
     * @return string Plain text
     */
    public static function record_text(int $sampleid, array $redact = []): string {
        global $DB;
        $sample = $DB->get_record(self::TABLE, ['id' => $sampleid], '*', MUST_EXIST);
        $filters = self::normalise_filters(json_decode($sample->filters, true) ?: []);
        $identity = identity::from_flags((object) ['id' => 0], $redact);
        $creator = \core_user::get_user($sample->createdby);
        $str = fn($id, $a = null) => get_string($id, 'report_evidencereporter', $a);
        $items = self::get_items($sampleid);
        $active = array_filter($items, fn($i) => !$i->removed);
        $lines = [
            $str('samplerecord') . ': ' . format_string($sample->name),
            str_repeat('=', 40),
            $str('sampledrawnby', ['by' => $creator ? $identity->staff(fullname($creator)) : '-',
                'date' => dates::datetime((int) $sample->timecreated, 'strftimedaydatetime')]),
            '',
        ];
        foreach (self::describe_filters($filters) as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }
        $lines[] = '';
        $lines[] = $str('samplepopulation', ['size' => count($active), 'population' => (int) $sample->population]);
        $lines[] = $str('sampledrawcode', ['code' => $sample->seed, 'check' => substr($sample->populationhash, 0, 8)]);
        $swapped = array_filter($items, fn($i) => $i->removed);
        if ($swapped) {
            $lines[] = '';
            $lines[] = $str('sampleswaps', count($swapped));
            $number = [];
            foreach ($items as $n => $item) {
                $number[(int) $item->id] = $n + 1;
            }
            foreach ($swapped as $item) {
                $remover = $item->removedby ? \core_user::get_user($item->removedby) : null;
                $replacement = array_values(array_filter($items, fn($i) => (int) $i->replacesid === (int) $item->id));
                $lines[] = '- ' . $str('sampleswapline', [
                    'number' => $number[(int) $item->id],
                    'reason' => (string) $item->removedreason,
                    'by' => $remover ? $identity->staff(fullname($remover)) : '-',
                    'date' => dates::datetime((int) $item->timeremoved, 'strftimedaydatetime'),
                    'replacement' => $replacement ? '#' . $number[(int) $replacement[0]->id] : $str('samplenoreplacement'),
                ]);
            }
        }
        $lines[] = '';
        $lines[] = $str('samplereproduce');
        return implode("\r\n", $lines) . "\r\n";
    }
}
