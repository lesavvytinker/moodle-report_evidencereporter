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

namespace report_evidencereporter\task;

use report_evidencereporter\local\bulk;

/**
 * Background task: build a bulk download of evidence summaries or packs, then notify the person who asked.
 *
 * Runs as that person (set with set_userid() when queued), so their access applies to every learner.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_download extends \core\task\adhoc_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskbulkdownload', 'report_evidencereporter');
    }

    /**
     * Build the download and send the notification.
     */
    public function execute(): void {
        global $USER;
        $options = (array) $this->get_custom_data();
        // Permission may have been removed since the request was queued.
        if (!has_capability(bulk::CAPABILITY, \context_system::instance())) {
            mtrace('Requester no longer has ' . bulk::CAPABILITY . '; skipping.');
            return;
        }
        $file = bulk::build($options);
        mtrace('Built ' . $file->get_filename());

        $url = \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename(),
            true
        );
        $a = (object) ['filename' => $file->get_filename(), 'url' => $url->out(false), 'days' => bulk::KEEP_DAYS,
            'counts' => $file->get_source()];
        $message = new \core\message\message();
        $message->component = 'report_evidencereporter';
        $message->name = 'bulkready';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $USER;
        $message->subject = get_string('bulkreadysubject', 'report_evidencereporter');
        $message->fullmessage = get_string('bulkreadybody', 'report_evidencereporter', $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = get_string('bulkreadysubject', 'report_evidencereporter');
        $message->notification = 1;
        $message->contexturl = (new \moodle_url('/report/evidencereporter/bulk.php'))->out(false);
        $message->contexturlname = get_string('bulkdownloads', 'report_evidencereporter');
        message_send($message);
    }
}
