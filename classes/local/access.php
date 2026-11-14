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

use core_reportbuilder\local\helpers\database;

/**
 * Row-level access for the evidence report.
 *
 * Custom reports don't filter rows by permission on their own, so the data source adds this as a base condition.
 * A viewer sees an evidence row when any of these is true:
 *  - they hold moodle/competency:usercompetencyview at system level (managers, admins);
 *  - the evidence is about them;
 *  - the evidence came from a course where they hold moodle/competency:usercompetencyview (teachers);
 *  - they hold moodle/competency:usercompetencyview in the learner's user context (mentors, supervisors).
 *
 * Evidence of prior learning lives in the user context, so course teachers don't see it unless they also
 * have a role in the learner's user context. That matches core Moodle behaviour.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {
    /** @var string Capability used for row visibility. */
    public const CAPABILITY = 'moodle/competency:usercompetencyview';

    /**
     * Build the SQL condition restricting rows to what the current user may see.
     *
     * @param string $learneridfield SQL field holding the learner's user ID
     * @param string $courseidfield SQL expression giving the course ID the evidence came from (may be NULL)
     * @return array|null [$where, $params], or null when no restriction is needed
     */
    public static function get_sql(string $learneridfield, string $courseidfield): ?array {
        global $DB, $USER;

        if (has_capability(self::CAPABILITY, \context_system::instance())) {
            return null;
        }

        $viewerparam = database::generate_param_name();
        $where = ["{$learneridfield} = :{$viewerparam}"];
        $params = [$viewerparam => (int) $USER->id];

        // Courses where the viewer can see learners' competencies.
        $courses = get_user_capability_course(self::CAPABILITY, $USER->id, true, '', 'id', 0);
        $courseids = $courses ? array_map('intval', array_column($courses, 'id')) : [];
        if (!empty($courseids)) {
            [$insql, $inparams] = $DB->get_in_or_equal(
                $courseids,
                SQL_PARAMS_NAMED,
                database::generate_param_name('_')
            );
            $where[] = "{$courseidfield} {$insql}";
            $params += $inparams;
        }

        // Learners whose user context the viewer has a role in (mentor / supervisor style roles).
        $learners = [];
        $sql = "SELECT DISTINCT ctx.instanceid
                  FROM {role_assignments} ra
                  JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :userlevel
                 WHERE ra.userid = :viewer";
        foreach ($DB->get_fieldset_sql($sql, ['userlevel' => CONTEXT_USER, 'viewer' => $USER->id]) as $learnerid) {
            $usercontext = \context_user::instance((int) $learnerid, IGNORE_MISSING);
            if ($usercontext && has_capability(self::CAPABILITY, $usercontext)) {
                $learners[] = (int) $learnerid;
            }
        }
        if (!empty($learners)) {
            [$insql, $inparams] = $DB->get_in_or_equal($learners, SQL_PARAMS_NAMED, database::generate_param_name('_'));
            $where[] = "{$learneridfield} {$insql}";
            $params += $inparams;
        }

        return ['(' . implode(' OR ', $where) . ')', $params];
    }
}
