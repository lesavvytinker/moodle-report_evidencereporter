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

namespace report_evidencereporter\admin;

use html_writer;
use report_evidencereporter\local\terms;

/**
 * Admin setting: a terminology profile for each competency framework (for providers offering, say, VET and degrees).
 *
 * Stored as JSON: framework ID => profile. Frameworks not listed use the site's profile.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_frameworkprofiles extends \admin_setting {
    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(
            'report_evidencereporter/frameworkprofiles',
            get_string('settings_frameworkprofiles', 'report_evidencereporter'),
            get_string('settings_frameworkprofiles_desc', 'report_evidencereporter'),
            ''
        );
    }

    #[\Override]
    public function get_setting() {
        return $this->config_read($this->name);
    }

    #[\Override]
    public function write_setting($data) {
        $profiles = terms::get_profiles();
        $map = [];
        $data = is_array($data) ? $data : [];
        foreach ($data as $frameworkid => $profile) {
            if (is_int($frameworkid) || ctype_digit((string) $frameworkid)) {
                if ((int) $frameworkid > 0 && isset($profiles[$profile])) {
                    $map[(int) $frameworkid] = $profile;
                }
            }
        }
        // The "Add a framework" row.
        $add = (int) ($data['add'] ?? 0);
        if ($add > 0 && isset($profiles[$data['addprofile'] ?? ''])) {
            $map[$add] = $data['addprofile'];
        }
        ksort($map);
        return $this->config_write($this->name, $map ? json_encode($map) : '') ? '' : get_string('errorsetting', 'admin');
    }

    #[\Override]
    public function output_html($data, $query = '') {
        global $DB;
        $map = is_array($data) ? $data : (json_decode((string) $data, true) ?: []);
        $frameworks = $DB->get_records('competency_framework', null, 'shortname, id', 'id, shortname, idnumber');
        if (!$frameworks) {
            $element = html_writer::div(get_string('settings_frameworkprofiles_none', 'report_evidencereporter'), 'text-muted');
            return format_admin_setting($this, $this->visiblename, $element, $this->description, false, '', null, $query);
        }
        $profiles = terms::get_profiles();
        $label = fn($framework) => format_string($framework->shortname)
            . ($framework->idnumber ? ' (' . s($framework->idnumber) . ')' : '');
        // Only the frameworks with their own profile are listed; the rest use the site's.
        $rows = '';
        foreach ($frameworks as $framework) {
            if (!isset($map[$framework->id]) || !isset($profiles[$map[$framework->id]])) {
                continue;
            }
            $id = $this->get_id() . '_' . $framework->id;
            $rows .= html_writer::tag(
                'tr',
                html_writer::tag('td', html_writer::label($label($framework), $id)) .
                html_writer::tag('td', html_writer::select(
                    ['' => get_string('settings_frameworkprofiles_remove', 'report_evidencereporter')] + $profiles,
                    $this->get_full_name() . '[' . $framework->id . ']',
                    $map[$framework->id],
                    false,
                    ['id' => $id, 'class' => 'form-select']
                ))
            );
        }
        $others = array_filter($frameworks, fn($f) => !isset($map[$f->id]) || !isset($profiles[$map[$f->id]]));
        $site = $profiles[terms::get_site_profile()];
        if ($others) {
            $choices = [0 => get_string('settings_frameworkprofiles_choose', 'report_evidencereporter')];
            foreach ($others as $framework) {
                $choices[$framework->id] = $label($framework);
            }
            $addid = $this->get_id() . '_add';
            $rows .= html_writer::tag(
                'tr',
                html_writer::tag('td', html_writer::label(
                    get_string('settings_frameworkprofiles_add', 'report_evidencereporter'),
                    $addid,
                    true,
                    ['class' => 'visually-hidden']
                ) . html_writer::select(
                    $choices,
                    $this->get_full_name() . '[add]',
                    0,
                    false,
                    ['id' => $addid, 'class' => 'form-select']
                )) .
                html_writer::tag('td', html_writer::select(
                    $profiles,
                    $this->get_full_name() . '[addprofile]',
                    terms::get_site_profile(),
                    false,
                    ['id' => $addid . 'profile', 'class' => 'form-select',
                        'aria-label' => get_string('settings_frameworkprofiles_add', 'report_evidencereporter')]
                )),
                ['class' => 'er-addframework']
            );
        }
        $note = $others ? get_string(
            'settings_frameworkprofiles_others',
            'report_evidencereporter',
            (object) ['count' => count($others), 'profile' => $site]
        )
            : get_string('settings_frameworkprofiles_noothers', 'report_evidencereporter');
        // An empty value keeps the field in the submitted form when every framework uses the default.
        $element = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $this->get_full_name() . '[0]', 'value' => ''])
            . html_writer::tag('table', html_writer::tag('tbody', $rows), ['class' => 'table table-sm w-auto mb-1'])
            . html_writer::div(s($note), 'small text-muted');
        return format_admin_setting($this, $this->visiblename, $element, $this->description, false, '', null, $query);
    }
}
