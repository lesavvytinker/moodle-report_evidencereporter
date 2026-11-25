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
 * Library callbacks for Evidence Reporter.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add a link to the printable evidence summary on the user's profile, in the Reports section.
 *
 * @param core_user\output\myprofile\tree $tree
 * @param stdClass $user The profile being viewed
 * @param bool $iscurrentuser
 * @param stdClass|null $course
 * @return void
 */
function report_evidencereporter_myprofile_navigation(core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    if (!\core_competency\api::is_enabled() || isguestuser($user) || !isloggedin()) {
        return;
    }
    if (!\report_evidencereporter\local\summary::has_visible_evidence((int) $user->id)) {
        return;
    }
    $url = new moodle_url('/report/evidencereporter/print.php', ['userid' => $user->id]);
    $node = new core_user\output\myprofile\node(
        'reports',
        'evidencesummary',
        \report_evidencereporter\local\terms::get('summarytitle'),
        null,
        $url
    );
    $tree->add_node($node);
}

/**
 * Serve bulk downloads, only to the person who requested them.
 *
 * @param stdClass $course
 * @param stdClass|null $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool false if the file isn't found or isn't theirs
 */
function report_evidencereporter_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $USER;
    // The organisation logo set in the plugin's settings, shown on evidence summaries.
    if ($context->contextlevel == CONTEXT_SYSTEM && $filearea === 'logo') {
        require_login(null, false);
        array_shift($args);
        $filename = array_pop($args);
        $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
        $file = get_file_storage()->get_file($context->id, 'report_evidencereporter', 'logo', 0, $filepath, $filename);
        if (!$file || $file->is_directory()) {
            return false;
        }
        send_stored_file($file, DAYSECS, 0, false, $options);
    }
    if ($context->contextlevel != CONTEXT_USER || $filearea !== \report_evidencereporter\local\bulk::FILEAREA) {
        return false;
    }
    require_login(null, false);
    if ((int) $context->instanceid !== (int) $USER->id) {
        return false;
    }
    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $file = get_file_storage()->get_file($context->id, 'report_evidencereporter', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, true, $options);
}

/**
 * Add the "Assessment tool details" section to the settings of activities Evidence Reporter reads.
 *
 * @param moodleform_mod $formwrapper
 * @param MoodleQuickForm $mform
 */
function report_evidencereporter_coursemodule_standard_elements($formwrapper, $mform) {
    $cm = $formwrapper->get_coursemodule();
    $modname = $formwrapper->get_current()->modulename ?? ($cm->modname ?? '');
    \report_evidencereporter\local\tool_details::add_form_elements($mform, (string) $modname, $cm ? (int) $cm->id : 0);
}

/**
 * Save the "Assessment tool details" section.
 *
 * @param stdClass $data
 * @param stdClass $course
 * @return stdClass
 */
function report_evidencereporter_coursemodule_edit_post_actions($data, $course) {
    \report_evidencereporter\local\tool_details::save_from_form($data);
    return $data;
}

/**
 * Add "Assessor guide" and "Reasonable adjustments" to the activity's navigation (the More menu).
 *
 * @param navigation_node $navigation
 * @param cm_info $cm
 */
function report_evidencereporter_extend_navigation_module($navigation, $cm) {
    if (\report_evidencereporter\local\assessor_guide::can_view($cm)) {
        $navigation->add(
            get_string('assessorguide', 'report_evidencereporter'),
            new moodle_url('/report/evidencereporter/assessorguide.php', ['cmid' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'evidencereporterassessorguide',
            new pix_icon('t/print', '')
        );
    }
    $context = context_module::instance($cm->id);
    if (
        \report_evidencereporter\local\reasonable_adjustments::available($cm)
        && has_any_capability(
            ['report/evidencereporter:viewadjustments', 'report/evidencereporter:manageadjustments'],
            $context
        )
    ) {
        $navigation->add(
            get_string('adjustments', 'report_evidencereporter'),
            new moodle_url('/report/evidencereporter/adjustments.php', ['cmid' => $cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'evidencereporteradjustments',
            new pix_icon('i/users', '')
        );
    }
}
