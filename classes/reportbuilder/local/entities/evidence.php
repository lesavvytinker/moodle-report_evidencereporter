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

declare(strict_types=1);

namespace report_evidencereporter\reportbuilder\local\entities;

use core_competency\evidence as evidence_persistent;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\{date, select};
use core_reportbuilder\local\helpers\{database, format};
use core_reportbuilder\local\report\{column, filter};
use html_writer;
use lang_string;
use report_evidencereporter\local\{activity_grade, assessor, attempts, data_entries, evidence_link, feedback_visibility,
    rating};
use stdClass;

/**
 * Competency evidence entity: one row per piece of evidence logged against a user competency.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evidence extends base {
    /** @var int Feedback longer than this (plain text characters) is collapsed on screen. */
    public const FEEDBACK_PREVIEW_LENGTH = 150;

    /**
     * Database tables that this entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [
            'competency_evidence',
            'competency_usercomp',
            'context',
            'course_modules',
            'modules',
            'grade_items',
            'grade_grades',
            'assign',
            'assign_user_flags',
            'user',
            'competency',
        ];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entityevidence', 'report_evidencereporter');
    }

    /**
     * Join from evidence to the user competency it belongs to.
     *
     * @return string
     */
    public function get_usercomp_join(): string {
        $ev = $this->get_table_alias('competency_evidence');
        $uc = $this->get_table_alias('competency_usercomp');
        return "JOIN {competency_usercomp} {$uc} ON {$uc}.id = {$ev}.usercompetencyid";
    }

    /**
     * Joins from evidence to the activity (course module) it was logged in, if any.
     *
     * @return string[]
     */
    public function get_activity_joins(): array {
        $ev = $this->get_table_alias('competency_evidence');
        $ctx = $this->get_table_alias('context');
        $cm = $this->get_table_alias('course_modules');
        $m = $this->get_table_alias('modules');
        $modulelevel = CONTEXT_MODULE;
        return [
            "LEFT JOIN {context} {$ctx} ON {$ctx}.id = {$ev}.contextid",
            "LEFT JOIN {course_modules} {$cm} ON {$ctx}.contextlevel = {$modulelevel} AND {$cm}.id = {$ctx}.instanceid",
            "LEFT JOIN {modules} {$m} ON {$m}.id = {$cm}.module",
        ];
    }

    /**
     * SQL expression for the course the evidence came from (activity course, or course context), else NULL.
     *
     * @return string
     */
    public function get_course_id_sql(): string {
        $ctx = $this->get_table_alias('context');
        $cm = $this->get_table_alias('course_modules');
        $courselevel = CONTEXT_COURSE;
        return "COALESCE({$cm}.course, CASE WHEN {$ctx}.contextlevel = {$courselevel} THEN {$ctx}.instanceid ELSE NULL END)";
    }

    /**
     * Joins to the learner's gradebook grade/feedback and assignment marking workflow state.
     *
     * @return string[]
     */
    private function get_grade_joins(): array {
        $uc = $this->get_table_alias('competency_usercomp');
        $cm = $this->get_table_alias('course_modules');
        $m = $this->get_table_alias('modules');
        $gi = $this->get_table_alias('grade_items');
        $gg = $this->get_table_alias('grade_grades');
        $as = $this->get_table_alias('assign');
        $auf = $this->get_table_alias('assign_user_flags');
        return array_merge([$this->get_usercomp_join()], $this->get_activity_joins(), [
            "LEFT JOIN {grade_items} {$gi} ON {$gi}.courseid = {$cm}.course AND {$gi}.itemtype = 'mod'
                   AND {$gi}.itemmodule = {$m}.name AND {$gi}.iteminstance = {$cm}.instance AND {$gi}.itemnumber = 0",
            "LEFT JOIN {grade_grades} {$gg} ON {$gg}.itemid = {$gi}.id AND {$gg}.userid = {$uc}.userid",
            "LEFT JOIN {assign} {$as} ON {$m}.name = 'assign' AND {$as}.id = {$cm}.instance",
            "LEFT JOIN {assign_user_flags} {$auf} ON {$auf}.assignment = {$as}.id AND {$auf}.userid = {$uc}.userid",
        ]);
    }

    /**
     * Initialise the entity.
     *
     * @return base
     */
    public function initialise(): base {
        foreach ($this->get_all_columns() as $column) {
            $this->add_column($column);
        }
        foreach ($this->get_all_filters() as $filter) {
            $this->add_filter($filter)->add_condition($filter);
        }
        return $this;
    }

    /**
     * Options for the evidence action (type).
     *
     * @return string[]
     */
    public static function get_action_options(): array {
        return [
            evidence_persistent::ACTION_LOG => get_string('actionlog', 'report_evidencereporter'),
            evidence_persistent::ACTION_COMPLETE => get_string('actioncomplete', 'report_evidencereporter'),
            evidence_persistent::ACTION_OVERRIDE => get_string('actionoverride', 'report_evidencereporter'),
        ];
    }

    /**
     * Add the fields every link/feedback column needs to identify the activity and learner.
     *
     * @param column $column
     * @return column
     */
    private function add_activity_fields(column $column): column {
        $uc = $this->get_table_alias('competency_usercomp');
        $cm = $this->get_table_alias('course_modules');
        $m = $this->get_table_alias('modules');
        return $column
            ->add_field("{$cm}.id", 'cmid')
            ->add_field("{$cm}.course", 'courseid')
            ->add_field("{$m}.name", 'modname')
            ->add_field("{$uc}.userid", 'userid');
    }

    /**
     * Add the fields used to work out whether feedback is released.
     *
     * @param column $column
     * @return column
     */
    private function add_release_fields(column $column): column {
        $gi = $this->get_table_alias('grade_items');
        $gg = $this->get_table_alias('grade_grades');
        $as = $this->get_table_alias('assign');
        $auf = $this->get_table_alias('assign_user_flags');
        $ev = $this->get_table_alias('competency_evidence');
        $ev2 = database::generate_alias();

        // The gradebook only holds the learner's current feedback, so it is shown against the most recent
        // evidence from that activity for each competency. Older rows (earlier attempts) are marked as superseded.
        // Same user competency = same learner and same competency.
        return $column
            ->add_field("CASE WHEN {$ev}.id = (SELECT MAX({$ev2}.id) FROM {competency_evidence} {$ev2}
                             WHERE {$ev2}.usercompetencyid = {$ev}.usercompetencyid
                               AND {$ev2}.contextid = {$ev}.contextid)
                         THEN 1 ELSE 0 END", 'islatest')
            ->add_field("{$gg}.id", 'gradeid')
            ->add_field("{$gi}.hidden", 'gradeitemhidden')
            ->add_field("{$gg}.hidden", 'gradehidden')
            ->add_field("{$as}.markingworkflow", 'markingworkflow')
            ->add_field("{$auf}.workflowstate", 'workflowstate');
    }

    /**
     * Returns list of all available columns.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        $ev = $this->get_table_alias('competency_evidence');
        $uc = $this->get_table_alias('competency_usercomp');
        $cm = $this->get_table_alias('course_modules');
        $m = $this->get_table_alias('modules');
        $gg = $this->get_table_alias('grade_grades');
        $au = $this->get_table_alias('user');

        // Date logged.
        $columns[] = (new column(
            'timecreated',
            new lang_string('timecreated', 'report_evidencereporter'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$ev}.timecreated")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        // Evidence type (log / completed / rating set).
        $columns[] = (new column('action', new lang_string('action', 'report_evidencereporter'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$ev}.action")
            ->set_is_sortable(true)
            ->set_disabled_aggregation(['avg', 'max', 'min', 'percent', 'sum'])
            ->add_callback(static function (?int $action): string {
                $options = self::get_action_options();
                return $action === null ? '' : ($options[$action] ?? (string) $action);
            });

        // Evidence description (the core competency string, e.g. "The activity 'X' was completed.").
        $columns[] = (new column(
            'description',
            new lang_string('evidencedescription', 'report_evidencereporter'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_fields("{$ev}.descidentifier, {$ev}.desccomponent, {$ev}.desca")
            ->set_is_sortable(false)
            ->add_callback(static function (?string $identifier, stdClass $row): string {
                if (empty($identifier) || empty($row->desccomponent)) {
                    return '';
                }
                if (!get_string_manager()->string_exists($identifier, $row->desccomponent)) {
                    return s($identifier);
                }
                $a = $row->desca === null || $row->desca === '' ? null : json_decode($row->desca);
                return get_string($identifier, $row->desccomponent, $a);
            });

        // Activity name, linked to the activity.
        $columns[] = $this->add_activity_fields((new column(
            'activity',
            new lang_string('activity', 'report_evidencereporter'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_joins(array_merge([$this->get_usercomp_join()], $this->get_activity_joins()))
            ->set_type(column::TYPE_TEXT))
            ->set_is_sortable(false)
            ->add_callback(static function ($cmid, stdClass $row): string {
                if (empty($row->cmid) || empty($row->courseid)) {
                    return '';
                }
                try {
                    $cminfo = get_fast_modinfo((int) $row->courseid)->get_cm((int) $row->cmid);
                } catch (\Throwable $e) {
                    return '';
                }
                return html_writer::link($cminfo->url, $cminfo->get_formatted_name());
            });

        // Activity type.
        $columns[] = (new column(
            'activitytype',
            new lang_string('activitytype', 'report_evidencereporter'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_joins($this->get_activity_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$m}.name", 'modname')
            ->set_is_sortable(true)
            ->add_callback(static function (?string $modname): string {
                if (empty($modname)) {
                    return '';
                }
                return get_string_manager()->string_exists('modulename', "mod_{$modname}")
                    ? get_string('modulename', "mod_{$modname}") : s($modname);
            });

        // Evidence link (HTML) and evidence URL (plain, for exports) share the same fields.
        foreach (['evidencelink', 'evidenceurl'] as $name) {
            $column = $this->add_activity_fields((new column(
                $name,
                new lang_string($name, 'report_evidencereporter'),
                $this->get_entity_name()
            ))
                ->add_joins($this->get_joins())
                ->add_joins(array_merge([$this->get_usercomp_join()], $this->get_activity_joins()))
                ->set_type(column::TYPE_TEXT)
                ->add_field("{$ev}.url", 'url'));

            // Latest finished quiz attempt / latest database entry for this learner, when relevant.
            $qa = database::generate_alias();
            $dr = database::generate_alias();
            $column
                ->add_field("CASE WHEN {$m}.name = 'quiz' THEN (SELECT MAX({$qa}.id) FROM {quiz_attempts} {$qa}
                              WHERE {$qa}.quiz = {$cm}.instance AND {$qa}.userid = {$uc}.userid
                                AND {$qa}.state = 'finished') ELSE NULL END", 'quizattemptid')
                ->add_field("CASE WHEN {$m}.name = 'data' THEN " . data_entries::latest_entry_sql("{$cm}.instance", "{$uc}.userid")
                    . " ELSE NULL END", 'datarecordid')
                ->set_is_sortable(false);

            if ($name === 'evidencelink') {
                $column->add_callback(static function ($value, stdClass $row): string {
                    $url = evidence_link::get_url($row);
                    return $url ? html_writer::link($url, get_string('viewevidence', 'report_evidencereporter')) : '';
                });
            } else {
                $column->add_callback(static function ($value, stdClass $row): string {
                    $url = evidence_link::get_url($row);
                    return $url ? $url->out(false) : '';
                });
            }
            $columns[] = $column;
        }

        // Rating given in this evidence event, and the learner's current rating for the competency, both shown as
        // the scale item name (e.g. "Competent").
        $ratingcallback = static function (?int $grade, stdClass $row): string {
            return rating::name((int) ($row->competencyid ?? 0), $grade);
        };
        $columns[] = (new column('rating', new lang_string('rating', 'report_evidencereporter'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->add_join($this->get_usercomp_join())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$ev}.grade", 'grade')
            ->add_field("{$uc}.competencyid", 'competencyid')
            ->set_is_sortable(true, ['grade'])
            ->set_disabled_aggregation(['avg', 'max', 'min', 'percent', 'sum'])
            ->add_callback($ratingcallback);

        $columns[] = (new column(
            'currentrating',
            new lang_string('currentrating', 'report_evidencereporter'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_join($this->get_usercomp_join())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$uc}.grade", 'currentgrade')
            ->add_field("{$uc}.competencyid", 'competencyid')
            ->set_is_sortable(true, ['currentgrade'])
            ->set_disabled_aggregation(['avg', 'max', 'min', 'percent', 'sum'])
            ->add_callback($ratingcallback);

        // Link to the learner's printable evidence summary for this competency's framework.
        $c = $this->get_table_alias('competency');
        $columns[] = (new column(
            'printsummary',
            new lang_string('printsummarylink', 'report_evidencereporter'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_join($this->get_usercomp_join())
            ->add_join("JOIN {competency} {$c} ON {$c}.id = {$uc}.competencyid")
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$uc}.userid", 'learnerid')
            ->add_field("{$c}.competencyframeworkid", 'frameworkid')
            ->set_is_sortable(false)
            ->add_callback(static function ($learnerid, stdClass $row): string {
                if (empty($row->learnerid) || empty($row->frameworkid)) {
                    return '';
                }
                $url = new \moodle_url(
                    '/report/evidencereporter/print.php',
                    ['userid' => $row->learnerid, 'frameworkid' => $row->frameworkid]
                );
                return html_writer::link($url, get_string('printsummarylink', 'report_evidencereporter'));
            });

        // Note added by the assessor when rating manually.
        $columns[] = (new column('note', new lang_string('note', 'report_evidencereporter'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_LONGTEXT)
            ->add_field("{$ev}.note")
            ->set_is_sortable(false)
            ->add_callback(static function (?string $note): string {
                return $note === null ? '' : s($note);
            });

        // Assessor: whoever rated the competency or logged the evidence. Completion evidence has no user recorded
        // against it, so for those rows fall back to whoever graded the activity for this learner (the latest row
        // per competency only, as the gradebook holds the current grade). Grades the learner triggered themselves
        // (e.g. auto-marked quizzes) don't count, and unreleased grades only show to users who can see them.
        $gu = database::generate_alias();
        $viewfullnames = has_capability('moodle/site:viewfullnames', \context_system::instance());
        $namefieldlist = \core_user\fields::get_name_fields();
        $column = (new column('assessor', new lang_string('assessor', 'report_evidencereporter'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->add_joins($this->get_grade_joins())
            ->add_join("LEFT JOIN {user} {$au} ON {$au}.id = {$ev}.actionuserid")
            ->add_join("LEFT JOIN {user} {$gu} ON {$gu}.id = {$gg}.usermodified AND {$gg}.usermodified <> {$uc}.userid
                                               AND {$gg}.finalgrade IS NOT NULL")
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$au}.id", 'assessorid')
            ->add_field("{$gu}.id", 'graderid');
        foreach ($namefieldlist as $namefield) {
            $column->add_field("{$au}.{$namefield}", "a_{$namefield}")
                ->add_field("{$gu}.{$namefield}", "g_{$namefield}");
        }
        $column->add_field("COALESCE({$au}.lastname, {$gu}.lastname)", 'sortlastname')
            ->add_field("COALESCE({$au}.firstname, {$gu}.firstname)", 'sortfirstname');
        $columns[] = $this->add_activity_fields($this->add_release_fields($column))
            ->set_is_sortable(true, ['sortlastname', 'sortfirstname'])
            ->add_callback(static function ($assessorid, stdClass $row) use ($viewfullnames): string {
                return assessor::name($row, $viewfullnames);
            });

        // The learner's gradebook grade for the activity, respecting release rules.
        $gi = $this->get_table_alias('grade_items');
        $column = (new column(
            'activitygrade',
            new lang_string('activitygrade', 'report_evidencereporter'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_joins($this->get_grade_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$gi}.id", 'gradeitemid')
            ->add_field("{$gg}.finalgrade", 'finalgrade');
        $columns[] = $this->add_release_fields($this->add_activity_fields($column))
            ->set_is_sortable(false)
            ->add_callback(static function ($gradeitemid, stdClass $row): string {
                return activity_grade::format($row);
            });

        // Every assessed attempt at this activity (assignments and quizzes), failed attempts included.
        $column = (new column(
            'attempthistory',
            new lang_string('attempthistory', 'report_evidencereporter'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_joins(array_merge([$this->get_usercomp_join()], $this->get_activity_joins()))
            ->set_type(column::TYPE_LONGTEXT);
        $columns[] = $this->add_activity_fields($column)
            ->set_is_sortable(false)
            ->set_disabled_aggregation(['groupconcat', 'groupconcatdistinct'])
            ->add_callback(static function ($cmid, stdClass $row) use ($viewfullnames): string {
                if (empty($row->cmid) || !in_array($row->modname, ['assign', 'quiz', 'data'])) {
                    return '';
                }
                $lines = [];
                foreach (attempts::get_for_user((int) $row->userid, [(int) $row->cmid])[(int) $row->cmid] ?? [] as $raw) {
                    $attempt = attempts::format($raw, $viewfullnames);
                    $parts = [get_string(
                        $attempt->isentry ? 'entrysummary' : 'attemptsummary',
                        'report_evidencereporter',
                        $attempt->attemptno
                    )];
                    foreach ([$attempt->grade, $attempt->marker] as $part) {
                        if ($part !== '') {
                            $parts[] = s($part);
                        }
                    }
                    if ($attempt->graded) {
                        $parts[] = userdate($attempt->graded, get_string('strftimedatefullshort', 'core_langconfig'));
                    }
                    $line = implode(' · ', $parts);
                    if ($attempt->feedbackplain !== '') {
                        $line .= ': ' . html_writer::span(s(shorten_text($attempt->feedbackplain, 100)), 'text-muted');
                    }
                    $lines[] = $line;
                }
                return implode(html_writer::empty_tag('br'), $lines);
            });

        // Feedback from the gradebook, respecting release rules.
        $column = (new column('feedback', new lang_string('feedback', 'report_evidencereporter'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->add_joins($this->get_grade_joins())
            ->set_type(column::TYPE_LONGTEXT)
            ->add_field("{$gg}.feedback", 'feedback')
            ->add_field("{$gg}.feedbackformat", 'feedbackformat')
            ->add_field("CASE WHEN {$m}.name = 'data' THEN " . data_entries::latest_entry_sql("{$cm}.instance", "{$uc}.userid")
                . " ELSE NULL END", 'datarecordid');
        $columns[] = $this->add_release_fields($this->add_activity_fields($column))
            ->set_is_sortable(false)
            ->add_callback(static function (?string $feedback, stdClass $row) use ($viewfullnames): string {
                // Database entries: every comment on the entry, with who wrote it and when.
                if ($row->modname === 'data' && !empty($row->datarecordid)) {
                    return data_entries::comments_html(data_entries::get_comments(
                        (int) $row->datarecordid,
                        (int) $row->cmid,
                        $viewfullnames
                    ));
                }
                if ($feedback === null || trim($feedback) === '') {
                    return '';
                }
                if (empty($row->islatest)) {
                    return get_string('feedbacksuperseded', 'report_evidencereporter');
                }
                if (
                    !feedback_visibility::is_released($row)
                        && !feedback_visibility::can_view_unreleased((int) $row->cmid, (string) $row->modname)
                ) {
                    return get_string('pendingrelease', 'report_evidencereporter');
                }
                $context = \context_course::instance((int) $row->courseid, IGNORE_MISSING) ?: \context_system::instance();
                $html = format_text($feedback, (int) $row->feedbackformat, ['context' => $context]);
                $plain = trim(html_to_text($html, 0, false));
                if (\core_text::strlen($plain) <= self::FEEDBACK_PREVIEW_LENGTH) {
                    return $html;
                }
                return html_writer::tag(
                    'details',
                    html_writer::tag('summary', s(shorten_text($plain, self::FEEDBACK_PREVIEW_LENGTH))) . $html
                );
            });

        // Release status of that feedback.
        $column = (new column(
            'releasestatus',
            new lang_string('releasestatus', 'report_evidencereporter'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_joins($this->get_grade_joins())
            ->set_type(column::TYPE_TEXT);
        $columns[] = $this->add_activity_fields($this->add_release_fields($column))
            ->set_is_sortable(false)
            ->add_callback(static function ($gradeid, stdClass $row): string {
                if (empty($row->gradeid) || empty($row->islatest)) {
                    return '';
                }
                if (feedback_visibility::is_released($row)) {
                    return get_string('released', 'report_evidencereporter');
                }
                if (feedback_visibility::can_view_unreleased((int) $row->cmid, (string) $row->modname)) {
                    return html_writer::span(
                        get_string('notreleased', 'report_evidencereporter'),
                        'badge bg-warning text-dark'
                    );
                }
                return get_string('pendingrelease', 'report_evidencereporter');
            });

        return $columns;
    }

    /**
     * Return list of all available filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $ev = $this->get_table_alias('competency_evidence');
        $m = $this->get_table_alias('modules');

        $filters[] = (new filter(
            date::class,
            'timecreated',
            new lang_string('timecreated', 'report_evidencereporter'),
            $this->get_entity_name(),
            "{$ev}.timecreated"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            select::class,
            'action',
            new lang_string('action', 'report_evidencereporter'),
            $this->get_entity_name(),
            "{$ev}.action"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback([self::class, 'get_action_options']);

        $filters[] = (new filter(
            select::class,
            'activitytype',
            new lang_string('activitytype', 'report_evidencereporter'),
            $this->get_entity_name(),
            "{$m}.name"
        ))
            ->add_joins($this->get_joins())
            ->add_joins($this->get_activity_joins())
            ->set_options_callback(static function (): array {
                return get_module_types_names();
            });

        return $filters;
    }
}
