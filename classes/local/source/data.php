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

namespace report_evidencereporter\local\source;

use moodle_url;
use report_evidencereporter\local\data_entries;
use report_evidencereporter\local\evidence_pack;
use report_evidencereporter\local\feedback_visibility;
use report_evidencereporter\local\identity;
use stdClass;

/**
 * Database activities: every rated entry counts as an assessment, its ratings make the grade and its comments are
 * the feedback; links go to the single view of the learner's latest entry.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class data extends base {
    #[\Override]
    public function get_modname(): string {
        return 'data';
    }

    #[\Override]
    public function get_description(): string {
        return get_string('sourcedesc_data', 'report_evidencereporter');
    }

    #[\Override]
    public function get_row_fields_sql(string $instance, string $userid): array {
        return ['datarecordid' => data_entries::latest_entry_sql($instance, $userid)];
    }

    #[\Override]
    public function get_url(stdClass $row): ?moodle_url {
        if (!empty($row->datarecordid)) {
            // Single view of the learner's most recent entry.
            return new moodle_url('/mod/data/view.php', ['id' => (int) $row->cmid, 'rid' => (int) $row->datarecordid,
                'mode' => 'single']);
        }
        return new moodle_url('/mod/data/view.php', ['id' => (int) $row->cmid]);
    }

    #[\Override]
    public function shows_current_state(): bool {
        return true;
    }

    #[\Override]
    public function feedback_follows_release(): bool {
        // Comments are visible in the activity as soon as they're made.
        return false;
    }

    #[\Override]
    public function has_own_feedback(stdClass $row): bool {
        return !empty($row->datarecordid);
    }

    #[\Override]
    public function get_feedback_html(stdClass $row, bool $viewfullnames, ?identity $identity = null): string {
        // Every comment on the entry, with who wrote it and when.
        return data_entries::comments_html(
            data_entries::get_comments((int) $row->datarecordid, (int) $row->cmid, $viewfullnames),
            $identity
        );
    }

    #[\Override]
    public function has_attempts(): bool {
        return true;
    }

    #[\Override]
    public function format_attempt(stdClass $row, stdClass $result, bool $canseeunreleased, bool $viewfullnames): stdClass {
        $value = null;
        $overallfeedback = '';
        $showmarks = true;
        $showfeedback = true;
        // Rated database entry: its ratings make the grade, its comments are the feedback.
        $value = $row->gradevalue === null ? null : (float) $row->gradevalue;
        $result->url = new moodle_url(
            '/mod/data/view.php',
            ['id' => $row->cmid, 'rid' => $row->datarecordid, 'mode' => 'single']
        );
        $released = feedback_visibility::is_released((object) ['gradeitemhidden' => $row->gradeitemhidden]);
        $result->released = $released;
        if (!$released && !$canseeunreleased) {
            $showmarks = false;
        }
        $result->ratings = data_entries::get_ratings((int) $row->datarecordid, $viewfullnames);
        $result->marker = implode(', ', array_unique(array_column($result->ratings, 'rater')));
        $result->comments = data_entries::get_comments((int) $row->datarecordid, (int) $row->cmid, $viewfullnames);
        $result->isentry = true;
        if ($result->comments) {
            $result->feedback = data_entries::comments_html($result->comments);
            $result->feedbackplain = data_entries::comments_plain($result->comments);
        }
            return (object) ['value' => $value, 'showmarks' => $showmarks, 'showfeedback' => $showfeedback,
            'overallfeedback' => $overallfeedback];
    }

    #[\Override]
    public function add_attempt_to_pack(
        evidence_pack $pack,
        string $folder,
        \cm_info $cm,
        stdClass $row,
        stdClass $attempt,
        array $compnames
    ): void {
        global $DB;
        $context = \context_module::instance($cm->id);
        $showmarks = $attempt->released || $attempt->canseeunreleased;
        $record = $pack->attempt_header_html($cm, $attempt, $compnames);

        // The entry's content, field by field.
        $contents = $DB->get_records_sql("SELECT c.id, c.content, f.name, f.type
                                            FROM {data_content} c
                                            JOIN {data_fields} f ON f.id = c.fieldid
                                           WHERE c.recordid = :recordid
                                        ORDER BY f.id", ['recordid' => $row->datarecordid]);
        $record .= '<h3>' . s(get_string('packentrycontent', 'report_evidencereporter')) . '</h3>'
            . '<table border="1" cellpadding="4" width="100%">';
        foreach ($contents as $content) {
            $record .= $pack->table_row(format_string($content->name), s($pack->get_identity()->text(trim(html_to_text(
                (string) $content->content,
                0,
                false
            )))));
        }
        $record .= '</table>';

        if ($showmarks && $attempt->ratings) {
            $record .= '<h3>' . s(get_string('packratings', 'report_evidencereporter')) . '</h3>'
                . '<table border="1" cellpadding="4" width="100%">';
            foreach ($attempt->ratings as $rating) {
                $record .= $pack->table_row($rating->rater, s(data_entries::rating_text($rating->scaleid, $rating->rating)
                    . ' · ' . userdate($rating->time)));
            }
            $record .= '</table>';
        }
        if ($pack->includes_feedback()) {
            $record .= '<h3>' . s(get_string('comments')) . '</h3>'
                . ($attempt->comments ? data_entries::comments_html($attempt->comments, $pack->get_identity()) : '<p>-</p>');
        } else {
            $record .= '<p><em>' . s(get_string('feedbackexcluded', 'report_evidencereporter')) . '</em></p>';
        }

        $fs = get_file_storage();
        foreach (array_keys($contents) as $contentid) {
            foreach ($fs->get_area_files($context->id, 'mod_data', 'content', $contentid, 'filepath, filename', false) as $file) {
                $pack->add_file($folder . '/' . get_string('packsubmittedfiles', 'report_evidencereporter'), $file);
            }
        }
        $pack->add_content(
            $folder . '/' . get_string('packattemptrecord', 'report_evidencereporter') . '.pdf',
            $pack->html_to_pdf($cm->get_formatted_name(), $record)
        );
    }
}
