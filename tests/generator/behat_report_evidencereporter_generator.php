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

/**
 * Behat data generator for Evidence Reporter.
 *
 * @package    report_evidencereporter
 * @category   test
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_report_evidencereporter_generator extends behat_generator_base {
    /**
     * Entities Behat can create with this generator.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'evidence' => [
                'singular' => 'evidence',
                'datagenerator' => 'evidence',
                'required' => ['user', 'competency'],
                'switchids' => ['user' => 'userid', 'competency' => 'competencyid', 'activity' => 'cmid',
                    'assessor' => 'actionuserid'],
            ],
            'course ratings' => [
                'singular' => 'course rating',
                'datagenerator' => 'course_rating',
                'required' => ['user', 'competency', 'course', 'grade'],
                'switchids' => ['user' => 'userid', 'competency' => 'competencyid', 'course' => 'courseid',
                    'assessor' => 'actionuserid'],
            ],
        ];
    }

    /**
     * Competency ID from its ID number.
     *
     * @param string $idnumber
     * @return int
     */
    protected function get_competency_id(string $idnumber): int {
        global $DB;
        if (!$id = $DB->get_field('competency', 'id', ['idnumber' => $idnumber])) {
            throw new Exception('The specified competency with idnumber "' . $idnumber . '" could not be found.');
        }
        return (int) $id;
    }

    /**
     * Assessor user ID from their username.
     *
     * @param string $username
     * @return int
     */
    protected function get_assessor_id(string $username): int {
        return (int) $this->get_user_id($username);
    }
}
