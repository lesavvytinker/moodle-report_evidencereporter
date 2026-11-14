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

namespace report_evidencereporter\reportbuilder\datasource;

use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\{course, user};
use lang_string;
use report_evidencereporter\local\access;
use report_evidencereporter\reportbuilder\local\entities\{attempt, competency};

/**
 * Assessment attempts data source: every assessed assignment attempt and finished quiz attempt, failed ones included,
 * for each competency linked to the activity.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempts extends datasource {
    /**
     * Return user friendly name of the report source.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('datasourceattempts', 'report_evidencereporter');
    }

    /**
     * Only offer this source when competencies are enabled on the site.
     *
     * @return bool
     */
    public static function is_available(): bool {
        return \core_competency\api::is_enabled();
    }

    /**
     * Initialise report.
     */
    protected function initialise(): void {
        $attempt = new attempt();
        $mc = $attempt->get_table_alias('competency_modulecomp');
        $cm = $attempt->get_table_alias('course_modules');
        $this->set_main_table('competency_modulecomp', $mc);
        $this->add_entity($attempt);

        foreach ($attempt->get_attempt_joins() as $join) {
            $this->add_join($join);
        }
        $learner = $attempt->get_learner_sql();
        $this->add_base_condition_sql($attempt->get_has_attempt_sql());
        if ($access = access::get_sql($learner, "{$cm}.course")) {
            [$where, $params] = $access;
            $this->add_base_condition_sql($where, $params);
        }

        $competency = new competency();
        $c = $competency->get_table_alias('competency');
        $this->add_entity($competency->add_join("JOIN {competency} {$c} ON {$c}.id = {$mc}.competencyid"));

        $user = new user();
        $u = $user->get_table_alias('user');
        $this->add_entity($user
            ->add_joins($attempt->get_attempt_joins())
            ->add_join("JOIN {user} {$u} ON {$u}.id = {$learner}")
            ->set_entity_title(new lang_string('entitylearner', 'report_evidencereporter')));

        $course = new course();
        $co = $course->get_table_alias('course');
        $this->add_entity($course
            ->add_joins($attempt->get_attempt_joins())
            ->add_join("JOIN {course} {$co} ON {$co}.id = {$cm}.course"));

        $this->add_all_from_entities();
    }

    /**
     * Return the columns that will be added to the report upon creation.
     *
     * @return string[]
     */
    public function get_default_columns(): array {
        return [
            'user:fullnamewithlink',
            'competency:name',
            'course:fullname',
            'attempt:activity',
            'attempt:attempt',
            'attempt:timesubmitted',
            'attempt:timegraded',
            'attempt:grade',
            'attempt:outcome',
            'attempt:marker',
            'attempt:feedback',
            'attempt:releasestatus',
            'attempt:link',
        ];
    }

    /**
     * Return the default sorting that will be added to the report once it is created.
     *
     * @return int[]
     */
    public function get_default_column_sorting(): array {
        return [
            'user:fullnamewithlink' => SORT_ASC,
            'competency:name' => SORT_ASC,
            'attempt:attempt' => SORT_ASC,
        ];
    }

    /**
     * Return the filters that will be added to the report upon creation.
     *
     * @return string[]
     */
    public function get_default_filters(): array {
        return [
            'user:fullname',
            'competency:name',
            'competency:framework',
            'course:fullname',
            'attempt:outcome',
            'attempt:islatest',
            'attempt:activitytype',
            'attempt:timegraded',
        ];
    }

    /**
     * Return the conditions that will be added to the report upon creation.
     *
     * @return string[]
     */
    public function get_default_conditions(): array {
        return [
            'competency:framework',
        ];
    }
}
