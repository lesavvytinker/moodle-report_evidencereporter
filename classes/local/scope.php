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

use core_competency\plan;
use stdClass;

/**
 * What a summary or evidence pack covers: one learning plan, the learner's active plans, a competency framework, or
 * one unit.
 *
 * Plans hold units; evidence usually sits on the elements, criteria and evidence items beneath them, so a plan scope
 * covers each plan competency and everything below it. A unit scope covers one unit and everything below it, e.g. when
 * an auditor samples one unit from a clustered course whose tasks also cover other units.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scope {
    /** @var string One competency framework. */
    public const FRAMEWORK = 'framework';

    /** @var string One learning plan. */
    public const PLAN = 'plan';

    /** @var string All of the learner's active learning plans. */
    public const ACTIVE_PLANS = 'plans';

    /** @var string One unit: a competency and everything below it. */
    public const UNIT = 'unit';

    /** @var string The units the learner has evidence in: the default for learners without a learning plan. */
    public const WITH_EVIDENCE = 'evidence';

    /** @var stdClass[]|null Ordered competencies, cached. */
    private ?array $competencies = null;

    /**
     * Constructor: use the static factories.
     *
     * @param string $type
     * @param int $userid
     * @param int $frameworkid For a framework scope
     * @param plan[] $plans For plan scopes
     * @param int $competencyid For a unit scope
     * @param stdClass[] $units For a units-with-evidence scope, from {@see get_units_with_evidence()}
     */
    private function __construct(
        /** @var string Scope type. */
        public readonly string $type,
        /** @var int The learner. */
        public readonly int $userid,
        /** @var int Framework ID, for a framework scope. */
        public readonly int $frameworkid = 0,
        /** @var plan[] Plans, for plan scopes. */
        public readonly array $plans = [],
        /** @var int The unit's competency ID, for a unit scope. */
        public readonly int $competencyid = 0,
        /** @var stdClass[] Units, for a units-with-evidence scope. */
        public readonly array $units = []
    ) {
    }

    /**
     * A competency framework.
     *
     * @param int $userid
     * @param int $frameworkid
     * @return self
     */
    public static function for_framework(int $userid, int $frameworkid): self {
        return new self(self::FRAMEWORK, $userid, $frameworkid);
    }

    /**
     * One unit: a competency and everything below it.
     *
     * @param int $userid
     * @param int $competencyid
     * @return self
     */
    public static function for_unit(int $userid, int $competencyid): self {
        $frameworkid = (int) \core_competency\competency::get_record(['id' => $competencyid], MUST_EXIST)
            ->get('competencyframeworkid');
        return new self(self::UNIT, $userid, $frameworkid, [], $competencyid);
    }

    /**
     * The units the learner has evidence in (or assessed attempts linked to), in framework order. Works without
     * learning plans: it's what a plan would hold, taken from what the learner actually did.
     *
     * @param int $userid
     * @param array $frameworks From {@see summary::get_frameworks()}
     * @return stdClass[] competencyid => (id, shortname, idnumber, frameworkid)
     */
    public static function get_units_with_evidence(int $userid, array $frameworks): array {
        global $DB;
        $assessed = summary::get_assessed_competencyids($userid);
        if (!$assessed) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($assessed);
        $paths = $DB->get_records_select_menu('competency', "id {$insql}", $params, '', 'id, path');
        $units = [];
        foreach ($frameworks as $framework) {
            foreach (self::framework_units((int) $framework->id) as $unit) {
                foreach ($paths as $id => $path) {
                    if ((int) $id === (int) $unit->id || str_contains($path, '/' . $unit->id . '/')) {
                        $units[(int) $unit->id] = (object) ['id' => (int) $unit->id, 'shortname' => $unit->shortname,
                            'idnumber' => $unit->idnumber, 'frameworkid' => (int) $unit->competencyframeworkid];
                        break;
                    }
                }
            }
        }
        return $units;
    }

    /**
     * The units the learner has evidence in.
     *
     * @param int $userid
     * @param array $frameworks From {@see summary::get_frameworks()}
     * @return self|null Null when there are none
     */
    public static function for_units_with_evidence(int $userid, array $frameworks): ?self {
        $units = self::get_units_with_evidence($userid, $frameworks);
        return $units ? new self(self::WITH_EVIDENCE, $userid, 0, [], 0, $units) : null;
    }

    /**
     * The units a learner's documents can cover: each competency in their learning plans, then the top level of each
     * framework they have evidence in. Where a framework has a single top-level competency (e.g. the qualification),
     * its children are the units.
     *
     * @param int $userid
     * @param array $frameworks From {@see summary::get_frameworks()}
     * @return stdClass[] competencyid => (id, shortname, idnumber, frameworkid)
     */
    public static function get_units(int $userid, array $frameworks): array {
        $units = [];
        $add = function (stdClass $comp) use (&$units): void {
            $units[(int) $comp->id] ??= (object) ['id' => (int) $comp->id, 'shortname' => $comp->shortname,
                'idnumber' => $comp->idnumber, 'frameworkid' => (int) $comp->competencyframeworkid];
        };
        foreach (self::get_plans($userid) as $plan) {
            foreach ($plan->get_competencies() as $planned) {
                $add($planned->to_record());
            }
        }
        foreach ($frameworks as $framework) {
            array_map($add, self::framework_units((int) $framework->id));
        }
        return $units;
    }

    /**
     * A framework's units: its top-level competencies, or the children of its only top-level competency (e.g. when the
     * qualification itself sits at the top).
     *
     * @param int $frameworkid
     * @return stdClass[] id, shortname, idnumber, competencyframeworkid
     */
    public static function framework_units(int $frameworkid): array {
        global $DB;
        $fields = 'id, shortname, idnumber, competencyframeworkid, sortorder';
        $top = $DB->get_records(
            'competency',
            ['competencyframeworkid' => $frameworkid, 'parentid' => 0],
            'sortorder, id',
            $fields
        );
        if (count($top) === 1) {
            $children = $DB->get_records('competency', ['parentid' => reset($top)->id], 'sortorder, id', $fields);
            $top = $children ?: $top;
        }
        return $top;
    }

    /**
     * Label for a unit: its short name, with its ID number first when the name doesn't already start with it.
     *
     * @param stdClass $unit With shortname and idnumber
     * @return string
     */
    public static function unit_label(stdClass $unit): string {
        $name = format_string($unit->shortname, true, ['context' => \context_system::instance()]);
        $idnumber = (string) $unit->idnumber;
        return $idnumber !== '' && !str_starts_with($name, $idnumber) ? $idnumber . ' ' . $name : $name;
    }

    /**
     * A framework's name, for group headings.
     *
     * @param int $frameworkid
     * @return string
     */
    private static function framework_name(int $frameworkid): string {
        $framework = \core_competency\competency_framework::get_record(['id' => $frameworkid]);
        return $framework ? format_string($framework->get('shortname'), true, ['context' => \context_system::instance()]) : '';
    }

    /**
     * One learning plan.
     *
     * @param plan $plan
     * @return self
     */
    public static function for_plan(plan $plan): self {
        return new self(self::PLAN, (int) $plan->get('userid'), 0, [$plan]);
    }

    /**
     * Several plans together (normally the learner's active plans, e.g. core units plus electives).
     *
     * @param int $userid
     * @param plan[] $plans
     * @return self
     */
    public static function for_plans(int $userid, array $plans): self {
        return count($plans) === 1 ? self::for_plan(reset($plans)) : new self(self::ACTIVE_PLANS, $userid, 0, array_values($plans));
    }

    /**
     * The learner's active and completed plans that the current user may see, newest first. Draft plans are left out:
     * they haven't been agreed yet.
     *
     * @param int $userid
     * @return plan[]
     */
    public static function get_plans(int $userid): array {
        if (!plan::can_read_user($userid)) {
            return [];
        }
        $plans = [];
        foreach (plan::get_records(['userid' => $userid], 'timemodified', 'DESC') as $plan) {
            if (in_array((int) $plan->get('status'), [plan::STATUS_ACTIVE, plan::STATUS_COMPLETE], true)) {
                $plans[] = $plan;
            }
        }
        // Most recently changed first; plans changed in the same second in the order they were made.
        usort($plans, fn(plan $a, plan $b) => [(int) $b->get('timemodified'), (int) $a->get('id')]
            <=> [(int) $a->get('timemodified'), (int) $b->get('id')]);
        return $plans;
    }

    /**
     * The learner's active plans that the current user may see.
     *
     * @param int $userid
     * @return plan[]
     */
    public static function get_active_plans(int $userid): array {
        return array_values(array_filter(
            self::get_plans($userid),
            fn(plan $plan) => (int) $plan->get('status') === plan::STATUS_ACTIVE
        ));
    }

    /**
     * The default: all active plans; otherwise the most recent plan; otherwise the framework, if there's only one.
     *
     * @param int $userid
     * @param array $frameworks From {@see summary::get_frameworks()}
     * @return self|null Null when the viewer must choose
     */
    public static function get_default(int $userid, array $frameworks): ?self {
        if ($active = self::get_active_plans($userid)) {
            return self::for_plans($userid, $active);
        }
        if ($plans = self::get_plans($userid)) {
            return self::for_plan(reset($plans));
        }
        if ($withevidence = self::for_units_with_evidence($userid, $frameworks)) {
            return $withevidence;
        }
        if (count($frameworks) === 1) {
            return self::for_framework($userid, (int) reset($frameworks)->id);
        }
        return null;
    }

    /**
     * Read a scope from page parameters, checking it belongs to the learner and the current user may see it.
     *
     * @param int $userid
     * @param string $type
     * @param int $planid
     * @param int $frameworkid
     * @param array $frameworks From {@see summary::get_frameworks()}
     * @param int $competencyid For a unit scope
     * @return self|null Null if nothing was asked for
     * @throws \moodle_exception If what was asked for isn't available
     */
    public static function from_params(
        int $userid,
        string $type,
        int $planid,
        int $frameworkid,
        array $frameworks,
        int $competencyid = 0
    ): ?self {
        if ($type === '' && $planid) {
            $type = self::PLAN;
        } else if ($type === '' && $frameworkid) {
            $type = self::FRAMEWORK;
        }
        switch ($type) {
            case self::PLAN:
                foreach (self::get_plans($userid) as $plan) {
                    if ((int) $plan->get('id') === $planid) {
                        return self::for_plan($plan);
                    }
                }
                break;
            case self::ACTIVE_PLANS:
                if ($active = self::get_active_plans($userid)) {
                    return self::for_plans($userid, $active);
                }
                break;
            case self::FRAMEWORK:
                if (isset($frameworks[$frameworkid])) {
                    return self::for_framework($userid, $frameworkid);
                }
                break;
            case self::UNIT:
                if (isset(self::get_units($userid, $frameworks)[$competencyid])) {
                    return self::for_unit($userid, $competencyid);
                }
                break;
            case self::WITH_EVIDENCE:
                if ($scope = self::for_units_with_evidence($userid, $frameworks)) {
                    return $scope;
                }
                break;
            default:
                return null;
        }
        throw new \moodle_exception('nopermissiontoviewevidence', 'report_evidencereporter');
    }

    /**
     * Page parameters for this scope.
     *
     * @return array
     */
    public function get_params(): array {
        return match ($this->type) {
            self::FRAMEWORK => ['scope' => self::FRAMEWORK, 'frameworkid' => $this->frameworkid],
            self::PLAN => ['scope' => self::PLAN, 'planid' => (int) $this->plans[0]->get('id')],
            self::UNIT => ['scope' => self::UNIT, 'competencyid' => $this->competencyid],
            self::WITH_EVIDENCE => ['scope' => self::WITH_EVIDENCE],
            default => ['scope' => self::ACTIVE_PLANS],
        };
    }

    /**
     * Data for events' other: scope, frameworkid (0 for plans), planid (single plan only).
     *
     * @return array
     */
    public function get_event_data(): array {
        return [
            'scope' => $this->type,
            'frameworkid' => $this->frameworkid,
            'planid' => $this->type === self::PLAN ? (int) $this->plans[0]->get('id') : 0,
            'competencyid' => $this->competencyid,
        ];
    }

    /**
     * Is this a learning plan scope?
     *
     * @return bool
     */
    public function is_plan(): bool {
        return in_array($this->type, [self::PLAN, self::ACTIVE_PLANS], true);
    }

    /**
     * The unit, for a unit scope.
     *
     * @return stdClass|null id, shortname, idnumber
     */
    private function get_unit(): ?stdClass {
        global $DB;
        return $this->type === self::UNIT
            ? $DB->get_record('competency', ['id' => $this->competencyid], 'id, shortname, idnumber') ?: null : null;
    }

    /**
     * Name for headings and file names.
     *
     * @return string
     */
    public function get_name(): string {
        $context = \context_system::instance();
        if ($this->type === self::FRAMEWORK) {
            $framework = \core_competency\competency_framework::get_record(['id' => $this->frameworkid]);
            return $framework ? format_string($framework->get('shortname'), true, ['context' => $context]) : '';
        }
        if ($this->type === self::PLAN) {
            return format_string($this->plans[0]->get('name'), true, ['context' => $context]);
        }
        if ($unit = $this->get_unit()) {
            return self::unit_label($unit);
        }
        if ($this->type === self::WITH_EVIDENCE) {
            return get_string('scopewithevidence', 'report_evidencereporter', terms::get('units'));
        }
        return get_string('scopeactiveplans', 'report_evidencereporter');
    }

    /**
     * Short label for file names: the framework ID number, or the plan name.
     *
     * @return string
     */
    public function get_file_label(): string {
        if ($this->type === self::FRAMEWORK) {
            $framework = \core_competency\competency_framework::get_record(['id' => $this->frameworkid]);
            return $framework ? ($framework->get('idnumber') ?: $framework->get('shortname')) : '';
        }
        if ($unit = $this->get_unit()) {
            return $unit->idnumber ?: $unit->shortname;
        }
        if ($this->type === self::WITH_EVIDENCE) {
            return $this->get_name();
        }
        return $this->type === self::PLAN ? $this->plans[0]->get('name')
            : get_string('scopeactiveplans', 'report_evidencereporter');
    }

    /**
     * Rows describing the scope on official documents: label => value (plain text).
     *
     * @return array
     */
    public function describe(): array {
        $context = \context_system::instance();
        if ($this->type === self::FRAMEWORK) {
            $framework = \core_competency\competency_framework::get_record(['id' => $this->frameworkid]);
            $name = $framework ? format_string($framework->get('shortname'), true, ['context' => $context])
                . ($framework->get('idnumber') ? ' (' . $framework->get('idnumber') . ')' : '') : '';
            return [terms::get('framework') => $name];
        }
        if ($this->type === self::WITH_EVIDENCE) {
            return [
                get_string('scopecovering', 'report_evidencereporter') => $this->get_name(),
                terms::get('units') => implode(', ', array_map(fn($u) => $u->idnumber ?: $u->shortname, $this->units)),
            ];
        }
        if ($unit = $this->get_unit()) {
            $framework = \core_competency\competency_framework::get_record(['id' => $this->frameworkid]);
            return array_filter([
                terms::get('unit') => self::unit_label($unit),
                terms::get('framework') => $framework
                    ? format_string($framework->get('shortname'), true, ['context' => $context]) : '',
            ]);
        }
        $rows = [];
        $datefmt = get_string('strftimedate', 'core_langconfig');
        foreach ($this->plans as $plan) {
            $parts = [$plan->get_statusname()];
            if ($template = $plan->get_template()) {
                $parts[] = get_string(
                    'scopetemplate',
                    'report_evidencereporter',
                    format_string($template->get('shortname'), true, ['context' => $context])
                );
            }
            if ((int) $plan->get('status') === plan::STATUS_COMPLETE) {
                $parts[] = get_string('scopecompleted', 'report_evidencereporter', userdate($plan->get('timemodified'), $datefmt));
            } else if ($plan->get('duedate')) {
                $parts[] = get_string('scopedue', 'report_evidencereporter', userdate($plan->get('duedate'), $datefmt));
            }
            $rows[] = format_string($plan->get('name'), true, ['context' => $context]) . ' (' . implode(', ', $parts) . ')';
        }
        return [get_string(count($rows) > 1 ? 'learningplans' : 'learningplan', 'report_evidencereporter') => implode('; ', $rows)];
    }

    /**
     * Competencies covered, in order: each with id, parentid, shortname, idnumber, depth and group (the plan's name
     * when several plans are covered, on the first competency of each plan).
     *
     * @return stdClass[]
     */
    public function get_competencies(): array {
        if ($this->competencies !== null) {
            return $this->competencies;
        }
        if ($this->type === self::FRAMEWORK) {
            return $this->competencies = self::framework_order($this->frameworkid);
        }
        if ($this->type === self::UNIT) {
            $unit = \core_competency\competency::get_record(['id' => $this->competencyid], MUST_EXIST);
            $prefix = $unit->get('path') . $unit->get('id') . '/';
            $result = [];
            $depth = null;
            foreach (self::framework_order($this->frameworkid) as $comp) {
                if ((int) $comp->id === $this->competencyid || str_starts_with($comp->path, $prefix)) {
                    $depth ??= $comp->depth;
                    $comp->depth -= $depth;
                    $result[] = $comp;
                }
            }
            return $this->competencies = $result;
        }

        if ($this->type === self::WITH_EVIDENCE) {
            $result = [];
            $frameworkids = array_unique(array_column($this->units, 'frameworkid'));
            foreach ($frameworkids as $frameworkid) {
                $first = true;
                $prefixes = [];
                foreach ($this->units as $unit) {
                    if ($unit->frameworkid === $frameworkid) {
                        $prefixes[$unit->id] = true;
                    }
                }
                $depth = null;
                foreach (self::framework_order($frameworkid) as $comp) {
                    $under = isset($prefixes[(int) $comp->id]);
                    foreach (array_keys($prefixes) as $unitid) {
                        $under = $under || str_contains($comp->path, '/' . $unitid . '/');
                    }
                    if (!$under) {
                        continue;
                    }
                    if (isset($prefixes[(int) $comp->id])) {
                        $depth = $comp->depth;
                    }
                    $copy = clone $comp;
                    $copy->depth = $comp->depth - (int) $depth;
                    $copy->group = $first && count($frameworkids) > 1 ? self::framework_name($frameworkid) : '';
                    $first = false;
                    $result[] = $copy;
                }
            }
            return $this->competencies = $result;
        }

        $frameworkorders = [];
        $seen = [];
        $result = [];
        foreach ($this->plans as $plan) {
            $first = true;
            foreach ($plan->get_competencies() as $planned) {
                $frameworkid = (int) $planned->get('competencyframeworkid');
                $frameworkorders[$frameworkid] ??= self::framework_order($frameworkid);
                // The planned competency and everything below it, in framework order.
                $prefix = $planned->get('path') . $planned->get('id') . '/';
                $depth = null;
                foreach ($frameworkorders[$frameworkid] as $comp) {
                    if ((int) $comp->id !== (int) $planned->get('id') && !str_starts_with($comp->path, $prefix)) {
                        continue;
                    }
                    if (isset($seen[$comp->id])) {
                        continue;
                    }
                    $depth ??= $comp->depth;
                    $seen[$comp->id] = true;
                    $copy = clone $comp;
                    $copy->depth = $comp->depth - $depth;
                    $copy->group = $first && count($this->plans) > 1
                        ? format_string($plan->get('name'), true, ['context' => \context_system::instance()]) : '';
                    $first = false;
                    $result[] = $copy;
                }
            }
        }
        return $this->competencies = $result;
    }

    /**
     * Frameworks the scope covers: the framework, or those of the competencies in the plans.
     *
     * @return int[]
     */
    public function get_frameworkids(): array {
        if ($this->type === self::WITH_EVIDENCE) {
            return array_values(array_unique(array_column($this->units, 'frameworkid')));
        }
        if ($this->type === self::FRAMEWORK || $this->type === self::UNIT) {
            return [$this->frameworkid];
        }
        $ids = [];
        foreach ($this->plans as $plan) {
            foreach ($plan->get_competencies() as $competency) {
                $ids[(int) $competency->get('competencyframeworkid')] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * The terminology profile for documents covering this scope (see {@see terms::for_frameworks()}).
     *
     * @return string
     */
    public function get_profile(): string {
        $ids = $this->get_frameworkids();
        return $ids ? terms::for_frameworks($ids) : terms::get_site_profile();
    }

    /**
     * IDs of the competencies covered.
     *
     * @return int[]
     */
    public function get_competency_ids(): array {
        return array_map(fn($comp) => (int) $comp->id, $this->get_competencies());
    }

    /**
     * Ratings frozen when a plan was completed: competencyid => stdClass (grade, proficiency). The summary shows these
     * for completed plans, so it reflects what was true when the plan was signed off.
     *
     * @return stdClass[]
     */
    public function get_frozen_ratings(): array {
        $frozen = [];
        foreach ($this->plans as $plan) {
            if ((int) $plan->get('status') !== plan::STATUS_COMPLETE) {
                continue;
            }
            $records = \core_competency\user_competency_plan::get_records(['planid' => $plan->get('id'),
                'userid' => $plan->get('userid')]);
            foreach ($records as $record) {
                $frozen[(int) $record->get('competencyid')] = (object) [
                    'grade' => $record->get('grade') === null ? null : (int) $record->get('grade'),
                    'proficiency' => (int) $record->get('proficiency'),
                ];
            }
        }
        return $frozen;
    }

    /**
     * Options for choosing a scope: name, params, selected.
     *
     * @param int $userid
     * @param array $frameworks From {@see summary::get_frameworks()}
     * @return array
     */
    public function get_options(int $userid, array $frameworks): array {
        $context = \context_system::instance();
        $options = [];
        $plans = self::get_plans($userid);
        if (count(self::get_active_plans($userid)) > 1) {
            $options[] = ['name' => get_string('scopeactiveplans', 'report_evidencereporter'),
                'params' => ['scope' => self::ACTIVE_PLANS]];
        }
        foreach ($plans as $plan) {
            $options[] = [
                'name' => get_string('learningplan', 'report_evidencereporter') . ': '
                    . format_string($plan->get('name'), true, ['context' => $context]) . ' (' . $plan->get_statusname() . ')',
                'params' => ['scope' => self::PLAN, 'planid' => (int) $plan->get('id')],
            ];
        }
        if (self::get_units_with_evidence($userid, $frameworks)) {
            $options[] = ['name' => get_string('scopewithevidence', 'report_evidencereporter', terms::get('units')),
                'params' => ['scope' => self::WITH_EVIDENCE]];
        }
        foreach (self::get_units($userid, $frameworks) as $unit) {
            $options[] = [
                'name' => terms::get('unit') . ': ' . self::unit_label($unit),
                'params' => ['scope' => self::UNIT, 'competencyid' => $unit->id],
            ];
        }
        foreach ($frameworks as $framework) {
            $options[] = [
                'name' => terms::get('framework') . ': '
                    . format_string($framework->shortname, true, ['context' => $context]),
                'params' => ['scope' => self::FRAMEWORK, 'frameworkid' => (int) $framework->id],
            ];
        }
        $mine = $this->get_params();
        foreach ($options as &$option) {
            $option['selected'] = $option['params'] == $mine;
        }
        return $options;
    }

    /**
     * Competencies of a framework in tree order (parents before children, by sort order).
     *
     * @param int $frameworkid
     * @return stdClass[] with depth (0 = top level)
     */
    public static function framework_order(int $frameworkid): array {
        global $DB;
        $all = $DB->get_records(
            'competency',
            ['competencyframeworkid' => $frameworkid],
            'sortorder, id',
            'id, parentid, path, shortname, idnumber, sortorder'
        );
        $children = [];
        foreach ($all as $comp) {
            $children[(int) $comp->parentid][] = $comp;
        }
        $ordered = [];
        $walk = function (int $parentid, int $depth) use (&$walk, &$ordered, $children): void {
            foreach ($children[$parentid] ?? [] as $comp) {
                $comp->depth = $depth;
                $comp->group = '';
                $ordered[] = $comp;
                $walk((int) $comp->id, $depth + 1);
            }
        };
        $walk(0, 0);
        return $ordered;
    }
}
