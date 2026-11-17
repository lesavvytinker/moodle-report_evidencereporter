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

namespace report_evidencereporter\local\source;

use moodle_url;
use stdClass;

/**
 * Forums: links to the learner's posts in the course.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forum extends base {
    #[\Override]
    public function get_modname(): string {
        return 'forum';
    }

    #[\Override]
    public function get_description(): string {
        return get_string('sourcedesc_forum', 'report_evidencereporter');
    }

    #[\Override]
    public function get_url(stdClass $row): ?moodle_url {
        return new moodle_url('/mod/forum/user.php', ['id' => (int) ($row->userid ?? 0), 'course' => (int) $row->courseid]);
    }
}
