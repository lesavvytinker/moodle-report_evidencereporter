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

use report_evidencereporter\local\source\base;

/**
 * The evidence sources available on this site: the built-in ones plus any other plugins add.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sources {
    /** @var base[]|null Installed sources by module name, cached for the request. */
    private static ?array $sources = null;

    /**
     * All installed sources.
     *
     * @return base[] modname => source
     */
    public static function all(): array {
        if (self::$sources === null) {
            $sources = [];
            foreach (
                [source\assign::class, source\quiz::class, source\data::class, source\forum::class,
                    source\checklist::class, source\h5pactivity::class, source\scorm::class, source\workshop::class,
                    source\attendance::class] as $class
            ) {
                $source = new $class();
                $sources[$source->get_modname()] = $source;
            }
            // Other plugins can add sources, or replace a built-in one.
            $hook = new \report_evidencereporter\hook\collect_sources();
            \core\di::get(\core\hook\manager::class)->dispatch($hook);
            $sources = array_merge($sources, $hook->get_sources());
            self::$sources = array_filter($sources, fn(base $source) => $source->is_installed());
        }
        return self::$sources;
    }

    /**
     * The source for an activity type, if there is one.
     *
     * @param string|null $modname
     * @return base|null
     */
    public static function get(?string $modname): ?base {
        return $modname === null || $modname === '' ? null : (self::all()[$modname] ?? null);
    }

    /**
     * Extra row fields every source needs, as SQL: each field is evaluated only for its own activity type.
     *
     * @param string $modname SQL for the module name (e.g. m.name)
     * @param string $instance SQL for the activity instance ID
     * @param string $userid SQL for the learner's ID
     * @return array field name => SQL
     */
    public static function row_fields_sql(string $modname, string $instance, string $userid): array {
        $cases = [];
        foreach (self::all() as $name => $source) {
            foreach ($source->get_row_fields_sql($instance, $userid) as $field => $sql) {
                $cases[$field][] = "WHEN {$modname} = '{$name}' THEN {$sql}";
            }
        }
        $fields = [];
        foreach ($cases as $field => $whens) {
            $fields[$field] = 'CASE ' . implode(' ', $whens) . ' ELSE NULL END';
        }
        return $fields;
    }

    /**
     * Field names {@see row_fields_sql()} adds.
     *
     * @return string[]
     */
    public static function row_field_names(): array {
        return array_keys(self::row_fields_sql('m', 'i', 'u'));
    }

    /**
     * Feedback for an evidence row with release and superseded rules applied (shared by the report and the summary).
     *
     * @param \stdClass $row Evidence row: modname, cmid, courseid, userid, islatest, feedback, feedbackformat, the
     *     release fields, and the sources' row fields
     * @param bool $viewfullnames
     * @param identity|null $identity
     * @return \stdClass html (string), status ('' | 'released' | 'notreleased' | 'pendingrelease' | 'superseded'),
     *     fromsource (bool: the source supplied it, rather than the gradebook)
     */
    public static function feedback(\stdClass $row, bool $viewfullnames, ?identity $identity = null): \stdClass {
        $result = (object) ['html' => '', 'status' => '', 'fromsource' => false];
        $source = self::get($row->modname ?? null);
        $cmid = (int) ($row->cmid ?? 0);
        $released = feedback_visibility::is_released($row);
        $canseeunreleased = $cmid && feedback_visibility::can_view_unreleased($cmid, (string) $row->modname);

        // Sources with their own feedback (database entry comments, checklist marks).
        if ($source && $cmid) {
            if ($source->shows_current_state() && empty($row->islatest)) {
                // Feedback shows the activity as it is now, so only the latest evidence row from it carries it.
                $result->html = s(get_string('feedbacksupersededlater', 'report_evidencereporter'));
                $result->status = 'superseded';
                $result->fromsource = true;
                return $result;
            }
            if ($source->has_own_feedback($row)) {
                $result->fromsource = true;
                if ($source->feedback_follows_release() && !$released && !$canseeunreleased) {
                    $result->html = s(get_string('pendingrelease', 'report_evidencereporter'));
                    $result->status = 'pendingrelease';
                    return $result;
                }
                $result->html = $source->get_feedback_html($row, $viewfullnames, $identity);
                $result->status = $result->html === '' || !$source->feedback_follows_release() ? ''
                    : ($released ? 'released' : 'notreleased');
                return $result;
            }
        }

        // Gradebook feedback.
        if (!isset($row->feedback) || trim((string) $row->feedback) === '') {
            return $result;
        }
        if (empty($row->islatest)) {
            $result->html = s(get_string('feedbacksuperseded', 'report_evidencereporter'));
            $result->status = 'superseded';
        } else if ($released || $canseeunreleased) {
            $context = \context_course::instance((int) $row->courseid, IGNORE_MISSING) ?: \context_system::instance();
            $html = format_text($row->feedback, (int) $row->feedbackformat, ['context' => $context]);
            $result->html = $identity ? $identity->text($html) : $html;
            $result->status = $released ? 'released' : 'notreleased';
        } else {
            $result->html = s(get_string('pendingrelease', 'report_evidencereporter'));
            $result->status = 'pendingrelease';
        }
        return $result;
    }

    /**
     * Forget the sources (unit tests).
     */
    public static function reset_cache(): void {
        self::$sources = null;
    }
}
