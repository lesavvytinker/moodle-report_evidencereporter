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
 * A filled-in rubric or marking guide as a simple table, for the summary page and the PDF alike.
 *
 * Moodle's own renderers draw interactive grids that don't survive PDF conversion, so this reads the filling and writes
 * one row per criterion: the criterion, the level chosen (or score) and the marker's remark.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class advanced_grading {
    /**
     * Table of the active rubric or marking guide filling for a graded item, or empty when there is none.
     *
     * @param \context $context The activity's context
     * @param string $component e.g. mod_forum
     * @param string $area e.g. forum
     * @param int $itemid The grade record's ID (e.g. forum_grades.id)
     * @param identity|null $identity Redaction for remarks
     * @return string HTML
     */
    public static function table_html(
        \context $context,
        string $component,
        string $area,
        int $itemid,
        ?identity $identity = null
    ): string {
        global $CFG;
        require_once($CFG->dirroot . '/grade/grading/lib.php');
        try {
            $manager = get_grading_manager($context, $component, $area);
            $method = $manager->get_active_method();
            if (!in_array($method, ['rubric', 'guide'], true)) {
                return '';
            }
            $controller = $manager->get_controller($method);
            if (!$controller->is_form_available()) {
                return '';
            }
            $instances = $controller->get_active_instances($itemid);
            $instance = reset($instances);
            if (!$instance) {
                return '';
            }
            $definition = $controller->get_definition();
        } catch (\Throwable $e) {
            return '';
        }
        $text = fn(?string $value) => s(trim(html_to_text((string) $value, 0, false)));
        $remark = fn(?string $value) => $value === null || trim($value) === '' ? ''
            : s($identity ? $identity->text(trim($value)) : trim($value));

        $rows = [];
        if ($method === 'rubric') {
            $filling = $instance->get_rubric_filling();
            foreach ($definition->rubric_criteria ?? [] as $criterionid => $criterion) {
                $fill = $filling['criteria'][$criterionid] ?? null;
                $level = $fill ? ($criterion['levels'][$fill['levelid']] ?? null) : null;
                $rows[] = [$text($criterion['description']),
                    $level ? $text($level['definition']) . ' (' . format_float((float) $level['score'], -1) . ')' : '-',
                    $remark($fill['remark'] ?? null)];
            }
            $heads = [get_string('criterion', 'report_evidencereporter'), get_string('level', 'report_evidencereporter'),
                get_string('remark', 'report_evidencereporter')];
        } else {
            $filling = $instance->get_guide_filling();
            foreach ($definition->guide_criteria ?? [] as $criterionid => $criterion) {
                $fill = $filling['criteria'][$criterionid] ?? null;
                $rows[] = [$text($criterion['shortname']),
                    $fill ? format_float((float) $fill['score'], -1) . ' / ' . format_float((float) $criterion['maxscore'], -1)
                        : '-',
                    $remark($fill['remark'] ?? null)];
            }
            $heads = [get_string('criterion', 'report_evidencereporter'), get_string('score', 'report_evidencereporter'),
                get_string('remark', 'report_evidencereporter')];
        }
        if (!$rows) {
            return '';
        }
        $html = '<table class="table table-sm table-bordered er-grading" border="1" cellpadding="3" width="100%"><thead><tr>';
        foreach ($heads as $head) {
            $html .= '<th>' . s($head) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as [$criterion, $result, $note]) {
            $html .= "<tr><td>{$criterion}</td><td>{$result}</td><td>{$note}</td></tr>";
        }
        return $html . '</tbody></table>';
    }
}
