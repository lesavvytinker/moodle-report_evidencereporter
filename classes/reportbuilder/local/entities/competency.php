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

declare(strict_types=1);

namespace report_evidencereporter\reportbuilder\local\entities;

use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\{select, text};
use core_reportbuilder\local\report\{column, filter};
use lang_string;
use stdClass;

/**
 * Competency entity (competency plus its framework).
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class competency extends base {
    /**
     * Database tables that this entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [
            'competency',
            'competency_framework',
        ];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('competency', 'report_evidencereporter');
    }

    /**
     * Join from competency to its framework.
     *
     * @return string
     */
    private function get_framework_join(): string {
        $c = $this->get_table_alias('competency');
        $cf = $this->get_table_alias('competency_framework');
        return "JOIN {competency_framework} {$cf} ON {$cf}.id = {$c}.competencyframeworkid";
    }

    /**
     * Initialise the entity.
     *
     * @return base
     */
    public function initialise(): base {
        foreach ($this->get_all_columns() as $column) {
            $this->add_column($column);
        }
        foreach ($this->get_all_filters() as $filter) {
            $this->add_filter($filter)->add_condition($filter);
        }
        return $this;
    }

    /**
     * Returns list of all available columns.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        $c = $this->get_table_alias('competency');
        $cf = $this->get_table_alias('competency_framework');

        $columns[] = (new column(
            'name',
            new lang_string('competencyname', 'report_evidencereporter'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$c}.shortname")
            ->set_is_sortable(true)
            ->add_callback(static function (?string $name): string {
                return $name === null ? '' : format_string($name, true, ['context' => \context_system::instance()]);
            });

        $columns[] = (new column(
            'idnumber',
            new lang_string('competencyidnumber', 'report_evidencereporter'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$c}.idnumber")
            ->set_is_sortable(true)
            ->add_callback(static function (?string $idnumber): string {
                return $idnumber === null ? '' : s($idnumber);
            });

        $columns[] = (new column(
            'description',
            new lang_string('competencydescription', 'report_evidencereporter'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_LONGTEXT)
            ->add_fields("{$c}.description, {$c}.descriptionformat")
            ->set_is_sortable(false)
            ->add_callback(static function (?string $description, stdClass $row): string {
                if ($description === null) {
                    return '';
                }
                return format_text($description, (int) $row->descriptionformat, ['context' => \context_system::instance()]);
            });

        $columns[] = (new column(
            'framework',
            new lang_string('framework', 'report_evidencereporter'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_join($this->get_framework_join())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$cf}.shortname")
            ->set_is_sortable(true)
            ->add_callback(static function (?string $name): string {
                return $name === null ? '' : format_string($name, true, ['context' => \context_system::instance()]);
            });

        return $columns;
    }

    /**
     * Return list of all available filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        global $DB;
        $c = $this->get_table_alias('competency');

        $filters[] = (new filter(
            text::class,
            'name',
            new lang_string('competencyname', 'report_evidencereporter'),
            $this->get_entity_name(),
            "{$c}.shortname"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            text::class,
            'idnumber',
            new lang_string('competencyidnumber', 'report_evidencereporter'),
            $this->get_entity_name(),
            "{$c}.idnumber"
        ))
            ->add_joins($this->get_joins());

        $filters[] = (new filter(
            select::class,
            'framework',
            new lang_string('framework', 'report_evidencereporter'),
            $this->get_entity_name(),
            "{$c}.competencyframeworkid"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(static function () use ($DB): array {
                $options = [];
                foreach ($DB->get_records_menu('competency_framework', null, 'shortname', 'id, shortname') as $id => $name) {
                    $options[$id] = format_string($name, true, ['context' => \context_system::instance()]);
                }
                return $options;
            });

        return $filters;
    }
}
