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
 * Works out where a piece of competency evidence should link to.
 *
 * Priority:
 *  1. For evidence from an assignment, quiz, forum or database: a link to this learner's submission,
 *     attempt, posts or entry. (Core stores only the activity's view page URL on completion evidence.)
 *  2. The URL stored on the evidence record (evidence of prior learning, SMS links, other plugins).
 *  3. The activity's view page.
 *
 * Every target page applies its own permission checks, so a link is never a way around them.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evidence_link {
    /**
     * Resolve the URL for an evidence row.
     *
     * Expected row fields: url, modname, cmid, courseid, userid, quizattemptid, datarecordid.
     *
     * @param stdClass $row
     * @return moodle_url|null
     */
    public static function get_url(stdClass $row): ?moodle_url {
        if (!empty($row->cmid) && !empty($row->modname)) {
            if ($url = self::get_submission_url($row)) {
                return $url;
            }
        }

        if (!empty($row->url)) {
            try {
                return new moodle_url($row->url);
            } catch (\Throwable $e) {
                return null;
            }
        }

        if (!empty($row->cmid) && !empty($row->modname)) {
            return new moodle_url("/mod/{$row->modname}/view.php", ['id' => (int) $row->cmid]);
        }
        return null;
    }

    /**
     * Link to this learner's own work in the activity, for the module types we know how to link to.
     *
     * @param stdClass $row
     * @return moodle_url|null
     */
    private static function get_submission_url(stdClass $row): ?moodle_url {
        $cmid = (int) $row->cmid;
        $userid = (int) ($row->userid ?? 0);

        switch ($row->modname) {
            case 'assign':
                // The grader opens on this learner: submission, mark and feedback together.
                return new moodle_url('/mod/assign/view.php', ['id' => $cmid, 'action' => 'grader', 'userid' => $userid]);

            case 'quiz':
                if (!empty($row->quizattemptid)) {
                    return new moodle_url('/mod/quiz/review.php', ['attempt' => (int) $row->quizattemptid, 'showall' => 1]);
                }
                return new moodle_url('/mod/quiz/view.php', ['id' => $cmid]);

            case 'forum':
                return new moodle_url('/mod/forum/user.php', ['id' => $userid, 'course' => (int) $row->courseid]);

            case 'data':
                if (!empty($row->datarecordid)) {
                    // Single view of the learner's most recent entry.
                    return new moodle_url(
                        '/mod/data/view.php',
                        ['id' => $cmid, 'rid' => (int) $row->datarecordid, 'mode' => 'single']
                    );
                }
                return new moodle_url('/mod/data/view.php', ['id' => $cmid]);

            case 'checklist':
                // The learner's checklist report: each item's mark and the comments on it.
                return checklist_items::url($cmid, $userid);

            default:
                return null;
        }
    }

    /**
     * A link that opens in a new tab, so the report stays open behind it.
     *
     * @param \moodle_url|string $url
     * @param string $text Link text (already formatted)
     * @param array $attributes Extra attributes, e.g. class
     * @return string HTML
     */
    public static function new_tab(\moodle_url|string $url, string $text, array $attributes = []): string {
        return \html_writer::link(
            $url,
            $text . \html_writer::span(' ' . get_string('opensinnewwindowbracketed'), 'visually-hidden'),
            $attributes + ['target' => '_blank', 'rel' => 'noopener']
        );
    }

    /**
     * Make every link in a piece of HTML open in a new tab (links that already choose a target are left alone).
     *
     * @param string $html
     * @return string
     */
    public static function new_tab_html(string $html): string {
        if (stripos($html, '<a') === false) {
            return $html;
        }
        return preg_replace_callback('/<a\b([^>]*)>/i', static function (array $match): string {
            if (preg_match('/\btarget\s*=/i', $match[1])) {
                return $match[0];
            }
            return '<a' . $match[1] . ' target="_blank" rel="noopener">';
        }, $html);
    }
}
