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

namespace report_evidencereporter\local;

#[\PHPUnit\Framework\Attributes\CoversClass(evidence_link::class)]
/**
 * Tests for links that open in a new tab.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\evidence_link
 */
final class evidence_link_test extends \advanced_testcase {
    /**
     * Our links open in a new tab and tell screen reader users so.
     */
    public function test_new_tab(): void {
        $html = evidence_link::new_tab(new \moodle_url('/mod/assign/view.php', ['id' => 5]), 'View evidence', ['class' => 'x']);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
        $this->assertStringContainsString('class="x"', $html);
        $this->assertStringContainsString('<span class="visually-hidden"> (Opens in new window)</span>', $html);
    }

    /**
     * Links inside feedback get a new tab, unless they already choose a target; other HTML is untouched.
     */
    public function test_new_tab_html(): void {
        $this->assertEquals('<p>No links</p>', evidence_link::new_tab_html('<p>No links</p>'));
        $this->assertEquals(
            '<p>See <a href="https://example.com/rubric" target="_blank" rel="noopener">the rubric</a> and '
                . '<A HREF="x" target="_self">this</A>.</p><abbr>RPL</abbr>',
            evidence_link::new_tab_html('<p>See <a href="https://example.com/rubric">the rubric</a> and '
                . '<A HREF="x" target="_self">this</A>.</p><abbr>RPL</abbr>')
        );
    }
}
