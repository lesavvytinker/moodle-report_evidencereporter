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
use report_evidencereporter\reportbuilder\local\entities\{competency, evidence as evidence_entity};

/**
 * Competency evidence data source: one row per piece of evidence, with learner, competency and course.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evidence extends datasource {
    /**
     * Return user friendly name of the report source.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('datasource', 'report_evidencereporter');
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
        $evidenceentity = new evidence_entity();
        $ev = $evidenceentity->get_table_alias('competency_evidence');
        $uc = $evidenceentity->get_table_alias('competency_usercomp');

        $this->set_main_table('competency_evidence', $ev);
        $this->add_entity($evidenceentity);

        // Row-level permissions: always joined so the condition can use them.
        $basejoins = array_merge([$evidenceentity->get_usercomp_join()], $evidenceentity->get_activity_joins());
        foreach ($basejoins as $join) {
            $this->add_join($join);
        }
        if ($access = access::get_sql("{$uc}.userid", $evidenceentity->get_course_id_sql())) {
            [$where, $params] = $access;
            $this->add_base_condition_sql($where, $params);
        }

        // Competency (and framework).
        $competencyentity = new competency();
        $c = $competencyentity->get_table_alias('competency');
        $this->add_entity($competencyentity
            ->add_join($evidenceentity->get_usercomp_join())
            ->add_join("JOIN {competency} {$c} ON {$c}.id = {$uc}.competencyid"));

        // Learner.
        $userentity = new user();
        $u = $userentity->get_table_alias('user');
        $this->add_entity($userentity
            ->add_join($evidenceentity->get_usercomp_join())
            ->add_join("JOIN {user} {$u} ON {$u}.id = {$uc}.userid")
            ->set_entity_title(new lang_string('entitylearner', 'report_evidencereporter')));

        // Course the evidence came from (empty for evidence of prior learning and plan ratings).
        $courseentity = new course();
        $co = $courseentity->get_table_alias('course');
        $this->add_entity($courseentity
            ->add_joins($evidenceentity->get_activity_joins())
            ->add_join("LEFT JOIN {course} {$co} ON {$co}.id = " . $evidenceentity->get_course_id_sql()));

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
            'evidence:timecreated',
            'evidence:action',
            'evidence:activity',
            'evidence:evidencelink',
            'evidence:activitygrade',
            'evidence:finalresult',
            'evidence:attempthistory',
            'evidence:rating',
            'evidence:currentrating',
            'evidence:assessor',
            'evidence:feedback',
            'evidence:releasestatus',
            'evidence:printsummary',
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
            'evidence:timecreated' => SORT_ASC,
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
            'evidence:activitytype',
            'evidence:timecreated',
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
