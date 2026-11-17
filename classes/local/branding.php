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
 * Document branding and layout settings: organisation name and logo, header and footer text, the sign-off block,
 * which sections appear, and how files are named.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class branding {
    /** @var string Default file name pattern for evidence packs. */
    public const DEFAULT_FILENAME = '{document} - {learner} - {covering} - {date}';

    /**
     * A setting's value.
     *
     * @param string $name
     * @return string
     */
    private static function config(string $name): string {
        return trim((string) get_config('report_evidencereporter', $name));
    }

    /**
     * Organisation name: as set, or the site name.
     *
     * @return string Plain text
     */
    public static function get_orgname(): string {
        $name = self::config('orgname');
        return $name !== '' ? $name : format_string(get_site()->fullname, true, ['context' => \context_system::instance()]);
    }

    /**
     * Text under the title of every document (e.g. a registration number, "Confidential").
     *
     * @return string Plain text, may be empty
     */
    public static function get_header(): string {
        return self::config('documentheader');
    }

    /**
     * Text at the end of every document.
     *
     * @return string Plain text, may be empty
     */
    public static function get_footer(): string {
        return self::config('documentfooter');
    }

    /**
     * Show a section of the summary? Sections: evidencedetail, attempts, signoff.
     *
     * @param string $section
     * @return bool
     */
    public static function show(string $section): bool {
        $value = get_config('report_evidencereporter', 'show' . $section);
        return $value === false || $value === '' || (bool) $value;
    }

    /**
     * Sign-off heading: as set, or the default.
     *
     * @return string
     */
    public static function get_signoff_heading(): string {
        $heading = self::config('signoffheading');
        return $heading !== '' ? $heading : get_string('reviewheading', 'report_evidencereporter');
    }

    /**
     * Declaration printed above the sign-off lines, if any.
     *
     * @return string Plain text, may be empty
     */
    public static function get_signoff_statement(): string {
        return self::config('signoffstatement');
    }

    /**
     * The logo file, if one is set.
     *
     * @return \stored_file|null
     */
    public static function get_logo(): ?\stored_file {
        $path = self::config('logo');
        if ($path === '') {
            return null;
        }
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            \context_system::instance()->id,
            'report_evidencereporter',
            'logo',
            0,
            'itemid, filepath, filename',
            false
        );
        return $files ? reset($files) : null;
    }

    /**
     * URL of the logo for web pages.
     *
     * @return \moodle_url|null
     */
    public static function get_logo_url(): ?\moodle_url {
        $file = self::get_logo();
        if (!$file) {
            return null;
        }
        return \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            'report_evidencereporter',
            'logo',
            $file->get_timemodified(),
            $file->get_filepath(),
            $file->get_filename()
        );
    }

    /**
     * Build a file name from the pattern setting.
     *
     * Placeholders: {document} (e.g. "Evidence pack"), {learner}, {covering}, {date}, {orgname}.
     *
     * @param array $values placeholder => value
     * @return string Without extension, made safe for file names
     */
    public static function file_name(array $values): string {
        $pattern = self::config('filenamepattern');
        if ($pattern === '' || !str_contains($pattern, '{learner}')) {
            // The learner must stay in the name, or bulk downloads would collide.
            $pattern = self::DEFAULT_FILENAME;
        }
        $values += ['orgname' => self::get_orgname()];
        $name = preg_replace_callback('/\{(\w+)\}/', fn($m) => $values[$m[1]] ?? '', $pattern);
        // Tidy separators left by empty placeholders.
        $name = preg_replace('/(\s*-\s*){2,}/', ' - ', $name);
        $name = trim($name, " -");
        return clean_filename($name);
    }
}
