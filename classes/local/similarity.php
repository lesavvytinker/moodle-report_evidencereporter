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
 * Similarity check results (Turnitin, Ouriginal, Copyleaks...) beside the evidence, through Moodle's plagiarism API.
 *
 * Evidence Reporter doesn't read any vendor's tables: it asks Moodle for the same badge the plugin shows in the
 * activity, so any similarity plugin that follows Moodle's API works. Results are for staff (people who can see
 * unreleased grades in the activity), since a raw score isn't a finding. Packs include a plain-text version only
 * when the site turns that on.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class similarity {
    /** Don't show similarity results. */
    public const SHOW_OFF = 'off';
    /** Show them to staff on the summary page and in the report. */
    public const SHOW_SUMMARY = 'summary';
    /** As SHOW_SUMMARY, and in evidence packs that staff download. */
    public const SHOW_PACKS = 'packs';

    /** @var callable|null Replaces plagiarism_get_links() in unit tests. */
    private static $linksource = null;

    /** @var array Results cached by cmid and userid. */
    private static array $cache = [];

    /**
     * Setting: where similarity results show.
     *
     * @return string One of the SHOW_ constants
     */
    public static function get_mode(): string {
        $mode = (string) get_config('report_evidencereporter', 'similarity');
        return in_array($mode, [self::SHOW_OFF, self::SHOW_SUMMARY, self::SHOW_PACKS]) ? $mode : self::SHOW_SUMMARY;
    }

    /**
     * Is a similarity plugin enabled on this site?
     *
     * @return bool
     */
    public static function is_available(): bool {
        global $CFG;
        if (self::$linksource) {
            return true;
        }
        if (empty($CFG->enableplagiarism)) {
            return false;
        }
        require_once($CFG->libdir . '/plagiarismlib.php');
        return !empty(plagiarism_load_available_plugins());
    }

    /**
     * The badges and links every enabled similarity plugin gives for a file or text.
     *
     * @param array $linkarray As for plagiarism_get_links(): userid, cmid, course, and file or content
     * @return string HTML
     */
    public static function get_links(array $linkarray): string {
        global $CFG;
        if (self::$linksource) {
            return (string) call_user_func(self::$linksource, $linkarray);
        }
        require_once($CFG->libdir . '/plagiarismlib.php');
        return trim((string) plagiarism_get_links($linkarray));
    }

    /**
     * Similarity results for an evidence row, as HTML, if the viewer may see them.
     *
     * @param stdClass $row Evidence row with cmid, modname, userid and islatest
     * @return string HTML, or empty
     */
    public static function row_html(stdClass $row): string {
        if (
            self::get_mode() === self::SHOW_OFF || empty($row->islatest) || empty($row->cmid) || empty($row->userid)
                || !self::is_available()
        ) {
            return '';
        }
        $source = sources::get((string) ($row->modname ?? ''));
        if (!$source) {
            return '';
        }
        $key = (int) $row->cmid . '-' . (int) $row->userid;
        if (!array_key_exists($key, self::$cache)) {
            $context = \context_module::instance((int) $row->cmid, IGNORE_MISSING);
            self::$cache[$key] = ($context && $source->can_view_unreleased($context))
                ? $source->get_similarity_html($row) : '';
        }
        return self::$cache[$key];
    }

    /**
     * Similarity results for the evidence pack, as plain text, or empty when packs leave them out.
     *
     * @param stdClass $row
     * @return string
     */
    public static function pack_text(stdClass $row): string {
        if (self::get_mode() !== self::SHOW_PACKS) {
            return '';
        }
        $html = self::row_html($row);
        if ($html === '') {
            return '';
        }
        return trim(preg_replace('/\s+/', ' ', html_to_text(str_replace('<br>', ' | ', $html), 0, false)));
    }

    /**
     * Use a function in place of plagiarism_get_links() (unit tests only).
     *
     * @param callable|null $source
     */
    public static function set_link_source(?callable $source): void {
        self::$linksource = $source;
        self::$cache = [];
    }

    /**
     * Reset the cache.
     */
    public static function reset_cache(): void {
        self::$cache = [];
    }
}
