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
use report_evidencereporter\local\evidence_pack;
use report_evidencereporter\local\identity;
use stdClass;

/**
 * An evidence source: what Evidence Reporter knows about one kind of activity.
 *
 * Each source answers the same questions for its activity type: where the learner's work is, what the feedback is,
 * and what goes in the evidence pack. Activities without a source still appear, with the activity's view page as
 * the link and the gradebook feedback as the feedback.
 *
 * Other plugins can add sources through the {@see \report_evidencereporter\hook\collect_sources} hook.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base {
    /**
     * The activity module this source handles, e.g. "assign".
     *
     * @return string
     */
    abstract public function get_modname(): string;

    /**
     * Is the activity module installed on this site?
     *
     * @return bool
     */
    public function is_installed(): bool {
        return \core_component::get_component_directory('mod_' . $this->get_modname()) !== null;
    }

    /**
     * Name to show admins, e.g. "Assignment".
     *
     * @return string
     */
    public function get_name(): string {
        $component = 'mod_' . $this->get_modname();
        return get_string_manager()->string_exists('modulename', $component)
            ? get_string('modulename', $component) : $this->get_modname();
    }

    /**
     * What this source adds, for the admin overview.
     *
     * @return string
     */
    public function get_description(): string {
        return '';
    }

    /**
     * Link to the learner's own work for an evidence row, or null to use the stored evidence URL or the activity page.
     *
     * The row has modname, cmid, courseid, userid, plus any fields from {@see get_row_fields_sql()}.
     *
     * @param stdClass $row
     * @return moodle_url|null
     */
    public function get_url(stdClass $row): ?moodle_url {
        return null;
    }

    /**
     * Extra SQL fields an evidence row needs for {@see get_url()} or {@see get_feedback_html()}, e.g. the learner's
     * latest attempt. Each is evaluated only for this activity type.
     *
     * @param string $instance SQL for the activity instance ID
     * @param string $userid SQL for the learner's ID
     * @return array field name => SQL expression
     */
    public function get_row_fields_sql(string $instance, string $userid): array {
        return [];
    }

    /**
     * Can the user see this activity's feedback before it's released (beyond moodle/grade:viewhidden)?
     *
     * @param \context_module $context
     * @return bool
     */
    public function can_view_unreleased(\context_module $context): bool {
        return false;
    }

    /**
     * Does the feedback show the activity as it is now (rather than at the time of the evidence)? If so, only the
     * latest evidence row from the activity shows it; earlier rows say they're superseded.
     *
     * @return bool
     */
    public function shows_current_state(): bool {
        return false;
    }

    /**
     * Does this source's own feedback follow the gradebook release rules (hidden until released)?
     *
     * @return bool
     */
    public function feedback_follows_release(): bool {
        return true;
    }

    /**
     * Similarity check results for the learner's current submission, from Moodle's similarity (plagiarism) plugins.
     *
     * Use similarity::get_links() so any installed plugin (Turnitin, Ouriginal, Copyleaks...) supplies its own badge.
     *
     * @param stdClass $row Evidence row with cmid and userid
     * @return string HTML, or empty when there's nothing to show
     */
    public function get_similarity_html(stdClass $row): string {
        return '';
    }

    /**
     * SQL condition: has the learner done something in this activity worth showing? Only for activity types not built
     * into the main query (e.g. plugins that may not be installed); built-in ones return null.
     *
     * @param string $instance SQL for the activity instance ID
     * @param int $userid
     * @return string|null
     */
    public function get_activity_done_sql(string $instance, int $userid): ?string {
        return null;
    }

    /**
     * Heading for this source's records on the summary, e.g. "Forum contributions".
     *
     * @return string
     */
    public function get_records_heading(): string {
        return $this->get_name();
    }

    /**
     * Records for activities that keep them instead of attempts (forum posts, attendance sessions, a workshop
     * submission and its reviews), shown under each competency the activity is linked to.
     *
     * @param int $userid
     * @param stdClass[] $links Linked activities of this type (competencyid, cmid, course)
     * @param bool $viewfullnames
     * @param identity|null $identity Redaction
     * @return stdClass[] (competencyid, activity, cmid, count: short summary, html)
     */
    public function get_records(int $userid, array $links, bool $viewfullnames, ?identity $identity): array {
        return [];
    }

    /**
     * Does this source supply its own feedback for this evidence row (instead of the gradebook feedback)?
     *
     * @param stdClass $row
     * @return bool
     */
    public function has_own_feedback(stdClass $row): bool {
        return false;
    }

    /**
     * The source's own feedback for an evidence row (when {@see has_own_feedback()} is true).
     *
     * Called only for rows whose feedback may be shown (release rules are checked first when
     * {@see feedback_follows_release()} is true).
     *
     * @param stdClass $row
     * @param bool $viewfullnames
     * @param identity|null $identity Redaction to apply to names and text
     * @return string HTML
     */
    public function get_feedback_html(stdClass $row, bool $viewfullnames, ?identity $identity = null): string {
        return '';
    }

    /**
     * Does this source record attempts (assignment attempts, quiz attempts, rated database entries)?
     *
     * Attempts come from the plugin's attempt query, which covers the built-in sources.
     *
     * @return bool
     */
    public function has_attempts(): bool {
        return false;
    }

    /**
     * Fill in the source-specific parts of a formatted attempt: grade value, link, marker, feedback and release.
     *
     * @param stdClass $row Raw attempt row
     * @param stdClass $result The attempt being formatted (url, marker, feedback, released, ratings, comments...)
     * @param bool $canseeunreleased
     * @param bool $viewfullnames
     * @return stdClass value (float|null), showmarks, showfeedback, overallfeedback (HTML)
     */
    public function format_attempt(stdClass $row, stdClass $result, bool $canseeunreleased, bool $viewfullnames): stdClass {
        return (object) ['value' => null, 'showmarks' => true, 'showfeedback' => true, 'overallfeedback' => ''];
    }

    /**
     * Add one attempt to an evidence pack: its record PDF and any files.
     *
     * @param evidence_pack $pack
     * @param string $folder Folder for this attempt
     * @param \cm_info $cm
     * @param stdClass $row Raw attempt row
     * @param stdClass $attempt Formatted attempt, with redaction applied
     * @param string[] $compnames Competencies the activity is linked to
     */
    public function add_attempt_to_pack(
        evidence_pack $pack,
        string $folder,
        \cm_info $cm,
        stdClass $row,
        stdClass $attempt,
        array $compnames
    ): void {
    }

    /**
     * Add records that aren't attempts to an evidence pack (e.g. a checklist's marks).
     *
     * @param evidence_pack $pack
     * @param int[] $competencyids Competencies the pack covers
     */
    public function add_records_to_pack(evidence_pack $pack, array $competencyids): void {
    }
}
