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

/**
 * Turns a competency rating (scale value) into its scale item name, e.g. 2 → "Competent".
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rating {
    /** @var array Scale items cached per competency ID. */
    private static array $cache = [];

    /**
     * Scale item name for a rating on a competency.
     *
     * @param int $competencyid
     * @param int|null $grade
     * @return string Empty string when there is no rating.
     */
    public static function name(int $competencyid, ?int $grade): string {
        if ($grade === null || $competencyid <= 0) {
            return '';
        }
        if (!array_key_exists($competencyid, self::$cache)) {
            self::$cache[$competencyid] = null;
            if ($competency = \core_competency\competency::get_record(['id' => $competencyid])) {
                $scale = $competency->get_scale();
                $scale->load_items();
                self::$cache[$competencyid] = $scale->scale_items;
            }
        }
        $items = self::$cache[$competencyid];
        return $items[$grade - 1] ?? (string) $grade;
    }

    /**
     * Clear the cache (for tests, where IDs are reused).
     */
    public static function reset_cache(): void {
        self::$cache = [];
    }
}
