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

#[\PHPUnit\Framework\Attributes\CoversClass(identity::class)]
/**
 * Tests for the learner identity and redaction helper.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\local\identity
 */
final class identity_test extends \advanced_testcase {
    /**
     * A learner to work with.
     *
     * @return \stdClass
     */
    private function learner(): \stdClass {
        return $this->getDataGenerator()->create_user(['firstname' => 'Ethan', 'lastname' => 'Brown', 'username' => 'ebrown',
            'idnumber' => 'S1001', 'email' => 'ethan.brown@example.com']);
    }

    /**
     * Nothing redacted: everything shows, text is untouched.
     */
    public function test_no_redaction(): void {
        $this->resetAfterTest();
        $learner = $this->learner();
        $identity = new identity($learner);
        $this->assertEquals('Ethan Brown', $identity->name());
        $this->assertEquals('ebrown', $identity->username());
        $this->assertEquals('S1001', $identity->idnumber());
        $this->assertEquals('Mel Marker', $identity->staff('Mel Marker'));
        $this->assertEquals('Ethan Brown (S1001)', $identity->file_label());
        $this->assertFalse($identity->is_redacted());
        $this->assertSame('', $identity->redaction_note());
        $this->assertEquals('Good work Ethan', $identity->text('Good work Ethan'));
        $this->assertEquals(['Learner', 'Username', 'ID number', 'Learner reference'], array_keys($identity->rows()));
    }

    /**
     * The reference is stable per learner and site, and differs between learners.
     */
    public function test_reference(): void {
        $this->resetAfterTest();
        $a = $this->learner();
        $b = $this->getDataGenerator()->create_user();
        $this->assertEquals((new identity($a))->reference(), (new identity($a, true, true, true))->reference());
        $this->assertNotEquals((new identity($a))->reference(), (new identity($b))->reference());
        $this->assertMatchesRegularExpression('/^L-[0-9A-F]{8}$/', (new identity($b))->reference());
    }

    /**
     * Name redaction: whole words in any case, longest first; other words left alone.
     */
    public function test_name_redaction(): void {
        $this->resetAfterTest();
        $identity = identity::from_flags($this->learner(), ['redactname' => 1]);
        $this->assertEquals('[redacted]', $identity->name());
        $this->assertEquals('ebrown', $identity->username());
        $this->assertEquals(
            '<p>Well done [redacted]. [redacted] met the criteria; Browning is fine.</p>',
            $identity->text('<p>Well done ETHAN BROWN. ethan met the criteria; Browning is fine.</p>')
        );
        // The email goes even when only the name is redacted.
        $this->assertEquals('Contact [redacted]', $identity->text('Contact ethan.brown@example.com'));
        $this->assertEquals($identity->reference() . ' (S1001)', $identity->file_label());
        $this->assertEquals('Ethan_Brown_report.docx', $identity->text('Ethan_Brown_report.docx'));
        $this->assertEquals('redacted_redacted_report.docx', $identity->text('Ethan_Brown_report.docx', true));
    }

    /**
     * Name parts without letters are only redacted as part of the full name.
     */
    public function test_numeric_name_part(): void {
        $this->resetAfterTest();
        $learner = $this->getDataGenerator()->create_user(['firstname' => 'Student', 'lastname' => '09']);
        $identity = new identity($learner, true);
        $this->assertEquals(
            '[redacted] marked at 09:00; [redacted] resubmitted.',
            $identity->text('Student 09 marked at 09:00; student resubmitted.')
        );
    }

    /**
     * Username redaction covers ID number and email; staff redaction covers others but not the learner.
     */
    public function test_username_and_staff_redaction(): void {
        $this->resetAfterTest();
        $learner = $this->learner();
        $identity = new identity($learner, false, true, true);
        $this->assertEquals('Ethan Brown', $identity->name());
        $this->assertEquals('[redacted]', $identity->username());
        $this->assertEquals('[redacted]', $identity->idnumber());
        $this->assertEquals('User [redacted] ([redacted])', $identity->text('User ebrown (S1001)'));
        $this->assertEquals('[redacted]', $identity->staff('Mel Marker'));
        $this->assertSame('', $identity->staff(''));
        $this->assertEquals('Ethan Brown', $identity->person((int) $learner->id, 'Ethan Brown'));
        $this->assertEquals('[redacted]', $identity->person((int) $learner->id + 1, 'Mel Marker'));
        $this->assertEquals('Ethan Brown (' . $identity->reference() . ')', $identity->file_label());
        $this->assertEquals('Redacted: learner username, ID number and email, staff names. '
            . 'The learner reference identifies the learner without naming them.', $identity->redaction_note());
        $this->assertEquals(['redactname' => 0, 'redactusername' => 1, 'redactstaff' => 1], $identity->get_flags());
    }
}
