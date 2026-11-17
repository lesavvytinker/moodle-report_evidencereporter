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

use stdClass;

/**
 * How a learner (and the staff around them) are identified on generated documents, with optional redaction.
 *
 * Every document shows the learner's name, username, ID number and a learner reference. The reference is a short
 * code that stays the same for that learner on this site, so redacted documents can still be matched up.
 *
 * Redaction options:
 *  - learner name: full, first and last name;
 *  - learner username: username, ID number and email address;
 *  - staff names: assessors, markers, raters and comment authors.
 * Learner identifiers are also removed from text Moodle generates (feedback, comments, online text). Files the
 * learner uploaded are included as they are.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class identity {
    /** @var string Replacement text. */
    private string $redacted;

    /**
     * Constructor.
     *
     * @param stdClass $learner User record (with name fields, username, idnumber, email)
     * @param bool $redactname
     * @param bool $redactusername
     * @param bool $redactstaff
     */
    public function __construct(
        /** @var stdClass The learner. */
        protected stdClass $learner,
        /** @var bool Redact the learner's name. */
        protected bool $redactname = false,
        /** @var bool Redact the learner's username, ID number and email. */
        protected bool $redactusername = false,
        /** @var bool Redact staff names. */
        protected bool $redactstaff = false
    ) {
        $this->redacted = get_string('redacted', 'report_evidencereporter');
    }

    /**
     * Build from request-style flags.
     *
     * @param stdClass $learner
     * @param array $flags redactname, redactusername, redactstaff
     * @return self
     */
    public static function from_flags(stdClass $learner, array $flags): self {
        return new self(
            $learner,
            !empty($flags['redactname']),
            !empty($flags['redactusername']),
            !empty($flags['redactstaff'])
        );
    }

    /**
     * The flags, for URLs and task data.
     *
     * @return array
     */
    public function get_flags(): array {
        return ['redactname' => (int) $this->redactname, 'redactusername' => (int) $this->redactusername,
            'redactstaff' => (int) $this->redactstaff];
    }

    /**
     * Learner's full name, or the redaction text.
     *
     * @return string
     */
    public function name(): string {
        return $this->redactname ? $this->redacted
            : fullname($this->learner, has_capability('moodle/site:viewfullnames', \context_system::instance()));
    }

    /**
     * Learner's username, or the redaction text.
     *
     * @return string
     */
    public function username(): string {
        return $this->redactusername ? $this->redacted : (string) $this->learner->username;
    }

    /**
     * Learner's ID number (empty if none), or the redaction text.
     *
     * @return string
     */
    public function idnumber(): string {
        if ((string) $this->learner->idnumber === '') {
            return '';
        }
        return $this->redactusername ? $this->redacted : (string) $this->learner->idnumber;
    }

    /**
     * Stable, non-identifying learner reference, e.g. "L-3F9A12C0".
     *
     * @return string
     */
    public function reference(): string {
        global $CFG;
        return 'L-' . strtoupper(substr(hash('sha256', ($CFG->siteidentifier ?? '') . ':' . $this->learner->id), 0, 8));
    }

    /**
     * A staff member's name, or the redaction text (empty stays empty).
     *
     * @param string $name
     * @return string
     */
    public function staff(string $name): string {
        return ($this->redactstaff && trim($name) !== '') ? $this->redacted : $name;
    }

    /**
     * The redaction text, safe in file names ("redacted").
     *
     * @return string
     */
    private function redacted_filename(): string {
        return trim(clean_filename(str_replace(['[', ']'], '', $this->redacted))) ?: 'redacted';
    }

    /**
     * Rows identifying the learner for official documents: label => value.
     *
     * @return array
     */
    public function rows(): array {
        $rows = [
            terms::get('learner') => $this->name(),
            get_string('username') => $this->username(),
        ];
        if ($this->idnumber() !== '') {
            $rows[get_string('idnumber')] = $this->idnumber();
        }
        $rows[get_string('learnerreference', 'report_evidencereporter')] = $this->reference();
        return $rows;
    }

    /**
     * Name of someone who acted on the evidence: the learner (e.g. their own comment) or a staff member.
     *
     * @param int $userid
     * @param string $name
     * @return string
     */
    public function person(int $userid, string $name): string {
        if ($userid && $userid === (int) $this->learner->id) {
            return $this->redactname ? $this->redacted : $name;
        }
        return $this->staff($name);
    }

    /**
     * Is anything redacted?
     *
     * @return bool
     */
    public function is_redacted(): bool {
        return $this->redactname || $this->redactusername || $this->redactstaff;
    }

    /**
     * What was redacted, for the documents themselves ("" when nothing).
     *
     * @return string
     */
    public function redaction_note(): string {
        $parts = [];
        foreach (
            ['redactname' => 'redactednamepart', 'redactusername' => 'redactedusernamepart',
                'redactstaff' => 'redactedstaffpart'] as $flag => $string
        ) {
            if ($this->{$flag}) {
                $parts[] = get_string($string, 'report_evidencereporter');
            }
        }
        return $parts ? get_string('redactionnote', 'report_evidencereporter', implode(', ', $parts)) : '';
    }

    /**
     * Label for file names: "Name (username)" with redacted parts dropped; the reference when both are redacted.
     *
     * @return string
     */
    public function file_label(): string {
        if ($this->redactname && $this->redactusername) {
            return $this->reference();
        }
        if ($this->redactname) {
            return $this->reference() . ' (' . ($this->learner->idnumber ?: $this->learner->username) . ')';
        }
        if ($this->redactusername) {
            return $this->name() . ' (' . $this->reference() . ')';
        }
        return $this->name() . ' (' . ($this->learner->idnumber ?: $this->learner->username) . ')';
    }

    /**
     * Remove the learner's identifiers from generated text (HTML or plain), as whole words, any case.
     *
     * @param string $text
     * @param bool $filename File names: also match between underscores, e.g. "Ethan_Brown_AT1.docx"
     * @return string
     */
    public function text(string $text, bool $filename = false): string {
        $terms = [];
        if ($this->redactname) {
            $terms[] = fullname($this->learner);
            foreach (\core_user\fields::get_name_fields() as $field) {
                // Single name parts only when they look like a name: "Student 09" must not redact "09" in times.
                $part = trim((string) ($this->learner->{$field} ?? ''));
                if (preg_match('/\p{L}/u', $part)) {
                    $terms[] = $part;
                }
            }
        }
        if ($this->redactusername) {
            $terms[] = (string) $this->learner->username;
            $terms[] = (string) $this->learner->idnumber;
        }
        if ($this->redactname || $this->redactusername) {
            $terms[] = (string) ($this->learner->email ?? '');
        }
        // Longest first, so a full name goes before its parts. Ignore one-letter terms.
        $terms = array_filter(array_unique(array_map('trim', $terms)), fn($t) => \core_text::strlen($t) > 1);
        usort($terms, fn($a, $b) => \core_text::strlen($b) <=> \core_text::strlen($a));
        foreach ($terms as $term) {
            $before = $filename ? '\p{L}\p{N}' : '\p{L}\p{N}_@.';
            $after = $filename ? '\p{L}\p{N}' : '\p{L}\p{N}_';
            $pattern = '/(?<![' . $before . '])' . preg_quote($term, '/') . '(?![' . $after . '])/iu';
            $text = preg_replace($pattern, $filename ? $this->redacted_filename() : $this->redacted, $text);
        }
        return $text;
    }
}
