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
use report_evidencereporter\local\sampling;

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

    /**
     * Validation samples: learners' judgements are exported and deleted; staff are exported and anonymised.
     */
    public function test_samples(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $learner = $gen->create_user();
        $other = $gen->create_user();
        $assessor = $gen->create_user();
        $drawer = $gen->create_user();
        $sampleid = $DB->insert_record(sampling::TABLE, ['name' => 'Audit', 'filters' => '{}', 'seed' => 1,
            'population' => 2, 'populationhash' => sha1('x'), 'createdby' => $drawer->id, 'timecreated' => time(),
            'timemodified' => time()]);
        foreach ([$learner, $other] as $user) {
            $DB->insert_record(sampling::ITEMS, ['sampleid' => $sampleid, 'userid' => $user->id, 'competencyid' => 1,
                'courseid' => 1, 'assessorid' => $assessor->id, 'result' => 'Competent', 'groupkey' => '',
                'reason' => 'drawn', 'replacesid' => 0, 'removed' => 0, 'removedby' => 0, 'timeremoved' => 0,
                'judged' => time(), 'timecreated' => time()]);
        }
        $system = \context_system::instance();

        foreach ([$learner, $assessor, $drawer] as $user) {
            $this->assertEquals([$system->id], provider::get_contexts_for_userid((int) $user->id)->get_contextids());
        }
        $userlist = new userlist($system, 'report_evidencereporter');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$learner->id, $other->id, $assessor->id, $drawer->id], $userlist->get_userids());

        $this->export_context_data_for_user((int) $learner->id, $system, 'report_evidencereporter');
        $data = writer::with_context($system)->get_data([get_string('sampling', 'report_evidencereporter')]);
        $this->assertCount(1, $data->drawn);
        $this->assertEquals('Audit', $data->drawn[0]['sample']);

        // The learner leaves the sample; the assessor and the person who drew it are anonymised.
        provider::delete_data_for_user(new approved_contextlist($learner, 'report_evidencereporter', [$system->id]));
        $this->assertFalse($DB->record_exists(sampling::ITEMS, ['userid' => $learner->id]));
        $this->assertTrue($DB->record_exists(sampling::ITEMS, ['userid' => $other->id]));
        provider::delete_data_for_users(new approved_userlist(
            $system,
            'report_evidencereporter',
            [$assessor->id, $drawer->id]
        ));
        $this->assertFalse($DB->record_exists(sampling::ITEMS, ['assessorid' => $assessor->id]));
        $this->assertEquals(0, $DB->get_field(sampling::TABLE, 'createdby', ['id' => $sampleid]));

        provider::delete_data_for_all_users_in_context($system);
        $this->assertEquals(0, $DB->count_records(sampling::ITEMS));
        $this->assertEquals(0, $DB->count_records(sampling::TABLE));
    }
}
