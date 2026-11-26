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

use MoodleQuickForm;

/**
 * Staff chosen from a list rather than typed: who reviewed an assessment tool, who approved a reasonable adjustment.
 *
 * A typed name can be anyone's (or a typo); choosing from staff means the record names an actual person. A typed name
 * is still allowed for someone without an account on the site, such as an industry representative.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class staff {
    /** @var string[] Capabilities that make someone staff here (any of them). */
    public const CAPABILITIES = [
        'moodle/grade:edit',
        'moodle/course:manageactivities',
        'report/evidencereporter:manageadjustments',
    ];

    /**
     * Staff in a context, for a list: user ID => full name, sorted by name. Includes the current user if they're staff
     * here (site administrators aren't otherwise listed), and the person already chosen even if they no longer are.
     *
     * @param \context $context
     * @param int $current The user already chosen, 0 for none
     * @return string[]
     */
    public static function options(\context $context, int $current = 0): array {
        global $USER;
        $fields = 'u.id, u.deleted, ' . implode(', ', array_map(fn($f) => 'u.' . $f, \core_user\fields::get_name_fields()));
        $users = get_users_by_capability($context, self::CAPABILITIES, $fields);
        if (isloggedin() && !isguestuser() && !isset($users[$USER->id]) && has_any_capability(self::CAPABILITIES, $context)) {
            $users[$USER->id] = $USER;
        }
        if ($current && !isset($users[$current]) && ($user = \core_user::get_user($current))) {
            $users[$current] = $user;
        }
        $viewfullnames = has_capability('moodle/site:viewfullnames', $context);
        $options = [];
        foreach ($users as $user) {
            if (empty($user->deleted)) {
                $options[(int) $user->id] = fullname($user, $viewfullnames);
            }
        }
        \core_collator::asort($options);
        return $options;
    }

    /**
     * The name to show: the chosen person's, else the typed name.
     *
     * @param int $userid
     * @param string $typed
     * @return string
     */
    public static function name(int $userid, string $typed): string {
        if ($userid && ($user = \core_user::get_user($userid)) && empty($user->deleted)) {
            return fullname($user);
        }
        return $typed;
    }

    /**
     * Add the pair of fields to a form: a staff list, and a name for someone without an account.
     *
     * @param MoodleQuickForm $mform
     * @param string $name Field name; the typed name is "{$name}" and the chosen user "{$name}id"
     * @param string $label String ID for the list's label (with a _help string)
     * @param string $otherlabel String ID for the typed name's label (with a _help string)
     * @param \context $context Where staff are looked for
     * @param int $current The user already chosen
     */
    public static function add_form_elements(
        MoodleQuickForm $mform,
        string $name,
        string $label,
        string $otherlabel,
        \context $context,
        int $current = 0
    ): void {
        $mform->addElement(
            'autocomplete',
            $name . 'id',
            get_string($label, 'report_evidencereporter'),
            [0 => ''] + self::options($context, $current),
            ['noselectionstring' => get_string('noselection', 'form')]
        );
        $mform->setType($name . 'id', PARAM_INT);
        $mform->addHelpButton($name . 'id', $label, 'report_evidencereporter');
        $mform->addElement('text', $name, get_string($otherlabel, 'report_evidencereporter'), ['size' => 40]);
        $mform->setType($name, PARAM_TEXT);
        $mform->addRule($name, get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton($name, $otherlabel, 'report_evidencereporter');
    }

    /**
     * Form validation for the pair: not both.
     *
     * @param array $data Submitted data
     * @param string $name As given to {@see add_form_elements()}
     * @return string[] errors
     */
    public static function validate(array $data, string $name): array {
        if (!empty($data[$name . 'id']) && trim((string) ($data[$name] ?? '')) !== '') {
            return [$name => get_string('staffchooseone', 'report_evidencereporter')];
        }
        return [];
    }

    /**
     * Is this user someone who may be chosen here (staff, or already chosen)?
     *
     * @param int $userid
     * @param \context $context
     * @param int $current
     * @return bool
     */
    public static function is_valid_choice(int $userid, \context $context, int $current = 0): bool {
        return $userid === 0 || $userid === $current || array_key_exists($userid, self::options($context, $current));
    }
}
