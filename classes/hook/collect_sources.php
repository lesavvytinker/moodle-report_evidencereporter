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

namespace report_evidencereporter\hook;

use report_evidencereporter\local\source\base;

/**
 * Collect evidence sources: other plugins add sources for their own activity types.
 *
 * In your plugin's db/hooks.php:
 *
 *     $callbacks = [[
 *         'hook' => \report_evidencereporter\hook\collect_sources::class,
 *         'callback' => [\mod_example\local\evidence_source::class, 'register'],
 *     ]];
 *
 * and in the callback: $hook->add(new \mod_example\local\evidence_source());
 * where the class extends {@see \report_evidencereporter\local\source\base}.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to add evidence sources (activity types) to Evidence Reporter.')]
#[\core\attribute\tags('report_evidencereporter')]
final class collect_sources {
    /** @var base[] Sources added, by module name. */
    private array $sources = [];

    /**
     * Add (or replace) the source for an activity type.
     *
     * @param base $source
     */
    public function add(base $source): void {
        $this->sources[$source->get_modname()] = $source;
    }

    /**
     * The sources added.
     *
     * @return base[] modname => source
     */
    public function get_sources(): array {
        return $this->sources;
    }
}
