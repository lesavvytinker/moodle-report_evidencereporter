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
 * Terminology profiles: the words official documents use, to suit the sector.
 *
 * Each term has a default string ("term_<key>") and optional per-profile strings ("term_<key>_<profile>"), so a
 * profile only lists the words it changes. Sites can change any of them through Moodle's language customisation.
 *
 * The site has a profile, and each competency framework can have its own (for providers offering, say, VET and
 * degrees). A document uses the profile of the frameworks it covers: see {@see use_profile()}.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class terms {
    /** @var string Australian vocational education and training. */
    public const AU_VET = 'auvet';

    /** @var string Higher education. */
    public const HIGHER_ED = 'highered';

    /** @var string UK apprenticeships. */
    public const UK_APPRENTICESHIP = 'ukappr';

    /** @var string South African occupational qualifications. */
    public const ZA_OCCUPATIONAL = 'zaocc';

    /** @var string Generic wording. */
    public const GENERIC = 'generic';

    /** @var string|null Profile in use for the document being built, if not the site's. */
    private static ?string $inuse = null;

    /** @var string[] The terms a profile can change. */
    public const KEYS = ['learner', 'competency', 'competencies', 'framework', 'assessor', 'proficient', 'summarytitle',
        'evidencepack', 'overviewcounts'];

    /**
     * The profiles, in menu order.
     *
     * @return array profile => name
     */
    public static function get_profiles(): array {
        $profiles = [];
        foreach ([self::AU_VET, self::HIGHER_ED, self::UK_APPRENTICESHIP, self::ZA_OCCUPATIONAL, self::GENERIC] as $profile) {
            $profiles[$profile] = get_string('profile_' . $profile, 'report_evidencereporter');
        }
        return $profiles;
    }

    /**
     * The site's profile.
     *
     * @return string
     */
    public static function get_site_profile(): string {
        $profile = (string) get_config('report_evidencereporter', 'profile');
        return array_key_exists($profile, self::get_profiles()) ? $profile : self::AU_VET;
    }

    /**
     * The profile in use: the one set for the document being built, otherwise the site's.
     *
     * @return string
     */
    public static function get_profile(): string {
        return self::$inuse ?? self::get_site_profile();
    }

    /**
     * Use a profile for what follows (the document being built), or null to go back to the site's.
     *
     * @param string|null $profile
     * @return string|null The profile in use before
     */
    public static function use_profile(?string $profile): ?string {
        $previous = self::$inuse;
        self::$inuse = $profile !== null && array_key_exists($profile, self::get_profiles()) ? $profile : null;
        return $previous;
    }

    /**
     * Profiles set for individual frameworks.
     *
     * @return array framework ID => profile
     */
    public static function get_framework_profiles(): array {
        $map = json_decode((string) get_config('report_evidencereporter', 'frameworkprofiles'), true);
        $profiles = self::get_profiles();
        return array_filter(is_array($map) ? $map : [], fn($profile) => isset($profiles[$profile]));
    }

    /**
     * The profile for a document covering these frameworks: theirs if they agree, otherwise the site's.
     *
     * @param int[] $frameworkids
     * @return string
     */
    public static function for_frameworks(array $frameworkids): string {
        $map = self::get_framework_profiles();
        $site = self::get_site_profile();
        $found = array_unique(array_map(fn($id) => $map[(int) $id] ?? $site, $frameworkids));
        return count($found) === 1 ? reset($found) : $site;
    }

    /**
     * One term in the site's profile.
     *
     * @param string $key One of {@see KEYS}
     * @param mixed $a Placeholder values, as for get_string()
     * @return string
     */
    public static function get(string $key, $a = null): string {
        $profiled = 'term_' . $key . '_' . self::get_profile();
        if (get_string_manager()->string_exists($profiled, 'report_evidencereporter')) {
            return get_string($profiled, 'report_evidencereporter', $a);
        }
        return get_string('term_' . $key, 'report_evidencereporter', $a);
    }

    /**
     * Every term, for templates.
     *
     * @return array key => text (placeholders left for the caller)
     */
    public static function all(): array {
        $terms = [];
        foreach (self::KEYS as $key) {
            if ($key !== 'overviewcounts') {
                $terms[$key] = self::get($key);
            }
        }
        return $terms;
    }
}
