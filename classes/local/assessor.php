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
 * Works out who assessed a piece of evidence.
 *
 * The user recorded on the evidence (manual ratings) comes first. Activity-completion evidence has no user recorded,
 * so for the latest row per competency we fall back to whoever graded the activity for the learner, unless the learner
 * triggered the grade themselves, or the grade isn't released and the viewer can't see unreleased grades.
 *
 * Expected row fields: assessorid, graderid, a_<namefield>, g_<namefield> for each user name field, islatest,
 * cmid, modname and the release fields used by {@see feedback_visibility::is_released()}.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assessor {
    /**
     * Assessor's display name, or empty string.
     *
     * @param stdClass $row
     * @param bool $viewfullnames
     * @return string
     */
    public static function name(stdClass $row, bool $viewfullnames): string {
        if (!empty($row->assessorid)) {
            $prefix = 'a_';
        } else if (
            !empty($row->graderid) && !empty($row->islatest)
            && (feedback_visibility::is_released($row)
                || feedback_visibility::can_view_unreleased((int) $row->cmid, (string) $row->modname))
        ) {
            $prefix = 'g_';
        } else {
            return '';
        }
        $person = new stdClass();
        foreach (\core_user\fields::get_name_fields() as $namefield) {
            $person->{$namefield} = $row->{$prefix . $namefield} ?? '';
        }
        return fullname($person, $viewfullnames);
    }
}
