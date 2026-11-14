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

use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\{boolean_select, date, select};
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\{column, filter};
use html_writer;
use lang_string;
use report_evidencereporter\local\attempts;
use stdClass;

/**
 * Assessment attempt entity: one row per assessed assignment attempt or finished quiz attempt, for each competency
 * linked to that activity.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt extends base {
    /** @var int Feedback longer than this (plain text characters) is collapsed on screen. */
    public const FEEDBACK_PREVIEW_LENGTH = 150;

    /**
     * Database tables that this entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [
            'competency_modulecomp',
            'course_modules',
            'modules',
            'assign',
            'assign_grades',
            'assign_submission',
            'assignfeedback_comments',
            'quiz',
            'quiz_attempts',
            'grade_items',
            'grade_grades',
            'assign_user_flags',
            'user',
            'data',
            'data_records',
        ];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entityattempt', 'report_evidencereporter');
    }

    /**
     * Aliases for {@see attempts::get_sql_parts()}.
     *
     * @return array
     */
    private function get_attempt_aliases(): array {
        return [
            'a' => $this->get_table_alias('assign'),
            'ag' => $this->get_table_alias('assign_grades'),
            'asub' => $this->get_table_alias('assign_submission'),
            'afc' => $this->get_table_alias('assignfeedback_comments'),
            'q' => $this->get_table_alias('quiz'),
            'qa' => $this->get_table_alias('quiz_attempts'),
            'gi' => $this->get_table_alias('grade_items'),
            'gg' => $this->get_table_alias('grade_grades'),
            'auf' => $this->get_table_alias('assign_user_flags'),
            'gu' => $this->get_table_alias('user'),
            'd' => $this->get_table_alias('data'),
            'dr' => $this->get_table_alias('data_records'),
        ];
    }

    /**
     * Joins from the module-competency link to the activity and all its attempts.
     *
     * @return string[]
     */
    public function get_attempt_joins(): array {
        $mc = $this->get_table_alias('competency_modulecomp');
        $cm = $this->get_table_alias('course_modules');
        $m = $this->get_table_alias('modules');
        [$joins] = attempts::get_sql_parts($cm, $m, $this->get_attempt_aliases());
        return array_merge([
            "JOIN {course_modules} {$cm} ON {$cm}.id = {$mc}.cmid",
            "JOIN {modules} {$m} ON {$m}.id = {$cm}.module",
        ], $joins);
    }

    /**
     * SQL for the learner's user ID.
     *
     * @return string
     */
    public function get_learner_sql(): string {
        [, $learner] = attempts::get_sql_parts(
            $this->get_table_alias('course_modules'),
            $this->get_table_alias('modules'),
            $this->get_attempt_aliases()
        );
        return $learner;
    }

    /**
     * SQL condition: the row is an actual attempt (each activity matches only its own attempt table).
     *
     * @return string
     */
    public function get_has_attempt_sql(): string {
        $aliases = $this->get_attempt_aliases();
        return "({$aliases['ag']}.id IS NOT NULL OR {$aliases['qa']}.id IS NOT NULL OR {$aliases['dr']}.id IS NOT NULL)";
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
     * Fields as SQL, by alias.
     *
     * @return array
     */
    private function get_fields(): array {
        [, , $fields] = attempts::get_sql_parts(
            $this->get_table_alias('course_modules'),
            $this->get_table_alias('modules'),
            $this->get_attempt_aliases()
        );
        return $fields;
    }

    /**
     * A column carrying every attempt field, so any callback can format the attempt.
     *
     * @param string $name
     * @param string $titleidentifier
     * @param int $type
     * @return column
     */
    private function attempt_column(string $name, string $titleidentifier, int $type = column::TYPE_TEXT): column {
        $column = (new column($name, new lang_string($titleidentifier, 'report_evidencereporter'), $this->get_entity_name()))
            ->add_joins($this->get_joins())
            ->add_joins($this->get_attempt_joins())
            ->set_type($type)
            ->set_disabled_aggregation(['avg', 'max', 'min', 'percent', 'sum', 'groupconcat', 'groupconcatdistinct']);
        // The first field is the column's value.
        $fields = $this->get_fields();
        $first = [
            'attempt' => 'attemptno', 'timesubmitted' => 'timesubmitted', 'timegraded' => 'timegraded',
            'activitytype' => 'modname',
        ][$name] ?? 'attemptid';
        $column->add_field($fields[$first], $first);
        foreach ($fields as $alias => $sql) {
            if ($alias !== $first) {
                $column->add_field($sql, $alias);
            }
        }
        return $column;
    }

    /**
     * Returns list of all available columns.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        $viewfullnames = has_capability('moodle/site:viewfullnames', \context_system::instance());
        $fields = $this->get_fields();
        $formatted = static fn(stdClass $row): stdClass => attempts::format($row, $viewfullnames);

        $columns[] = $this->attempt_column('activity', 'activity')
            ->set_is_sortable(false)
            ->add_callback(static function ($value, stdClass $row): string {
                try {
                    $cminfo = get_fast_modinfo((int) $row->courseid)->get_cm((int) $row->cmid);
                } catch (\Throwable $e) {
                    return '';
                }
                return html_writer::link($cminfo->url, $cminfo->get_formatted_name());
            });

        $columns[] = $this->attempt_column('activitytype', 'activitytype')
            ->set_is_sortable(true, ['modname'])
            ->add_callback(static function (?string $modname): string {
                return $modname ? get_string('modulename', "mod_{$modname}") : '';
            });

        $columns[] = $this->attempt_column('attempt', 'attemptnumber', column::TYPE_INTEGER)
            ->set_is_sortable(true, ['attemptno'])
            ->add_callback(static fn($value): string => $value === null ? '' : (string) $value);

        $columns[] = $this->attempt_column('timesubmitted', 'timesubmitted', column::TYPE_TIMESTAMP)
            ->set_is_sortable(true, ['timesubmitted'])
            ->add_callback([format::class, 'userdate']);

        $columns[] = $this->attempt_column('timegraded', 'timegraded', column::TYPE_TIMESTAMP)
            ->set_is_sortable(true, ['timegraded'])
            ->add_callback([format::class, 'userdate']);

        $columns[] = $this->attempt_column('grade', 'activitygrade')
            ->set_is_sortable(true, ['gradevalue'])
            ->add_callback(static fn($value, stdClass $row): string => $formatted($row)->grade);

        $columns[] = $this->attempt_column('outcome', 'outcome')
            ->set_is_sortable(false)
            ->add_callback(static function ($value, stdClass $row) use ($formatted): string {
                $outcome = $formatted($row)->outcome;
                return $outcome ? get_string($outcome === 'pass' ? 'gradepass' : 'gradefail', 'report_evidencereporter') : '';
            });

        $columns[] = $this->attempt_column('marker', 'marker')
            ->set_is_sortable(false)
            ->add_callback(static fn($value, stdClass $row): string => $formatted($row)->marker);

        $columns[] = $this->attempt_column('feedback', 'feedback', column::TYPE_LONGTEXT)
            ->set_is_sortable(false)
            ->add_callback(static function ($value, stdClass $row) use ($formatted): string {
                $attempt = $formatted($row);
                if ($attempt->feedback === '' || \core_text::strlen($attempt->feedbackplain) <= self::FEEDBACK_PREVIEW_LENGTH) {
                    return $attempt->feedback;
                }
                return html_writer::tag('details', html_writer::tag(
                    'summary',
                    s(shorten_text($attempt->feedbackplain, self::FEEDBACK_PREVIEW_LENGTH))
                ) . $attempt->feedback);
            });

        $columns[] = $this->attempt_column('releasestatus', 'releasestatus')
            ->set_is_sortable(false)
            ->add_callback(static function ($value, stdClass $row) use ($formatted): string {
                $attempt = $formatted($row);
                if ($attempt->released) {
                    return get_string('released', 'report_evidencereporter');
                }
                return $attempt->canseeunreleased
                    ? html_writer::span(get_string('notreleased', 'report_evidencereporter'), 'badge bg-warning text-dark')
                    : get_string('pendingrelease', 'report_evidencereporter');
            });

        $columns[] = $this->attempt_column('islatest', 'latestattempt', column::TYPE_BOOLEAN)
            ->set_is_sortable(false)
            ->add_callback(static fn($value, stdClass $row): string => get_string(empty($row->islatest) ? 'no' : 'yes'));

        $columns[] = $this->attempt_column('link', 'viewattempt')
            ->set_is_sortable(false)
            ->add_callback(static function ($value, stdClass $row) use ($formatted): string {
                $url = $formatted($row)->url;
                return $url ? html_writer::link($url, get_string('viewattempt', 'report_evidencereporter')) : '';
            });

        $columns[] = $this->attempt_column('url', 'attempturl')
            ->set_is_sortable(false)
            ->add_callback(static function ($value, stdClass $row) use ($formatted): string {
                $url = $formatted($row)->url;
                return $url ? $url->out(false) : '';
            });

        return $columns;
    }

    /**
     * Return list of all available filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $fields = $this->get_fields();
        $gi = $this->get_table_alias('grade_items');
        $outcomesql = "CASE WHEN {$gi}.gradepass > 0 THEN
                            CASE WHEN {$fields['gradevalue']} >= {$gi}.gradepass THEN 'pass' ELSE 'fail' END
                        ELSE 'none' END";

        $filters[] = (new filter(
            select::class,
            'outcome',
            new lang_string('outcome', 'report_evidencereporter'),
            $this->get_entity_name(),
            $outcomesql
        ))
            ->add_joins($this->get_joins())
            ->add_joins($this->get_attempt_joins())
            ->set_options([
                'pass' => get_string('gradepass', 'report_evidencereporter'),
                'fail' => get_string('gradefail', 'report_evidencereporter'),
                'none' => get_string('nogradetopass', 'report_evidencereporter'),
            ]);

        $filters[] = (new filter(
            boolean_select::class,
            'islatest',
            new lang_string('latestattempt', 'report_evidencereporter'),
            $this->get_entity_name(),
            $fields['islatest']
        ))
            ->add_joins($this->get_joins())
            ->add_joins($this->get_attempt_joins());

        $filters[] = (new filter(
            select::class,
            'activitytype',
            new lang_string('activitytype', 'report_evidencereporter'),
            $this->get_entity_name(),
            $fields['modname']
        ))
            ->add_joins($this->get_joins())
            ->add_joins($this->get_attempt_joins())
            ->set_options([
                'assign' => get_string('modulename', 'mod_assign'),
                'quiz' => get_string('modulename', 'mod_quiz'),
                'data' => get_string('modulename', 'mod_data'),
            ]);

        $filters[] = (new filter(
            date::class,
            'timegraded',
            new lang_string('timegraded', 'report_evidencereporter'),
            $this->get_entity_name(),
            $fields['timegraded']
        ))
            ->add_joins($this->get_joins())
            ->add_joins($this->get_attempt_joins());

        return $filters;
    }
}
