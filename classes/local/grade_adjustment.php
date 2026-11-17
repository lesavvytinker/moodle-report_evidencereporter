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
 * The final result for an assessment when the gradebook adjusts it, e.g. late or academic misconduct penalties.
 *
 * Many providers put an assessment in its own gradebook category with manual "penalty" items, and the category total
 * (or a calculation) is the real final result. This finds that case and explains it: the final result, the penalties
 * with their feedback, the calculation, and any hand override. Integrity-related penalties are flagged, since they can
 * mean the evidence itself is in question.
 *
 * A category counts as the assessment's own only when this activity is the only activity in it and it has no
 * subcategories. A category holding several assessments says nothing about any one of them, so nothing is shown.
 *
 * Expected row fields: gradeitemid, finalgrade, islatest, cmid, modname, userid and the release fields.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_adjustment {
    /** Show the final result, the reasons, the calculation and integrity flags. */
    public const DETAIL_FULL = 'full';
    /** Show the final result marked "adjusted", without reasons. */
    public const DETAIL_FLAG = 'flag';
    /** Don't show adjustments. */
    public const DETAIL_OFF = 'off';

    /** Default words that mark a penalty as an academic integrity matter. */
    public const DEFAULT_INTEGRITY_WORDS = 'misconduct, plagiarism, integrity, cheating, collusion';

    /** @var array Category structure cached by activity grade item ID. */
    private static array $structure = [];

    /**
     * Detail level from settings.
     *
     * @return string One of the DETAIL_ constants
     */
    public static function get_detail(): string {
        $detail = (string) get_config('report_evidencereporter', 'adjustmentdetail');
        return in_array($detail, [self::DETAIL_FULL, self::DETAIL_FLAG, self::DETAIL_OFF]) ? $detail : self::DETAIL_FULL;
    }

    /**
     * Words that mark a penalty as an academic integrity matter, lower case.
     *
     * @return string[]
     */
    public static function get_integrity_words(): array {
        $words = get_config('report_evidencereporter', 'integritywords');
        if ($words === false) {
            $words = self::DEFAULT_INTEGRITY_WORDS;
        }
        return array_values(array_filter(array_map(
            fn($w) => \core_text::strtolower(trim($w)),
            explode(',', (string) $words)
        ), fn($w) => $w !== ''));
    }

    /**
     * The adjustment for an evidence row, or null when the result isn't adjusted (or can't be shown).
     *
     * @param stdClass $row
     * @param identity|null $identity Redaction for feedback text
     * @return stdClass|null {finalgrade, detail, reasons: [{name, value, feedback}], calculation, overridden,
     *     overridefeedback, integrity}
     */
    public static function for_row(stdClass $row, ?identity $identity = null): ?stdClass {
        if (
            empty($row->gradeitemid) || !isset($row->finalgrade) || $row->finalgrade === null || empty($row->islatest)
                || empty($row->userid)
        ) {
            return null;
        }
        if (($detail = self::get_detail()) === self::DETAIL_OFF) {
            return null;
        }
        // Same release rule as the activity grade: nothing extra about a grade the viewer can't see yet.
        if (
            !feedback_visibility::is_released($row)
                && !feedback_visibility::can_view_unreleased((int) $row->cmid, (string) $row->modname)
        ) {
            return null;
        }
        return self::get((int) $row->gradeitemid, (int) $row->userid, (float) $row->finalgrade, $detail, $identity);
    }

    /**
     * The adjustment for a learner's grade on an activity grade item.
     *
     * @param int $gradeitemid Activity grade item
     * @param int $userid Learner
     * @param float $activitygrade The learner's grade on the activity
     * @param string $detail One of the DETAIL_ constants
     * @param identity|null $identity
     * @return stdClass|null
     */
    public static function get(
        int $gradeitemid,
        int $userid,
        float $activitygrade,
        string $detail = self::DETAIL_FULL,
        ?identity $identity = null
    ): ?stdClass {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        if (!$structure = self::get_structure($gradeitemid)) {
            return null;
        }
        $item = $structure->item;
        $catitem = $structure->categoryitem;
        $context = \context_course::instance($item->courseid);
        $canviewhidden = has_capability('moodle/grade:viewhidden', $context);

        $catgrade = \grade_grade::fetch(['itemid' => $catitem->id, 'userid' => $userid]);
        if (!$catgrade || $catgrade->finalgrade === null) {
            return null;
        }
        if (!$canviewhidden && ($catitem->is_hidden() || $catgrade->is_hidden())) {
            return null;
        }

        $overridden = !empty($catgrade->overridden);
        if (!$overridden && !self::differs($item, $activitygrade, $catitem, (float) $catgrade->finalgrade)) {
            return null;
        }

        $result = (object) [
            'finalgrade' => activity_grade::format_value((int) $catitem->id, (float) $catgrade->finalgrade),
            'detail' => $detail === self::DETAIL_FULL,
            'reasons' => [],
            'calculation' => '',
            'overridden' => $overridden,
            'overridefeedback' => '',
            'integrity' => false,
        ];
        if (!$result->detail) {
            return $result;
        }

        // The penalties: other items in the category with a grade.
        $words = self::get_integrity_words();
        foreach ($structure->others as $other) {
            $grade = \grade_grade::fetch(['itemid' => $other->id, 'userid' => $userid]);
            if (!$grade || $grade->finalgrade === null) {
                continue;
            }
            if ((int) $other->gradetype === GRADE_TYPE_VALUE && abs((float) $grade->finalgrade) < 0.00001) {
                continue;
            }
            if (!$canviewhidden && ($other->is_hidden() || $grade->is_hidden())) {
                continue;
            }
            $name = $other->get_name(true);
            $result->reasons[] = (object) [
                'name' => $name,
                'value' => grade_format_gradevalue((float) $grade->finalgrade, $other, true),
                'feedback' => self::feedback_html($grade, $context, $identity),
            ];
            $lower = \core_text::strtolower($name);
            foreach ($words as $word) {
                if (\core_text::strpos($lower, $word) !== false) {
                    $result->integrity = true;
                }
            }
        }

        if (!$overridden && $catitem->is_calculated()) {
            $result->calculation = self::readable_calculation($catitem);
        }
        if ($overridden) {
            $result->overridefeedback = self::feedback_html($catgrade, $context, $identity);
        }
        return $result;
    }

    /**
     * HTML for the adjustment, for the summary page, the report and the evidence pack.
     *
     * @param stdClass $adjustment From get()
     * @return string
     */
    public static function to_html(stdClass $adjustment): string {
        $html = '<div class="er-adjustment">'
            . s(get_string('finalresult', 'report_evidencereporter')) . ': <strong>' . s($adjustment->finalgrade) . '</strong> '
            . '<span class="badge bg-warning text-dark">' . s(get_string('finalresultadjusted', 'report_evidencereporter'))
            . '</span>';
        if ($adjustment->reasons) {
            $html .= '<br>' . s(get_string('adjustedby', 'report_evidencereporter')) . ':<ul class="mb-0">';
            foreach ($adjustment->reasons as $reason) {
                $html .= '<li>' . s($reason->name) . ': ' . s($reason->value)
                    . ($reason->feedback !== '' ? '<br>' . $reason->feedback : '') . '</li>';
            }
            $html .= '</ul>';
        }
        if ($adjustment->calculation !== '') {
            $html .= '<br>' . s(get_string('adjustmentcalculation', 'report_evidencereporter')) . ': '
                . s($adjustment->calculation);
        }
        if ($adjustment->overridden && $adjustment->detail) {
            $html .= '<br>' . s(get_string('adjustmentoverridden', 'report_evidencereporter'))
                . ($adjustment->overridefeedback !== '' ? '<br>' . $adjustment->overridefeedback : '');
        }
        if ($adjustment->integrity) {
            $html .= '<br><strong class="text-danger">' . s(get_string('adjustmentintegrity', 'report_evidencereporter'))
                . '</strong>';
        }
        return $html . '</div>';
    }

    /**
     * HTML for a row, or empty string.
     *
     * @param stdClass $row
     * @param identity|null $identity
     * @return string
     */
    public static function row_html(stdClass $row, ?identity $identity = null): string {
        $adjustment = self::for_row($row, $identity);
        return $adjustment ? self::to_html($adjustment) : '';
    }

    /**
     * The activity's own gradebook category, its total, and the other items in it; null when it isn't the activity's own.
     *
     * @param int $gradeitemid
     * @return stdClass|null {item, categoryitem, others}
     */
    private static function get_structure(int $gradeitemid): ?stdClass {
        if (array_key_exists($gradeitemid, self::$structure)) {
            return self::$structure[$gradeitemid];
        }
        $result = null;
        $item = \grade_item::fetch(['id' => $gradeitemid]);
        if ($item && $item->categoryid && ($category = \grade_category::fetch(['id' => $item->categoryid]))) {
            // Not the course total, and no subcategories.
            $owncategory = !empty($category->parent)
                && !\grade_category::fetch_all(['parent' => $category->id]);
            $others = [];
            if ($owncategory) {
                foreach (\grade_item::fetch_all(['categoryid' => $category->id]) ?: [] as $other) {
                    if ((int) $other->id === (int) $item->id) {
                        continue;
                    }
                    if ($other->itemtype === 'mod') {
                        // Another grade from the same activity (e.g. forum ratings) doesn't change whose category it is.
                        if ($other->itemmodule === $item->itemmodule && $other->iteminstance == $item->iteminstance) {
                            continue;
                        }
                        $owncategory = false;
                        break;
                    }
                    if ($other->itemtype === 'manual' && (int) $other->gradetype !== GRADE_TYPE_NONE) {
                        $others[] = $other;
                    }
                }
            }
            if ($owncategory && ($categoryitem = $category->load_grade_item())) {
                usort($others, fn($a, $b) => $a->sortorder <=> $b->sortorder);
                $result = (object) ['item' => $item, 'categoryitem' => $categoryitem, 'others' => $others];
            }
        }
        self::$structure[$gradeitemid] = $result;
        return $result;
    }

    /**
     * Does the category total differ from the activity grade, comparing them as a fraction of each one's range?
     *
     * @param \grade_item $item
     * @param float $grade
     * @param \grade_item $catitem
     * @param float $catgrade
     * @return bool
     */
    private static function differs(\grade_item $item, float $grade, \grade_item $catitem, float $catgrade): bool {
        $fraction = function (\grade_item $i, float $value): ?float {
            $range = (float) $i->grademax - (float) $i->grademin;
            return $range > 0 ? ($value - (float) $i->grademin) / $range : null;
        };
        $a = $fraction($item, $grade);
        $b = $fraction($catitem, $catgrade);
        if ($a === null || $b === null) {
            return abs($grade - $catgrade) > 0.00001;
        }
        return abs($a - $b) > 0.00005;
    }

    /**
     * The category's calculation with grade item names in place of ID numbers, e.g. "AT1 Essay - Late penalty".
     *
     * @param \grade_item $catitem
     * @return string
     */
    private static function readable_calculation(\grade_item $catitem): string {
        $formula = ltrim((string) $catitem->get_calculation(), '= ');
        $names = [];
        foreach (\grade_item::fetch_all(['courseid' => $catitem->courseid]) ?: [] as $i) {
            if ((string) $i->idnumber !== '') {
                $names[$i->idnumber] = $i->get_name(true);
            }
        }
        // Spaces around operators outside the [[references]], then names in place of references.
        $parts = preg_split('/(\[\[.+?\]\])/', $formula, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        foreach ($parts as $part) {
            if (preg_match('/^\[\[(.+)\]\]$/', $part, $m)) {
                $out .= $names[$m[1]] ?? $m[1];
            } else {
                $out .= preg_replace('/\s*([+*\/]|(?<![(,\s])-)\s*/', ' $1 ', $part);
            }
        }
        return trim(preg_replace('/\s+/', ' ', $out));
    }

    /**
     * Formatted, redacted feedback on a grade, or empty string.
     *
     * @param \grade_grade $grade
     * @param \context $context
     * @param identity|null $identity
     * @return string
     */
    private static function feedback_html(\grade_grade $grade, \context $context, ?identity $identity): string {
        if ($grade->feedback === null || trim(strip_tags((string) $grade->feedback)) === '') {
            return '';
        }
        $html = trim(format_text($grade->feedback, $grade->feedbackformat ?? FORMAT_HTML, ['context' => $context]));
        // One paragraph: drop the wrapper, so it sits under the penalty without a gap.
        if (preg_match('~^<p[^>]*>(.*)</p>$~s', $html, $m) && stripos($m[1], '<p') === false) {
            $html = $m[1];
        }
        return evidence_link::new_tab_html($identity ? $identity->text($html) : $html);
    }

    /**
     * Reset the cache (for unit tests).
     */
    public static function reset_cache(): void {
        self::$structure = [];
    }
}
