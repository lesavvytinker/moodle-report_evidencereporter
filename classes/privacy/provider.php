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

namespace report_evidencereporter\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\provider as metadata_provider;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\plugin\provider as plugin_provider;
use core_privacy\local\request\{approved_contextlist, approved_userlist, contextlist, userlist, writer};
use report_evidencereporter\local\bulk;

/**
 * Privacy provider.
 *
 * The plugin only stores bulk evidence downloads: ZIP files kept in the requesting user's own file area for
 * {@see bulk::KEEP_DAYS} days. Everything else it shows comes from the competency and gradebook subsystems.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements core_userlist_provider, metadata_provider, plugin_provider {
    /**
     * Describe stored data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:bulkfiles');
        return $collection;
    }

    /**
     * Contexts with data for a user: their user context, if they have bulk downloads.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {files} f ON f.contextid = ctx.id
                 WHERE ctx.contextlevel = :contextlevel AND ctx.instanceid = :userid
                   AND f.component = :component AND f.filearea = :filearea";
        $contextlist->add_from_sql($sql, ['contextlevel' => CONTEXT_USER, 'userid' => $userid,
            'component' => 'report_evidencereporter', 'filearea' => bulk::FILEAREA]);
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_USER) {
            return;
        }
        if (get_file_storage()->get_area_files($context->id, 'report_evidencereporter', bulk::FILEAREA, false, 'id', false)) {
            $userlist->add_user($context->instanceid);
        }
    }

    /**
     * Export a user's bulk downloads.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_USER && $context->instanceid == $contextlist->get_user()->id) {
                foreach (bulk::get_files((int) $context->instanceid) as $file) {
                    writer::with_context($context)->export_file([get_string('bulkdownloads', 'report_evidencereporter')], $file);
                }
            }
        }
    }

    /**
     * Delete all data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context->contextlevel == CONTEXT_USER) {
            get_file_storage()->delete_area_files($context->id, 'report_evidencereporter', bulk::FILEAREA);
        }
    }

    /**
     * Delete a user's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_USER && $context->instanceid == $contextlist->get_user()->id) {
                get_file_storage()->delete_area_files($context->id, 'report_evidencereporter', bulk::FILEAREA);
            }
        }
    }

    /**
     * Delete data for the approved users in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_USER && in_array($context->instanceid, $userlist->get_userids())) {
            get_file_storage()->delete_area_files($context->id, 'report_evidencereporter', bulk::FILEAREA);
        }
    }
}
