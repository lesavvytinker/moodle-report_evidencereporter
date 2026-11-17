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

namespace report_evidencereporter\tests\fixtures;

use report_evidencereporter\hook\collect_sources;
use report_evidencereporter\local\evidence_pack;
use report_evidencereporter\local\identity;
use report_evidencereporter\local\source\base;

/**
 * A source another plugin might add, for Page activities, used to test the collect_sources hook.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class test_source extends base {
    /**
     * Hook callback.
     *
     * @param collect_sources $hook
     */
    public static function register(collect_sources $hook): void {
        $hook->add(new self());
    }

    #[\Override]
    public function get_modname(): string {
        return 'page';
    }

    #[\Override]
    public function get_url(\stdClass $row): ?\moodle_url {
        return new \moodle_url('/mod/page/view.php', ['id' => (int) $row->cmid, 'learner' => (int) $row->userid]);
    }

    #[\Override]
    public function feedback_follows_release(): bool {
        return false;
    }

    #[\Override]
    public function has_own_feedback(\stdClass $row): bool {
        return true;
    }

    #[\Override]
    public function get_feedback_html(\stdClass $row, bool $viewfullnames, ?identity $identity = null): string {
        return '<p>Observed by the page source</p>';
    }

    #[\Override]
    public function add_records_to_pack(evidence_pack $pack, array $competencyids): void {
        $pack->add_content('02 Assessments/Page record.pdf', $pack->html_to_pdf(
            'Page record',
            '<h2>Page record</h2><table>' . $pack->learner_rows() . '</table>'
        ));
    }
}
