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

use html_writer;
use moodle_url;
use stdClass;

/**
 * Checklist activities (mod_checklist, an add-on): each item's mark and the comments on it, as feedback.
 *
 * Everything here checks {@see is_installed()} first, so sites without the Checklist plugin are unaffected.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checklist_items {
    /** @var int Teacher mark: not yet marked. */
    public const MARK_UNDECIDED = 0;

    /** @var int Teacher mark: yes. */
    public const MARK_YES = 1;

    /** @var int Teacher mark: no. */
    public const MARK_NO = 2;

    /** @var int Marking by students only. */
    public const MARKING_STUDENT = 0;

    /** @var int Item is a heading, not something to check off. */
    public const ITEM_HEADING = 2;

    /** @var bool|null Cached installed check. */
    private static ?bool $installed = null;

    /**
     * Is the Checklist activity installed on this site?
     *
     * @return bool
     */
    public static function is_installed(): bool {
        global $DB;
        if (self::$installed === null) {
            self::$installed = \core_component::get_component_directory('mod_checklist') !== null
                && $DB->get_manager()->table_exists('checklist_check');
        }
        return self::$installed;
    }

    /**
     * Link to this learner's checklist report: their marks and the comments, on one page.
     *
     * @param int $cmid
     * @param int $userid
     * @return moodle_url
     */
    public static function url(int $cmid, int $userid): moodle_url {
        return new moodle_url('/mod/checklist/report.php', ['id' => $cmid, 'studentid' => $userid]);
    }

    /**
     * The learner's checklist: every item with its marks and comments, in checklist order.
     *
     * @param int $cmid
     * @param int $userid
     * @param bool $viewfullnames
     * @return stdClass|null name, marking (teacheredit), items[] (text, isheading, studentchecked, studenttime,
     *     teachermark, teachertime, teacher, teacherid, comments[] (author, authorid, text, isstudent)), or null
     */
    public static function get_for_user(int $cmid, int $userid, bool $viewfullnames): ?stdClass {
        global $DB;
        if (!self::is_installed()) {
            return null;
        }
        $checklist = $DB->get_record_sql(
            "SELECT ch.*
               FROM {checklist} ch
               JOIN {course_modules} cm ON cm.instance = ch.id
               JOIN {modules} m ON m.id = cm.module AND m.name = 'checklist'
              WHERE cm.id = :cmid",
            ['cmid' => $cmid]
        );
        if (!$checklist) {
            return null;
        }

        $items = $DB->get_records_sql(
            "SELECT i.id, i.displaytext, i.itemoptional, i.position, i.userid AS owner,
                    ck.usertimestamp, ck.teachermark, ck.teachertimestamp, ck.teacherid
               FROM {checklist_item} i
          LEFT JOIN {checklist_check} ck ON ck.item = i.id AND ck.userid = :userid
              WHERE i.checklist = :checklist AND i.hidden = 0 AND (i.userid = 0 OR i.userid = :owner)
           ORDER BY i.position, i.id",
            ['userid' => $userid, 'checklist' => $checklist->id, 'owner' => $userid]
        );
        if (!$items) {
            return (object) ['name' => $checklist->name, 'marking' => (int) $checklist->teacheredit, 'items' => []];
        }

        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        [$insql, $params] = $DB->get_in_or_equal(array_keys($items), SQL_PARAMS_NAMED);
        $teachers = [];
        $teacherids = array_filter(array_unique(array_map(fn($i) => (int) $i->teacherid, $items)));
        if ($teacherids) {
            [$tsql, $tparams] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 't');
            foreach ($DB->get_records_sql("SELECT u.id, {$namefields} FROM {user} u WHERE u.id {$tsql}", $tparams) as $u) {
                $teachers[$u->id] = fullname($u, $viewfullnames);
            }
        }

        $comments = [];
        $teachercomments = $DB->get_records_sql(
            "SELECT c.id, c.itemid, c.text, c.commentby AS authorid, {$namefields}
               FROM {checklist_comment} c
          LEFT JOIN {user} u ON u.id = c.commentby
              WHERE c.userid = :userid AND c.itemid {$insql}
           ORDER BY c.id",
            ['userid' => $userid] + $params
        );
        foreach ($teachercomments as $c) {
            if (trim((string) $c->text) === '') {
                continue;
            }
            $comments[$c->itemid][] = (object) ['author' => $c->authorid ? fullname($c, $viewfullnames) : '',
                'authorid' => (int) $c->authorid, 'text' => (string) $c->text, 'isstudent' => false];
        }
        if ($DB->get_manager()->table_exists('checklist_comment_student')) {
            $studentcomments = $DB->get_records_sql(
                "SELECT c.id, c.itemid, c.text, c.usermodified AS authorid, {$namefields}
                   FROM {checklist_comment_student} c
                   JOIN {user} u ON u.id = c.usermodified
                  WHERE c.usermodified = :userid AND c.itemid {$insql}
               ORDER BY c.id",
                ['userid' => $userid] + $params
            );
            foreach ($studentcomments as $c) {
                if (trim((string) $c->text) === '') {
                    continue;
                }
                $comments[$c->itemid][] = (object) ['author' => fullname($c, $viewfullnames),
                    'authorid' => (int) $c->authorid, 'text' => (string) $c->text, 'isstudent' => true];
            }
        }

        $result = [];
        foreach ($items as $item) {
            $result[] = (object) [
                'text' => (string) $item->displaytext,
                'isheading' => (int) $item->itemoptional === self::ITEM_HEADING,
                'studentchecked' => !empty($item->usertimestamp),
                'studenttime' => (int) $item->usertimestamp,
                'teachermark' => (int) $item->teachermark,
                'teachertime' => (int) $item->teachertimestamp,
                'teacherid' => (int) $item->teacherid,
                'teacher' => $teachers[(int) $item->teacherid] ?? '',
                'comments' => $comments[$item->id] ?? [],
            ];
        }
        return (object) ['name' => $checklist->name, 'marking' => (int) $checklist->teacheredit, 'items' => $result];
    }

    /**
     * The mark shown for one item: the assessor's Yes / No, or the learner's tick when only learners mark.
     *
     * @param stdClass $item
     * @param int $marking
     * @return string
     */
    public static function mark(stdClass $item, int $marking): string {
        if ($marking === self::MARKING_STUDENT) {
            return $item->studentchecked ? get_string('checklistticked', 'report_evidencereporter') : '';
        }
        return match ($item->teachermark) {
            self::MARK_YES => get_string('yes'),
            self::MARK_NO => get_string('no'),
            default => '',
        };
    }

    /**
     * The checklist as feedback: each item, its mark, and the comments on it.
     *
     * @param stdClass $checklist From {@see get_for_user()}
     * @param identity|null $identity Redaction for comment authors and text
     * @return string HTML
     */
    public static function feedback_html(stdClass $checklist, ?identity $identity = null): string {
        $lines = [];
        foreach ($checklist->items as $item) {
            $text = s($identity ? $identity->text($item->text) : $item->text);
            if ($item->isheading) {
                $lines[] = html_writer::div(html_writer::tag('strong', $text), 'er-checklist-heading mt-2');
                continue;
            }
            $mark = self::mark($item, $checklist->marking);
            $line = $text . ': ' . html_writer::tag('strong', s($mark !== '' ? $mark
                : get_string('checklistnotmarked', 'report_evidencereporter')));
            $line .= self::comments_html($item, $identity);
            $lines[] = html_writer::div($line, 'er-checklist-item');
        }
        return implode('', $lines);
    }

    /**
     * The comments on one item.
     *
     * @param stdClass $item
     * @param identity|null $identity
     * @return string HTML
     */
    private static function comments_html(stdClass $item, ?identity $identity): string {
        $html = '';
        foreach ($item->comments as $comment) {
            $author = $identity ? $identity->person($comment->authorid, $comment->author) : $comment->author;
            $text = $identity ? $identity->text($comment->text) : $comment->text;
            $html .= html_writer::div(
                ($author !== '' ? html_writer::tag('em', s($author)) . ': ' : '') . s($text),
                'er-checklist-comment ms-3'
            );
        }
        return $html;
    }

    /**
     * The checklist as plain text, one item per line.
     *
     * @param stdClass $checklist From {@see get_for_user()}
     * @return string
     */
    public static function feedback_plain(stdClass $checklist): string {
        $lines = [];
        foreach ($checklist->items as $item) {
            if ($item->isheading) {
                continue;
            }
            $mark = self::mark($item, $checklist->marking);
            $line = $item->text . ': ' . ($mark !== '' ? $mark : get_string('checklistnotmarked', 'report_evidencereporter'));
            foreach ($item->comments as $comment) {
                $line .= ' (' . ($comment->author !== '' ? $comment->author . ': ' : '') . $comment->text . ')';
            }
            $lines[] = $line;
        }
        return implode('; ', $lines);
    }

    /**
     * Item table for the evidence pack's checklist record.
     *
     * @param stdClass $checklist From {@see get_for_user()}
     * @param identity $identity
     * @param bool $withfeedback Include the comments column
     * @return string HTML table
     */
    public static function record_table(stdClass $checklist, identity $identity, bool $withfeedback = true): string {
        $head = [get_string('checklistitem', 'report_evidencereporter'), get_string('checklistmark', 'report_evidencereporter'),
            get_string('marker', 'report_evidencereporter'), get_string('date')];
        if ($withfeedback) {
            $head[] = get_string('feedback', 'report_evidencereporter');
        }
        $html = '<table border="1" cellpadding="4" width="100%"><tr>';
        foreach ($head as $cell) {
            $html .= '<th bgcolor="#eeeeee"><strong>' . s($cell) . '</strong></th>';
        }
        $html .= '</tr>';
        $format = get_string('strftimedatetimeshort', 'core_langconfig');
        foreach ($checklist->items as $item) {
            $text = s($identity->text($item->text));
            if ($item->isheading) {
                $html .= '<tr><td colspan="' . count($head) . '"><strong>' . $text . '</strong></td></tr>';
                continue;
            }
            $mark = self::mark($item, $checklist->marking);
            $time = $checklist->marking === self::MARKING_STUDENT ? $item->studenttime : $item->teachertime;
            $html .= '<tr><td>' . $text . '</td>'
                . '<td>' . s($mark !== '' ? $mark : get_string('checklistnotmarked', 'report_evidencereporter')) . '</td>'
                . '<td>' . s($identity->person($item->teacherid, $item->teacher)) . '</td>'
                . '<td>' . ($time ? s(userdate($time, $format)) : '') . '</td>'
                . ($withfeedback ? '<td>' . self::comments_html($item, $identity) . '</td>' : '') . '</tr>';
        }
        return $html . '</table>';
    }

    /**
     * Checklists linked to competencies (in this framework) where the learner has a mark or a comment.
     *
     * @param int $userid
     * @param int|null $frameworkid
     * @param int[]|null $competencyids Limit to these competencies
     * @return stdClass[] id, competencyid, cmid, course
     */
    public static function get_linked(int $userid, ?int $frameworkid = null, ?array $competencyids = null): array {
        global $DB;
        if (!self::is_installed()) {
            return [];
        }
        $sql = "SELECT mc.id, mc.competencyid, mc.cmid, cm.course
                  FROM {competency_modulecomp} mc
                  JOIN {competency} c ON c.id = mc.competencyid
                  JOIN {course_modules} cm ON cm.id = mc.cmid
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'checklist'
                 WHERE (EXISTS (SELECT 1 FROM {checklist_item} ci
                                  JOIN {checklist_check} cc ON cc.item = ci.id
                                 WHERE ci.checklist = cm.instance AND cc.userid = :u1
                                   AND (cc.teachermark > 0 OR cc.usertimestamp > 0))
                        OR EXISTS (SELECT 1 FROM {checklist_item} ci2
                                     JOIN {checklist_comment} cco ON cco.itemid = ci2.id
                                    WHERE ci2.checklist = cm.instance AND cco.userid = :u2))";
        $params = ['u1' => $userid, 'u2' => $userid];
        if ($frameworkid) {
            $sql .= ' AND c.competencyframeworkid = :frameworkid';
            $params['frameworkid'] = $frameworkid;
        }
        if ($competencyids !== null) {
            if (!$competencyids) {
                return [];
            }
            [$insql, $inparams] = $DB->get_in_or_equal($competencyids, SQL_PARAMS_NAMED, 'cc');
            $sql .= " AND c.id {$insql}";
            $params += $inparams;
        }
        if ($access = access::get_sql((string) $userid, 'cm.course')) {
            $sql .= ' AND ' . $access[0];
            $params += $access[1];
        }
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Reset the cached installed check (unit tests).
     */
    public static function reset_cache(): void {
        self::$installed = null;
    }
}
