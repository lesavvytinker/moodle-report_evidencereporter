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

/**
 * Backs up Evidence Reporter's settings for an activity, with the activity: its assessment tool details, and (for a
 * Feedback activity) the assessment it asks about. No learner data: RA and LLN, adjustments and attempt history stay
 * with the course they were recorded in.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_report_evidencereporter_plugin extends backup_plugin {
    /**
     * The activity's settings.
     *
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);

        $tool = new backup_nested_element('tool', ['id'], ['toolversion', 'reviewdate', 'reviewedby', 'conditions',
            'resources', 'instructions', 'adjustments', 'timemodified']);
        $wrapper->add_child($tool);
        $tool->set_source_table('report_evidencereporter_tool', ['cmid' => backup::VAR_MODID]);

        $feedbackfor = new backup_nested_element('feedbackfor', ['id'], ['assesscmid']);
        $wrapper->add_child($feedbackfor);
        $feedbackfor->set_source_table('report_evidencereporter_fbk', ['cmid' => backup::VAR_MODID]);

        return $plugin;
    }
}
