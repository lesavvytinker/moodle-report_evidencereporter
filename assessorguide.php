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
 * Download the blank assessor guide (the assessment tool with model answers) for a quiz.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use report_evidencereporter\local\assessor_guide;

$cmid = required_param('cmid', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($cmid);
require_login($course, false, $cm);
require_capability('report/evidencereporter:assessorguide', context_module::instance($cm->id));
if (!assessor_guide::supports($cm->modname)) {
    throw new moodle_exception('assessorguideunsupported', 'report_evidencereporter');
}

$guide = new assessor_guide($cm);
$pdf = $guide->build_pdf();
\core\session\manager::write_close();
// Shown in the browser, ready to print or save; download=1 saves it straight away.
send_file($pdf, $guide->get_filename(), 0, 0, true, optional_param('download', 0, PARAM_BOOL), 'application/pdf');
