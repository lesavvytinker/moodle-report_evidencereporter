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
 * Download a learner's evidence pack (ZIP) for one competency framework.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use report_evidencereporter\local\{evidence_pack, scope, summary};

$userid = required_param('userid', PARAM_INT);
$frameworkid = optional_param('frameworkid', 0, PARAM_INT);
$planid = optional_param('planid', 0, PARAM_INT);
$scopetype = optional_param('scope', '', PARAM_ALPHA);
$includefeedback = optional_param('includefeedback', 1, PARAM_BOOL);
$redact = [
    'redactname' => optional_param('redactname', 0, PARAM_BOOL),
    'redactusername' => optional_param('redactusername', 0, PARAM_BOOL),
    'redactstaff' => optional_param('redactstaff', 0, PARAM_BOOL),
];

require_login(null, false);
require_sesskey();
if (!\core_competency\api::is_enabled()) {
    throw new moodle_exception('competenciesarenotenabled', 'core_competency');
}

$learner = core_user::get_user($userid, '*', MUST_EXIST);
$context = context_user::instance($learner->id);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/report/evidencereporter/pack.php', ['userid' => $userid]));

// The same access as the summary: the plan or framework must be visible to the current user.
$frameworks = summary::get_frameworks($learner->id);
$scope = scope::from_params($learner->id, $scopetype, $planid, $frameworkid, $frameworks)
    ?? scope::get_default($learner->id, $frameworks);
if (!$scope || (!$scope->is_plan() && !summary::has_visible_evidence($learner->id))) {
    throw new moodle_exception('nopermissiontoviewevidence', 'report_evidencereporter');
}

$pack = new evidence_pack($learner->id, $scope, (bool) $includefeedback, $redact);
$zippath = $pack->build();

\report_evidencereporter\event\pack_downloaded::create([
    'context' => $context,
    'relateduserid' => $learner->id,
    'other' => $scope->get_event_data() + ['includefeedback' => (int) $includefeedback] + array_map('intval', $redact),
])->trigger();

send_temp_file($zippath, $pack->get_filename());
