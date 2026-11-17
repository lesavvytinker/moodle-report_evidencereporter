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
        foreach (is_array($data) ? $data : [] as $frameworkid => $profile) {
            if ((int) $frameworkid > 0 && isset($profiles[$profile])) {
                $map[(int) $frameworkid] = $profile;
            }
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
        $default = get_string(
            'settings_frameworkprofiles_default',
            'report_evidencereporter',
            terms::get_profiles()[terms::get_site_profile()]
        );
        $options = ['' => $default] + terms::get_profiles();
        $rows = '';
        foreach ($frameworks as $framework) {
            $id = $this->get_id() . '_' . $framework->id;
            $label = format_string($framework->shortname) . ($framework->idnumber ? ' (' . s($framework->idnumber) . ')' : '');
            $rows .= html_writer::tag(
                'tr',
                html_writer::tag('td', html_writer::label($label, $id)) .
                html_writer::tag('td', html_writer::select(
                    $options,
                    $this->get_full_name() . '[' . $framework->id . ']',
                    $map[$framework->id] ?? '',
                    false,
                    ['id' => $id, 'class' => 'form-select']
                ))
            );
        }
        // An empty value keeps the field in the submitted form when every framework uses the default.
        $element = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $this->get_full_name() . '[0]', 'value' => ''])
            . html_writer::tag('table', html_writer::tag('tbody', $rows), ['class' => 'table table-sm w-auto']);
        return format_admin_setting($this, $this->visiblename, $element, $this->description, false, '', null, $query);
    }
}
