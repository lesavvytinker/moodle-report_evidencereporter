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
 * Printable competency evidence summary for one learner.
 *
 * Without a framework: lists the frameworks the learner has evidence in. With one: shows the summary.
 * Shows only evidence the current user could also see in the evidence report.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use report_evidencereporter\local\{scope, summary};

$userid = required_param('userid', PARAM_INT);
$frameworkid = optional_param('frameworkid', 0, PARAM_INT);
$planid = optional_param('planid', 0, PARAM_INT);
$scopetype = optional_param('scope', '', PARAM_ALPHA);
// The "Covering" menu sends one value: plans, plan:<id> or framework:<id>.
$choice = optional_param('scopechoice', '', PARAM_RAW_TRIMMED);
if (preg_match('/^(plans|plan|framework)(?::(\d+))?$/', $choice)) {
    [$scopetype, $choiceid] = array_pad(explode(':', $choice, 2), 2, 0);
    $planid = $scopetype === 'plan' ? (int) $choiceid : 0;
    $frameworkid = $scopetype === 'framework' ? (int) $choiceid : 0;
}
$redact = [
    'redactname' => optional_param('redactname', 0, PARAM_BOOL),
    'redactusername' => optional_param('redactusername', 0, PARAM_BOOL),
    'redactstaff' => optional_param('redactstaff', 0, PARAM_BOOL),
];

require_login(null, false);
if (!\core_competency\api::is_enabled()) {
    throw new moodle_exception('competenciesarenotenabled', 'core_competency');
}

$learner = core_user::get_user($userid, '*', MUST_EXIST);
$context = context_user::instance($learner->id);
$url = new moodle_url('/report/evidencereporter/print.php', ['userid' => $learner->id]);
foreach (array_filter($redact) as $flag => $value) {
    $url->param($flag, 1);
}
$identity = \report_evidencereporter\local\identity::from_flags($learner, $redact);

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$title = get_string('summarytitle', 'report_evidencereporter');
$PAGE->set_title($title . ': ' . $identity->name());
$PAGE->set_heading($title);

$frameworks = summary::get_frameworks($learner->id);

// What to cover: as asked, otherwise the learner's active learning plans (or latest plan, or only framework).
$fallbackframeworkid = optional_param('fallbackframeworkid', 0, PARAM_INT);
$scope = scope::from_params($learner->id, $scopetype, $planid, $frameworkid, $frameworks)
    ?? scope::get_default($learner->id, $frameworks)
    ?? (isset($frameworks[$fallbackframeworkid]) ? scope::for_framework($learner->id, $fallbackframeworkid) : null);

// The same row access as the report: nothing visible means nothing to show. A learning plan the current user may
// see can be shown before it has any evidence, so its gaps are visible.
if (!($scope && $scope->is_plan()) && !summary::has_visible_evidence($learner->id)) {
    throw new moodle_exception('nopermissiontoviewevidence', 'report_evidencereporter');
}

if (!$scope) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('chooseframework', 'report_evidencereporter', fullname($learner)));
    $items = [];
    foreach ($frameworks as $framework) {
        $link = new moodle_url($url, ['scope' => scope::FRAMEWORK, 'frameworkid' => $framework->id]);
        $name = format_string($framework->shortname, true, ['context' => context_system::instance()]);
        $items[] = html_writer::link($link, $name) . ' '
            . html_writer::span(get_string('evidencecount', 'report_evidencereporter', $framework->evidencecount), 'text-muted');
    }
    echo html_writer::alist($items);
    echo $OUTPUT->footer();
    exit;
}

$url->params($scope->get_params());
$PAGE->set_url($url);
$PAGE->set_pagelayout('print');

\report_evidencereporter\event\summary_viewed::create([
    'context' => $context,
    'relateduserid' => $learner->id,
    'other' => $scope->get_event_data() + $identity->get_flags(),
])->trigger();

$PAGE->requires->js_init_code(<<<JS
    document.querySelectorAll('[data-action="report_evidencereporter-print"]').forEach((button) => {
        button.addEventListener('click', () => window.print());
    });
    // Choosing what to cover, and redaction, are applied by the server: reload when they change.
    document.querySelectorAll('[data-region="report_evidencereporter-scope"] select').forEach((select) => {
        select.addEventListener('change', () => select.form.submit());
    });
    document.querySelectorAll('[data-region="report_evidencereporter-redact"] input[type="checkbox"]').forEach((box) => {
        box.addEventListener('change', () => box.form.submit());
    });
    document.querySelectorAll('[data-action="report_evidencereporter-togglefeedback"]').forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
            const summary = checkbox.closest('.report-evidencereporter-summary');
            summary.classList.toggle('er-hidefeedback', !checkbox.checked);
            // The evidence pack follows the same choice.
            summary.querySelectorAll('[data-action="report_evidencereporter-pack"]').forEach((link) => {
                const url = new URL(link.href);
                url.searchParams.set('includefeedback', checkbox.checked ? '1' : '0');
                link.href = url.toString();
            });
        });
    });
JS);

$page = new \report_evidencereporter\output\summary_page(
    $learner,
    $scope,
    summary::build($learner->id, $scope, $identity),
    $identity,
    $frameworks
);
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('report_evidencereporter/summary', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
