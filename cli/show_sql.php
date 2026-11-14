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
 * Print the SQL a custom report runs, as a given user, without running it.
 *
 * Usage:
 *   php report/evidencereporter/cli/show_sql.php --list
 *   php report/evidencereporter/cli/show_sql.php --id=5 [--user=er_teacher_c1only] [--inline]
 *
 * Works for any custom report, not only ones built on the Competency evidence source.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use core_reportbuilder\table\custom_report_table_view;

[$options] = cli_get_params(
    ['id' => 0, 'user' => 'admin', 'list' => false, 'inline' => false, 'help' => false],
    ['h' => 'help']
);

if ($options['help'] || (!$options['list'] && empty($options['id']))) {
    echo "Show the SQL behind a custom report.

Options:
  --list         List custom reports (id, name, source)
  --id=N         Report ID to show
  --user=NAME    Username to build the SQL as (default admin). Row permissions depend on the viewer.
  --inline       Substitute parameter values into the SQL (for pasting into a SQL client)
  -h, --help     Show this help
";
    exit(0);
}

if ($options['list']) {
    $reports = $DB->get_records('reportbuilder_report', ['type' => 0], 'id', 'id, name, source');
    foreach ($reports as $report) {
        echo str_pad((string) $report->id, 6) . str_pad($report->name, 40) . $report->source . "\n";
    }
    exit(0);
}

$user = $DB->get_record('user', ['username' => $options['user'], 'deleted' => 0]);
if (!$user) {
    cli_error("User '{$options['user']}' not found.");
}
\core\session\manager::set_user($user);

$table = custom_report_table_view::create((int) $options['id']);
$table->setup();

$method = new ReflectionMethod($table, 'get_table_sql');
$sql = $method->invoke($table, true);
$params = $table->sql->params;

// Expand {table} to the real prefixed name.
$sql = preg_replace('/\{([a-z][a-z0-9_]*)\}/', $CFG->prefix . '$1', $sql);

if ($options['inline']) {
    // Longest names first so :param1 doesn't clobber :param10.
    uksort($params, fn($a, $b) => strlen($b) <=> strlen($a));
    foreach ($params as $name => $value) {
        $literal = is_int($value) || is_float($value) ? (string) $value : "'" . str_replace("'", "''", (string) $value) . "'";
        $sql = str_replace(':' . $name, $literal, $sql);
    }
    echo $sql . ";\n";
} else {
    echo $sql . "\n\n-- Parameters\n";
    foreach ($params as $name => $value) {
        echo "-- :{$name} = " . var_export($value, true) . "\n";
    }
}
