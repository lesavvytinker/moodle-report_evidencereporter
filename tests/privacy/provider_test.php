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

namespace report_evidencereporter\privacy;

use core_privacy\local\request\{approved_contextlist, approved_userlist, userlist, writer};
use core_privacy\tests\provider_testcase;
use report_evidencereporter\local\bulk;

#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
/**
 * Privacy provider tests.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \report_evidencereporter\privacy\provider
 */
final class provider_test extends provider_testcase {
    /**
     * Store a bulk download for a user.
     *
     * @param int $userid
     * @return \context_user
     */
    private function create_download(int $userid): \context_user {
        $context = \context_user::instance($userid);
        get_file_storage()->create_file_from_string(['contextid' => $context->id, 'component' => 'report_evidencereporter',
            'filearea' => bulk::FILEAREA, 'itemid' => 1, 'filepath' => '/', 'filename' => 'bulk.zip'], 'zip');
        return $context;
    }

    /**
     * Contexts, users, export and deletion of bulk downloads.
     */
    public function test_provider(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $context = $this->create_download((int) $user->id);
        $othercontext = $this->create_download((int) $other->id);

        $this->assertEquals([$context->id], provider::get_contexts_for_userid((int) $user->id)->get_contextids());
        $userlist = new userlist($context, 'report_evidencereporter');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$user->id], $userlist->get_userids());

        $approved = new approved_contextlist($user, 'report_evidencereporter', [$context->id]);
        provider::export_user_data($approved);
        $this->assertTrue(writer::with_context($context)->has_any_data());

        provider::delete_data_for_user($approved);
        $this->assertCount(0, bulk::get_files((int) $user->id));
        $this->assertCount(1, bulk::get_files((int) $other->id));

        provider::delete_data_for_users(new approved_userlist($othercontext, 'report_evidencereporter', [$other->id]));
        $this->assertCount(0, bulk::get_files((int) $other->id));

        $this->create_download((int) $user->id);
        provider::delete_data_for_all_users_in_context($context);
        $this->assertCount(0, bulk::get_files((int) $user->id));
    }
}
