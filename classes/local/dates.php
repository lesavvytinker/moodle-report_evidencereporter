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
 * Dates and times as Evidence Reporter shows them: the site's chosen date order and 12- or 24-hour clock (settings),
 * or Moodle's language formats when left at "As the site's language".
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dates {
    /** @var string Moodle's language format. */
    public const SITE = 'site';

    /** @var array Date settings => strftime format. */
    public const DATEFORMATS = [
        'dmy' => '%d/%m/%Y',
        'mdy' => '%m/%d/%Y',
        'ymd' => '%Y-%m-%d',
        'dmonthy' => '%d %B %Y',
        'dmony' => '%d %b %Y',
        'monthdy' => '%B %d, %Y',
    ];

    /** @var array Time settings => strftime format. */
    public const TIMEFORMATS = [
        '24' => '%H:%M',
        '12' => '%I:%M %p',
    ];

    /**
     * The date setting.
     *
     * @return string
     */
    public static function get_date_setting(): string {
        $value = (string) get_config('report_evidencereporter', 'dateformat');
        return isset(self::DATEFORMATS[$value]) ? $value : self::SITE;
    }

    /**
     * The time setting.
     *
     * @return string
     */
    public static function get_time_setting(): string {
        $value = (string) get_config('report_evidencereporter', 'timeformat');
        return isset(self::TIMEFORMATS[$value]) ? $value : self::SITE;
    }

    /**
     * A date.
     *
     * @param int $time
     * @param string $langformat The language string used when the setting is the site's (core_langconfig)
     * @return string
     */
    public static function date(int $time, string $langformat = 'strftimedate'): string {
        $setting = self::get_date_setting();
        if ($setting === self::SITE) {
            return userdate($time, get_string($langformat, 'core_langconfig'));
        }
        return self::format($time, self::DATEFORMATS[$setting]);
    }

    /**
     * A time of day.
     *
     * @param int $time
     * @return string
     */
    public static function time(int $time): string {
        $setting = self::get_time_setting();
        if ($setting === self::SITE) {
            return userdate($time, get_string('strftimetime', 'core_langconfig'));
        }
        return self::format($time, self::TIMEFORMATS[$setting]);
    }

    /**
     * A date and time.
     *
     * @param int $time
     * @param string $langformat The language string used when both settings are the site's (core_langconfig)
     * @return string
     */
    public static function datetime(int $time, string $langformat = 'strftimedatetimeshort'): string {
        if (self::get_date_setting() === self::SITE && self::get_time_setting() === self::SITE) {
            return userdate($time, get_string($langformat, 'core_langconfig'));
        }
        $short = in_array($langformat, ['strftimedatetimeshort', 'strftimedatetimeshortaccurate']);
        return self::date($time, $short ? 'strftimedatefullshort' : 'strftimedate') . ', ' . self::time($time);
    }

    /**
     * Report builder column callback: a date and time, or nothing.
     *
     * @param mixed $value
     * @return string
     */
    public static function rb_datetime($value): string {
        return $value ? self::datetime((int) $value) : '';
    }

    /**
     * Examples of each choice, for the settings menus.
     *
     * @return array[] [date choices, time choices]
     */
    public static function get_choices(): array {
        $example = make_timestamp(2026, 11, 27, 14, 5);
        $dates = [self::SITE => get_string(
            'settings_dateformat_site',
            'report_evidencereporter',
            userdate($example, get_string('strftimedate', 'core_langconfig'))
        )];
        foreach (self::DATEFORMATS as $key => $format) {
            $dates[$key] = self::format($example, $format);
        }
        $times = [self::SITE => get_string(
            'settings_dateformat_site',
            'report_evidencereporter',
            userdate($example, get_string('strftimetime', 'core_langconfig'))
        )];
        foreach (self::TIMEFORMATS as $key => $format) {
            $times[$key] = get_string('settings_timeformat_' . $key, 'report_evidencereporter', self::format($example, $format));
        }
        return [$dates, $times];
    }

    /**
     * Format with Moodle's userdate (time zone and language month names), dropping leading zeros from the day of a
     * written-out date and from 12-hour times.
     *
     * @param int $time
     * @param string $format
     * @return string
     */
    private static function format(int $time, string $format): string {
        $fixday = str_contains($format, '%B') || str_contains($format, '%b');
        $fixhour = str_contains($format, '%I');
        return trim(userdate($time, $format, 99, $fixday, $fixhour));
    }
}
