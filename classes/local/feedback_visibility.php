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
 * Decides whether activity feedback has been released, and who may see it before release.
 *
 * Released means: the grade item and the grade are both visible (or their hidden-until date has passed),
 * and, for assignments using marking workflow, the workflow state is "released".
 *
 * Users with moodle/grade:viewhidden (or mod/assign:grade for assignments) in the activity context
 * can see unreleased feedback, which the report labels as not yet released.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_visibility {
    /** @var bool[] Capability cache keyed by "userid:cmid:modname". */
    private static array $cache = [];

    /**
     * Is a gradebook "hidden" value currently hiding the item?
     *
     * 0 = visible, 1 = hidden, any larger value = hidden until that timestamp.
     *
     * @param int|null $hidden
     * @return bool
     */
    private static function is_hidden(?int $hidden): bool {
        if (empty($hidden)) {
            return false;
        }
        return $hidden == 1 || $hidden > time();
    }

    /**
     * Has the feedback on this row been released to the learner?
     *
     * Expected row fields: gradeitemhidden, gradehidden, markingworkflow, workflowstate.
     *
     * @param stdClass $row
     * @return bool
     */
    public static function is_released(stdClass $row): bool {
        if (self::is_hidden(isset($row->gradeitemhidden) ? (int) $row->gradeitemhidden : null)) {
            return false;
        }
        if (self::is_hidden(isset($row->gradehidden) ? (int) $row->gradehidden : null)) {
            return false;
        }
        if (!empty($row->markingworkflow) && ($row->workflowstate ?? '') !== 'released') {
            return false;
        }
        return true;
    }

    /**
     * Can the current user see unreleased feedback for this activity?
     *
     * @param int $cmid
     * @param string $modname
     * @return bool
     */
    public static function can_view_unreleased(int $cmid, string $modname): bool {
        global $USER;
        // Keyed by user too: scheduled reports and tests can switch users within one request.
        $key = "{$USER->id}:{$cmid}:{$modname}";
        if (!array_key_exists($key, self::$cache)) {
            $context = \context_module::instance($cmid, IGNORE_MISSING);
            $can = false;
            if ($context) {
                $source = sources::get($modname);
                $can = has_capability('moodle/grade:viewhidden', $context)
                    || ($source && $source->can_view_unreleased($context));
            }
            self::$cache[$key] = $can;
        }
        return self::$cache[$key];
    }

    /**
     * Reset the capability cache (for unit tests, or after changing user).
     */
    public static function reset_cache(): void {
        self::$cache = [];
    }
}
