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

use moodle_url;
use stdClass;

/**
 * A learner's contributions to a forum linked to competencies: the whole-forum grade (with its rubric or marking guide),
 * the ratings result, and every post they wrote, each with what it replied to, its attachments, ratings and similarity
 * results.
 *
 * Whole-forum grading comes first; ratings follow. For a reply, the post it answered is shown for context. Another
 * learner's words are personal information about someone not being assessed, so when the parent was written by
 * another learner only "Another learner", its subject and a short excerpt are shown; staff are named (unless names are
 * redacted).
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forum_posts {
    /** Words of a parent post to show. */
    public const EXCERPT_WORDS = 30;

    /** @var array Contributions cached by cmid and userid. */
    private static array $cache = [];

    /**
     * Forums linked to competencies where the learner has posts, in courses the viewer may see.
     *
     * @param int $userid
     * @param int[]|null $competencyids
     * @return stdClass[] (competencyid, cmid, course)
     */
    public static function get_linked(int $userid, ?array $competencyids = null): array {
        return array_values(array_filter(
            summary::get_linked_activities($userid, null, $competencyids),
            fn($link) => $link->modname === 'forum'
        ));
    }

    /**
     * Everything the learner contributed to a forum, or null if there's nothing (or the forum is gone).
     *
     * @param int $cmid
     * @param int $userid
     * @return stdClass|null
     */
    public static function for_user(int $cmid, int $userid): ?stdClass {
        global $CFG, $DB;
        $key = $cmid . '-' . $userid;
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        require_once($CFG->libdir . '/gradelib.php');
        self::$cache[$key] = null;
        try {
            [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'forum');
        } catch (\Throwable $e) {
            return null;
        }
        $forum = $DB->get_record('forum', ['id' => $cm->instance], '*', IGNORE_MISSING);
        if (!$forum) {
            return null;
        }
        $context = \context_module::instance($cm->id);
        $coursecontext = \context_course::instance($course->id);
        $canviewhidden = has_capability('moodle/grade:viewhidden', $coursecontext);

        $posts = $DB->get_records_sql(
            "SELECT p.id, p.discussion, p.parent, p.subject, p.message, p.messageformat, p.created, p.userid,
                    d.name AS discussionname, d.firstpost
               FROM {forum_posts} p
               JOIN {forum_discussions} d ON d.id = p.discussion
              WHERE d.forum = :forum AND p.userid = :userid AND p.deleted = 0
           ORDER BY p.created, p.id",
            ['forum' => $forum->id, 'userid' => $userid]
        );

        // Parents of replies, and who wrote them.
        $parentids = array_unique(array_filter(array_map(fn($p) => (int) $p->parent, $posts)));
        $parents = $parentids ? $DB->get_records_list(
            'forum_posts',
            'id',
            $parentids,
            '',
            'id, subject, message, messageformat, userid, created'
        ) : [];
        $authors = [];
        foreach ($parents as $parent) {
            $authors[$parent->userid] ??= \core_user::get_user($parent->userid);
        }

        // Ratings on each post.
        $ratings = [];
        if ($posts && $forum->assessed) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($posts), SQL_PARAMS_NAMED);
            $params += ['contextid' => $context->id];
            foreach (
                $DB->get_records_select('rating', "component = 'mod_forum' AND ratingarea = 'post'
                    AND contextid = :contextid AND itemid {$insql}", $params, '', 'id, itemid, rating') as $r
            ) {
                $ratings[(int) $r->itemid][] = (float) $r->rating;
            }
        }

        // Grades: whole-forum grading (item 1) first, then ratings (item 0).
        $grades = [];
        foreach ([1 => 'wholeforum', 0 => 'ratings'] as $itemnumber => $name) {
            $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'forum', 'iteminstance' => $forum->id,
                'courseid' => $course->id, 'itemnumber' => $itemnumber]);
            if (!$item) {
                continue;
            }
            $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
            if (!$grade || $grade->finalgrade === null || (!$canviewhidden && ($item->is_hidden() || $grade->is_hidden()))) {
                continue;
            }
            $grades[$name] = (object) ['item' => $item, 'grade' => $grade];
        }
        $forumgradeid = isset($grades['wholeforum']) ? (int) $DB->get_field(
            'forum_grades',
            'id',
            ['forum' => $forum->id, 'itemnumber' => 1, 'userid' => $userid]
        ) : 0;

        if (!$posts && !$grades) {
            return null;
        }
        $fs = get_file_storage();
        foreach ($posts as $post) {
            $post->attachments = array_values($fs->get_area_files(
                $context->id,
                'mod_forum',
                'attachment',
                $post->id,
                'filename',
                false
            ));
            $post->ratings = $ratings[(int) $post->id] ?? [];
            $post->parentpost = $post->parent ? ($parents[$post->parent] ?? null) : null;
            $post->parentauthor = $post->parentpost ? ($authors[$post->parentpost->userid] ?? null) : null;
            $post->parentisstaff = $post->parentauthor && (int) $post->parentauthor->id !== $userid
                && has_any_capability(['mod/forum:grade', 'mod/forum:rate'], $context, $post->parentauthor);
        }
        return self::$cache[$key] = (object) ['cm' => $cm, 'course' => $course, 'forum' => $forum, 'context' => $context,
            'userid' => $userid, 'posts' => array_values($posts), 'grades' => $grades, 'forumgradeid' => $forumgradeid];
    }

    /**
     * The learner's contributions as HTML, for the summary page (posts folded) or the evidence pack (posts in full).
     *
     * @param stdClass $data From {@see for_user()}
     * @param identity|null $identity Redaction
     * @param bool $viewfullnames
     * @param bool $forpdf
     * @param bool $similarity Show similarity results for each post
     * @param bool $includefeedback Show the rubric or marking guide and gradebook feedback
     * @return string
     */
    public static function html(
        stdClass $data,
        ?identity $identity,
        bool $viewfullnames,
        bool $forpdf = false,
        bool $similarity = false,
        bool $includefeedback = true
    ): string {
        $datefmt = get_string('strftimedatetimeshort', 'core_langconfig');
        $html = '';

        // Whole-forum grade takes priority, then ratings.
        if ($wf = $data->grades['wholeforum'] ?? null) {
            $html .= '<p class="mb-1">' . s(get_string('forumwholegrade', 'report_evidencereporter')) . ': <strong>'
                . s(activity_grade::format_value((int) $wf->item->id, (float) $wf->grade->finalgrade)) . '</strong></p>';
            if ($includefeedback) {
                if ($data->forumgradeid) {
                    $html .= advanced_grading::table_html(
                        $data->context,
                        'mod_forum',
                        'forum',
                        $data->forumgradeid,
                        $identity
                    );
                }
                if (trim(strip_tags((string) $wf->grade->feedback)) !== '') {
                    $feedback = format_text(
                        $wf->grade->feedback,
                        $wf->grade->feedbackformat ?? FORMAT_HTML,
                        ['context' => $data->context]
                    );
                    $html .= '<div class="er-feedback">' . ($identity ? $identity->text($feedback) : $feedback) . '</div>';
                }
            }
        }
        if ($rt = $data->grades['ratings'] ?? null) {
            $html .= '<p class="mb-1">' . s(get_string(
                'forumratingsgrade',
                'report_evidencereporter',
                self::aggregation_name((int) $data->forum->assessed)
            )) . ': <strong>'
                . s(activity_grade::format_value((int) $rt->item->id, (float) $rt->grade->finalgrade)) . '</strong></p>';
        }

        $html .= '<p class="mb-1">' . s(get_string('forumpostcount', 'report_evidencereporter', count($data->posts))) . '</p>';
        foreach ($data->posts as $post) {
            $html .= self::post_html($data, $post, $identity, $viewfullnames, $forpdf, $similarity, $datefmt);
        }
        return $html;
    }

    /**
     * One post.
     *
     * @param stdClass $data
     * @param stdClass $post
     * @param identity|null $identity
     * @param bool $viewfullnames
     * @param bool $forpdf
     * @param bool $similarity
     * @param string $datefmt
     * @return string
     */
    private static function post_html(
        stdClass $data,
        stdClass $post,
        ?identity $identity,
        bool $viewfullnames,
        bool $forpdf,
        bool $similarity,
        string $datefmt
    ): string {
        $context = $data->context;
        $subject = format_string($post->subject, true, ['context' => $context]);
        $kind = $post->parent ? get_string(
            'forumreply',
            'report_evidencereporter',
            format_string($post->discussionname, true, ['context' => $context])
        )
            : get_string('forumdiscussionstarted', 'report_evidencereporter');
        $meta = [s($kind), s(dates::datetime((int) $post->created))];
        if ($post->ratings) {
            $meta[] = s(get_string('forumpostrating', 'report_evidencereporter', self::rating_text($data->forum, $post->ratings)));
        }
        $html = '<div class="er-post border-top pt-2 mt-2"><p class="mb-1"><strong>'
            . s($identity ? $identity->text($subject) : $subject) . '</strong><br><span class="small text-muted">'
            . implode(' &middot; ', $meta) . '</span></p>';

        // What it replied to.
        if ($post->parentpost) {
            $html .= '<div class="er-parent small text-muted border-start ps-2 mb-2">'
                . '<em>' . s(get_string('forumparent', 'report_evidencereporter', self::parent_label(
                    $post,
                    $data->userid,
                    $identity,
                    $viewfullnames
                ))) . '</em>: <strong>'
                . s(format_string($post->parentpost->subject, true, ['context' => $context])) . '</strong> &ldquo;'
                . s(self::excerpt($post->parentpost->message, $identity)) . '&rdquo;</div>';
        }

        // The post itself.
        $message = file_rewrite_pluginfile_urls($post->message, 'pluginfile.php', $context->id, 'mod_forum', 'post', $post->id);
        $message = format_text($message, $post->messageformat, ['context' => $context]);
        if ($identity) {
            $message = $identity->text($message);
        }
        if ($forpdf) {
            // Embedded images need a login to fetch, so the PDF says where they were.
            $message = preg_replace('/<img\b[^>]*>/i', '[' . s(get_string('image', 'report_evidencereporter')) . ']', $message);
            $html .= '<div class="er-message">' . $message . '</div>';
        } else {
            $html .= '<details class="er-message"><summary class="small">'
                . s(get_string('forumshowpost', 'report_evidencereporter')) . '</summary>' . $message . '</details>';
        }

        // Attachments.
        if ($post->attachments) {
            $names = [];
            foreach ($post->attachments as $file) {
                $name = s($identity ? $identity->text($file->get_filename(), true) : $file->get_filename());
                $names[] = $forpdf ? $name : \html_writer::link(
                    moodle_url::make_pluginfile_url(
                        $context->id,
                        'mod_forum',
                        'attachment',
                        $post->id,
                        $file->get_filepath(),
                        $file->get_filename()
                    ),
                    $name,
                    ['target' => '_blank', 'rel' => 'noopener']
                );
            }
            $html .= '<p class="small mb-1">' . s(get_string('forumattachments', 'report_evidencereporter')) . ': '
                . implode(', ', $names) . ($forpdf ? ' ' . s(get_string('forumattachmentsinpack', 'report_evidencereporter'))
                : '') . '</p>';
        }

        // Similarity results.
        if ($similarity) {
            $base = ['userid' => (int) $data->userid, 'cmid' => (int) $data->cm->id, 'course' => (int) $data->course->id,
                'forum' => (int) $data->forum->id];
            $parts = [];
            if (($links = similarity::get_links($base + ['content' => $post->message])) !== '') {
                $parts[] = s(get_string('forumposttext', 'report_evidencereporter')) . ': ' . $links;
            }
            foreach ($post->attachments as $file) {
                if (($links = similarity::get_links($base + ['file' => $file])) !== '') {
                    $parts[] = s($file->get_filename()) . ': ' . $links;
                }
            }
            if ($parts) {
                $text = implode(' | ', $parts);
                $html .= '<p class="small mb-1 er-similarity">' . s(get_string('similaritycheck', 'report_evidencereporter'))
                    . ': ' . ($forpdf ? s(trim(preg_replace('/\s+/', ' ', html_to_text($text, 0, false)))) : $text) . '</p>';
            }
        }

        if (!$forpdf) {
            $url = new moodle_url('/mod/forum/discuss.php', ['d' => $post->discussion], 'p' . $post->id);
            $html .= '<p class="small mb-0"><a href="' . $url->out() . '" target="_blank" rel="noopener">'
                . s(get_string('forumviewpost', 'report_evidencereporter')) . '<span class="visually-hidden"> '
                . s(get_string('opensinnewwindowbracketed')) . '</span></a></p>';
        }
        return $html . '</div>';
    }

    /**
     * Who wrote the post a reply answered: the learner, a named staff member, or "Another learner".
     *
     * @param stdClass $post
     * @param int $userid
     * @param identity|null $identity
     * @param bool $viewfullnames
     * @return string
     */
    private static function parent_label(stdClass $post, int $userid, ?identity $identity, bool $viewfullnames): string {
        if ($post->parentauthor && (int) $post->parentauthor->id === $userid) {
            return get_string('forumparentown', 'report_evidencereporter');
        }
        if ($post->parentisstaff) {
            $name = fullname($post->parentauthor, $viewfullnames);
            return $identity ? $identity->staff($name) : $name;
        }
        return get_string('forumparentlearner', 'report_evidencereporter');
    }

    /**
     * The first words of a post, as plain text.
     *
     * @param string $message
     * @param identity|null $identity
     * @return string
     */
    private static function excerpt(string $message, ?identity $identity): string {
        $text = trim(preg_replace('/\s+/', ' ', html_to_text($message, 0, false)));
        $words = explode(' ', $text);
        if (count($words) > self::EXCERPT_WORDS) {
            $text = implode(' ', array_slice($words, 0, self::EXCERPT_WORDS)) . '…';
        }
        return $identity ? $identity->text($text) : $text;
    }

    /**
     * Name of a forum's ratings aggregation, e.g. "Average of ratings".
     *
     * @param int $assessed
     * @return string
     */
    private static function aggregation_name(int $assessed): string {
        $names = [1 => 'aggregateavg', 2 => 'aggregatecount', 3 => 'aggregatemax', 4 => 'aggregatemin', 5 => 'aggregatesum'];
        return get_string($names[$assessed] ?? 'aggregateavg', 'core_rating');
    }

    /**
     * A post's ratings, aggregated as the forum does, e.g. "4.00 (3 ratings)" or "Good (2 ratings)" for a scale.
     *
     * @param stdClass $forum
     * @param float[] $values
     * @return string
     */
    private static function rating_text(stdClass $forum, array $values): string {
        $count = count($values);
        $value = match ((int) $forum->assessed) {
            2 => (float) $count,
            3 => max($values),
            4 => min($values),
            5 => array_sum($values),
            default => array_sum($values) / $count,
        };
        if ((int) $forum->scale < 0 && (int) $forum->assessed !== 2) {
            $scale = \grade_scale::fetch(['id' => -(int) $forum->scale]);
            $items = $scale ? $scale->load_items() : [];
            $shown = $items[(int) round($value) - 1] ?? format_float($value, 2);
        } else {
            $shown = format_float($value, (int) $forum->assessed === 2 ? 0 : 2);
        }
        return get_string('forumratingcount', 'report_evidencereporter', ['value' => $shown, 'count' => $count]);
    }

    /**
     * Reset the cache.
     */
    public static function reset_cache(): void {
        self::$cache = [];
    }
}
