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
 * Formats the learner's gradebook grade for the activity a piece of evidence came from.
 *
 * Uses the gradebook's own display settings (real, percentage, letter or scale), adds the maximum for numeric grades,
 * and Pass/Fail when the activity has a grade to pass. Follows the same rules as feedback: only on the latest row per
 * competency for that activity (the gradebook holds the current grade only), and unreleased grades show as pending to
 * viewers who can't see them.
 *
 * Expected row fields: gradeitemid, finalgrade, islatest, cmid, modname and the release fields.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_grade {
    /** @var \grade_item[] Grade items cached by ID. */
    private static array $items = [];

    /**
     * Display text for the grade, or empty string.
     *
     * @param stdClass $row
     * @return string
     */
    public static function format(stdClass $row): string {
        if (empty($row->gradeitemid) || !isset($row->finalgrade) || $row->finalgrade === null || empty($row->islatest)) {
            return '';
        }
        if (
            !feedback_visibility::is_released($row)
                && !feedback_visibility::can_view_unreleased((int) $row->cmid, (string) $row->modname)
        ) {
            return get_string('pendingrelease', 'report_evidencereporter');
        }

        return self::format_value((int) $row->gradeitemid, (float) $row->finalgrade);
    }

    /**
     * Format a grade value for a grade item: display format, maximum, and Pass/Fail when there's a grade to pass.
     *
     * @param int $gradeitemid
     * @param float $value Grade on the grade item's own scale (e.g. 40 out of 100, or scale item 2)
     * @return string
     */
    public static function format_value(int $gradeitemid, float $value): string {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        if (!array_key_exists($gradeitemid, self::$items)) {
            self::$items[$gradeitemid] = \grade_item::fetch(['id' => $gradeitemid]) ?: null;
        }
        if (!$item = self::$items[$gradeitemid]) {
            return format_float($value, 2);
        }
        $text = grade_format_gradevalue($value, $item, true);
        if ((int) $item->gradetype === GRADE_TYPE_VALUE && $item->get_displaytype() == GRADE_DISPLAY_TYPE_REAL) {
            $text .= ' / ' . grade_format_gradevalue($item->grademax, $item, true);
        }
        if ($outcome = self::outcome($gradeitemid, $value)) {
            $text .= ' (' . get_string($outcome === 'pass' ? 'gradepass' : 'gradefail', 'report_evidencereporter') . ')';
        }
        return $text;
    }

    /**
     * 'pass', 'fail', or '' when the grade item has no grade to pass.
     *
     * @param int $gradeitemid
     * @param float $value
     * @return string
     */
    public static function outcome(int $gradeitemid, float $value): string {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        if (!array_key_exists($gradeitemid, self::$items)) {
            self::$items[$gradeitemid] = \grade_item::fetch(['id' => $gradeitemid]) ?: null;
        }
        $item = self::$items[$gradeitemid];
        if (!$item || (float) $item->gradepass <= 0) {
            return '';
        }
        return $value >= (float) $item->gradepass ? 'pass' : 'fail';
    }

    /**
     * Reset the grade item cache (for unit tests).
     */
    public static function reset_cache(): void {
        self::$items = [];
    }
}
