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
 * Ratings and comments on database activity entries.
 *
 * Database activities have no submissions or feedback of their own: assessors rate entries (Ratings) and discuss
 * them in the entry's comments. These helpers read both, so every rated entry can be shown as an assessment, with
 * its comments as the feedback.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class data_entries {
    /**
     * SQL: does the entry with ID field $recordidsql have at least one rating?
     *
     * @param string $recordidsql
     * @param string $alias Unique alias for the rating table
     * @return string
     */
    public static function rated_sql(string $recordidsql, string $alias = 'errt'): string {
        return "EXISTS (SELECT 1 FROM {rating} {$alias} WHERE {$alias}.component = 'mod_data'
                         AND {$alias}.ratingarea = 'entry' AND {$alias}.itemid = {$recordidsql})";
    }

    /**
     * SQL: the learner's latest rated entry, else their latest entry, in a database.
     *
     * @param string $dataidsql
     * @param string $useridsql
     * @return string
     */
    public static function latest_entry_sql(string $dataidsql, string $useridsql): string {
        return "COALESCE(
                    (SELECT MAX(erlr.id) FROM {data_records} erlr
                      WHERE erlr.dataid = {$dataidsql} AND erlr.userid = {$useridsql}
                        AND " . self::rated_sql('erlr.id', 'erlrt') . "),
                    (SELECT MAX(erla.id) FROM {data_records} erla
                      WHERE erla.dataid = {$dataidsql} AND erla.userid = {$useridsql}))";
    }

    /**
     * Comments on an entry, oldest first.
     *
     * @param int $recordid
     * @param int $cmid
     * @param bool $viewfullnames
     * @return stdClass[] author, time (int), html, plain
     */
    public static function get_comments(int $recordid, int $cmid, bool $viewfullnames): array {
        global $DB;
        $context = \context_module::instance($cmid, IGNORE_MISSING);
        if (!$context) {
            return [];
        }
        $namefields = implode(', ', array_map(fn($f) => "u.{$f}", \core_user\fields::get_name_fields()));
        $records = $DB->get_records_sql(
            "SELECT c.id, c.content, c.format, c.timecreated, c.userid AS authorid, {$namefields}
                                           FROM {comments} c
                                           JOIN {user} u ON u.id = c.userid
                                          WHERE c.contextid = :contextid AND c.component = 'mod_data'
                                            AND c.commentarea = 'database_entry' AND c.itemid = :itemid
                                       ORDER BY c.timecreated, c.id",
            ['contextid' => $context->id, 'itemid' => $recordid]
        );
        $comments = [];
        foreach ($records as $record) {
            $html = format_text($record->content, (int) $record->format, ['context' => $context]);
            $comments[] = (object) [
                'author' => fullname($record, $viewfullnames),
                'authorid' => (int) $record->authorid,
                'time' => (int) $record->timecreated,
                'html' => $html,
                'plain' => trim(html_to_text($html, 0, false)),
            ];
        }
        return $comments;
    }

    /**
     * Comments as HTML: each with who wrote it and when.
     *
     * @param stdClass[] $comments From {@see get_comments()}
     * @param identity|null $identity Redaction for authors and text
     * @return string
     */
    public static function comments_html(array $comments, ?identity $identity = null): string {
        $parts = [];
        foreach ($comments as $comment) {
            $author = $identity ? $identity->person($comment->authorid, $comment->author) : $comment->author;
            $html = $identity ? $identity->text($comment->html) : $comment->html;
            $parts[] = \html_writer::div(\html_writer::tag('strong', s($author)) . ' · '
                . \html_writer::span(
                    dates::datetime($comment->time),
                    'text-muted'
                ) . $html, 'er-comment mb-2');
        }
        return implode('', $parts);
    }

    /**
     * Comments as plain text, one per line.
     *
     * @param stdClass[] $comments
     * @param identity|null $identity Redaction for authors and text
     * @return string
     */
    public static function comments_plain(array $comments, ?identity $identity = null): string {
        return implode("\n", array_map(fn($c) => ($identity ? $identity->person($c->authorid, $c->author) : $c->author)
            . ' (' . dates::datetime($c->time) . '): '
            . ($identity ? $identity->text($c->plain) : $c->plain), $comments));
    }

    /**
     * Ratings on an entry, oldest first.
     *
     * @param int $recordid
     * @param bool $viewfullnames
     * @return stdClass[] rater, raterid, rating (int), scaleid, time
     */
    public static function get_ratings(int $recordid, bool $viewfullnames): array {
        global $DB;
        $namefields = implode(', ', array_map(fn($f) => "u.{$f}", \core_user\fields::get_name_fields()));
        $records = $DB->get_records_sql("SELECT r.id, r.rating, r.scaleid, r.timemodified, r.userid AS raterid, {$namefields}
                                           FROM {rating} r
                                           JOIN {user} u ON u.id = r.userid
                                          WHERE r.component = 'mod_data' AND r.ratingarea = 'entry' AND r.itemid = :itemid
                                       ORDER BY r.timemodified, r.id", ['itemid' => $recordid]);
        return array_values(array_map(fn($r) => (object) [
            'rater' => fullname($r, $viewfullnames),
            'raterid' => (int) $r->raterid,
            'rating' => (int) $r->rating,
            'scaleid' => (int) $r->scaleid,
            'time' => (int) $r->timemodified,
        ], $records));
    }

    /**
     * Display text for one rating value: a scale item name, or "7 / 10" for point ratings.
     *
     * @param int $scaleid Rating scaleid: negative for a scale, positive for a maximum of points
     * @param int $rating
     * @return string
     */
    public static function rating_text(int $scaleid, int $rating): string {
        global $DB;
        static $scales = [];
        if ($scaleid < 0) {
            $id = -$scaleid;
            if (!array_key_exists($id, $scales)) {
                $scale = $DB->get_field('scale', 'scale', ['id' => $id]);
                $scales[$id] = $scale ? array_map('trim', explode(',', $scale)) : [];
            }
            return $scales[$id][$rating - 1] ?? (string) $rating;
        }
        return $rating . ' / ' . $scaleid;
    }
}
