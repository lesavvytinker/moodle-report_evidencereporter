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

/**
 * Making Evidence Reporter fit different needs: evidence sources (built-in and added by other plugins), terminology
 * profiles and document branding.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(sources::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(terms::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(branding::class)]
final class configurable_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        sources::reset_cache();
        attempts::reset_cache();
        feedback_visibility::reset_cache();
    }

    #[\Override]
    protected function tearDown(): void {
        sources::reset_cache();
        terms::use_profile(null);
        parent::tearDown();
    }

    /**
     * A learner with an assignment graded against a competency, and a Page activity with evidence.
     *
     * @return array framework, student, assign cmid, page cmid
     */
    private function setup_data(): array {
        $gen = $this->getDataGenerator();
        /** @var \core_competency_generator $cgen */
        $cgen = $gen->get_plugin_generator('core_competency');
        /** @var \report_evidencereporter_generator $egen */
        $egen = $gen->get_plugin_generator('report_evidencereporter');
        $framework = $cgen->create_framework(['shortname' => 'Outcomes', 'idnumber' => 'OUT']);
        $comp = $cgen->create_competency(['competencyframeworkid' => $framework->get('id'), 'shortname' => 'Analyse',
            'idnumber' => 'CLO1']);
        $course = $gen->create_course(['shortname' => 'BUS101']);
        $student = $gen->create_user(['firstname' => 'Ari', 'lastname' => 'Learner']);
        $gen->enrol_user($student->id, $course->id, 'student');
        $assign = $gen->create_module('assign', ['course' => $course->id, 'name' => 'Case study']);
        $page = $gen->create_module('page', ['course' => $course->id, 'name' => 'Placement log']);
        foreach ([$assign->cmid, $page->cmid] as $cmid) {
            $cgen->create_course_module_competency(['cmid' => $cmid, 'competencyid' => $comp->get('id')]);
            $egen->create_evidence(['userid' => $student->id, 'competencyid' => $comp->get('id'), 'cmid' => $cmid,
                'action' => \core_competency\evidence::ACTION_LOG]);
        }
        return [$framework, $student, (int) $assign->cmid, (int) $page->cmid];
    }

    /**
     * Add the test source through the hook, as another plugin would.
     */
    private function add_test_source(): void {
        global $CFG;
        require_once($CFG->dirroot . '/report/evidencereporter/tests/fixtures/test_source.php');
        \core\di::set(\core\hook\manager::class, \core\hook\manager::phpunit_get_instance([
            'report_evidencereporter' => $CFG->dirroot . '/report/evidencereporter/tests/fixtures/hooks.php',
        ]));
        sources::reset_cache();
    }

    /**
     * Built-in sources are listed with descriptions; activities without a source link to the activity page.
     */
    public function test_builtin_sources(): void {
        $this->resetAfterTest();
        [$framework, $student, $assigncmid, $pagecmid] = $this->setup_data();
        $this->setAdminUser();

        $all = sources::all();
        foreach (['assign', 'quiz', 'data', 'forum'] as $modname) {
            $this->assertArrayHasKey($modname, $all);
            $this->assertNotEmpty($all[$modname]->get_description());
        }
        $this->assertTrue(sources::get('assign')->has_attempts());
        $this->assertFalse(sources::get('forum')->has_attempts());
        $this->assertNull(sources::get('page'));
        $this->assertArrayHasKey('quizattemptid', sources::row_fields_sql('m.name', 'cm.instance', 'u.id'));
        $this->assertArrayHasKey('datarecordid', sources::row_fields_sql('m.name', 'cm.instance', 'u.id'));

        $rows = summary::build((int) $student->id, $framework->get('id'))->competencies[0]->evidence;
        $urls = array_column($rows, 'url');
        $this->assertContains((new \moodle_url('/mod/assign/view.php', ['id' => $assigncmid, 'action' => 'grader',
            'userid' => $student->id]))->out(false), $urls);
        $this->assertContains((new \moodle_url('/mod/page/view.php', ['id' => $pagecmid]))->out(false), $urls);
    }

    /**
     * Another plugin's source supplies the link, the feedback and pack records for its activity type.
     */
    public function test_source_added_by_hook(): void {
        $this->resetAfterTest();
        [$framework, $student, , $pagecmid] = $this->setup_data();
        $this->setAdminUser();
        $this->add_test_source();

        $this->assertArrayHasKey('page', sources::all());
        $this->assertArrayHasKey('assign', sources::all());
        $rows = summary::build((int) $student->id, $framework->get('id'))->competencies[0]->evidence;
        $pagerow = array_values(array_filter($rows, fn($row) => str_contains($row->url, '/mod/page/')))[0];
        $this->assertStringContainsString('learner=' . $student->id, $pagerow->url);
        $this->assertEquals('<p>Observed by the page source</p>', $pagerow->feedback);

        $pack = new evidence_pack((int) $student->id, $framework->get('id'));
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($pack->build()));
        $this->assertNotFalse($zip->locateName('02 Assessments/Page record.pdf'));
        $zip->close();
    }

    /**
     * Terminology profiles change the words on documents; anything a profile doesn't change uses the default.
     */
    public function test_terminology_profiles(): void {
        global $PAGE;
        $this->resetAfterTest();
        [$framework, $student] = $this->setup_data();
        $this->setAdminUser();

        $this->assertEquals('Competency', terms::get('competency'));
        $this->assertEquals('Learner', terms::get('learner'));

        set_config('profile', terms::HIGHER_ED, 'report_evidencereporter');
        $this->assertEquals('Learning outcome', terms::get('competency'));
        $this->assertEquals('Student', terms::get('learner'));
        $this->assertEquals('Marker', terms::get('assessor'));
        $this->assertEquals('Evidence pack', terms::get('evidencepack'));
        $this->assertEquals(
            '2 learning outcomes · 1 with evidence · 0 achieved',
            terms::get('overviewcounts', ['total' => 2, 'evidence' => 1, 'proficient' => 0])
        );

        $learner = \core_user::get_user($student->id);
        $page = new \report_evidencereporter\output\summary_page(
            $learner,
            $framework->to_record(),
            summary::build((int) $student->id, $framework->get('id'))
        );
        $html = $PAGE->get_renderer('core')->render_from_template(
            'report_evidencereporter/summary',
            $page->export_for_template($PAGE->get_renderer('core'))
        );
        $this->assertStringContainsString('Learning outcome evidence summary', $html);
        $this->assertStringContainsString('<th scope="row">Student</th>', $html);
        $this->assertStringContainsString('Outcomes framework', $html);
        $this->assertStringContainsString('1 learning outcomes · 1 with evidence · 0 achieved', $html);

        set_config('profile', terms::ZA_OCCUPATIONAL, 'report_evidencereporter');
        $pack = new evidence_pack((int) $student->id, $framework->get('id'));
        $this->assertStringStartsWith('Portfolio of evidence - Ari Learner', $pack->get_filename());
        $pack->build();
        $this->assertStringContainsString('Portfolio of evidence summary', $pack->get_rendered_html()[0]);

        // An unknown profile falls back to Australian VET.
        set_config('profile', 'nonsense', 'report_evidencereporter');
        $this->assertEquals(terms::AU_VET, terms::get_site_profile());
    }

    /**
     * Branding: organisation name, header and footer, sign-off wording, sections and file names.
     */
    public function test_branding(): void {
        global $PAGE;
        $this->resetAfterTest();
        [$framework, $student] = $this->setup_data();
        $this->setAdminUser();

        set_config('orgname', 'Example Institute', 'report_evidencereporter');
        set_config('documentheader', 'Provider 12345', 'report_evidencereporter');
        set_config('documentfooter', 'Confidential: for audit use only', 'report_evidencereporter');
        set_config('signoffheading', 'Assessor declaration', 'report_evidencereporter');
        set_config('signoffstatement', 'I confirm this evidence is valid and sufficient.', 'report_evidencereporter');
        set_config('filenamepattern', '{orgname} - {learner} - {date}', 'report_evidencereporter');

        $pack = new evidence_pack((int) $student->id, $framework->get('id'));
        $this->assertEquals('Example Institute - Ari Learner (' . $student->username . ') - ' . userdate(time(), '%Y-%m-%d')
            . '.zip', $pack->get_filename());
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($pack->build()));
        $readme = $zip->getFromName('README.txt');
        $zip->close();
        $this->assertStringContainsString('Evidence pack · Example Institute', $readme);
        $this->assertStringContainsString('Provider 12345', $readme);
        $this->assertStringContainsString('Confidential: for audit use only', $readme);
        $summary = $pack->get_rendered_html()[0];
        $this->assertStringContainsString('Example Institute<br>Provider 12345', $summary);
        $this->assertStringContainsString('<strong>Assessor declaration</strong>', $summary);
        $this->assertStringContainsString('I confirm this evidence is valid and sufficient.', $summary);
        $this->assertStringContainsString('Confidential: for audit use only', $summary);

        // A pattern without the learner falls back to the default, so bulk downloads can't collide.
        set_config('filenamepattern', '{orgname} - {date}', 'report_evidencereporter');
        $this->assertStringStartsWith('Evidence pack - Ari Learner', $pack->get_filename());

        // Sections switched off.
        set_config('showsignoff', 0, 'report_evidencereporter');
        set_config('showevidencedetail', 0, 'report_evidencereporter');
        $learner = \core_user::get_user($student->id);
        $page = new \report_evidencereporter\output\summary_page(
            $learner,
            $framework->to_record(),
            summary::build((int) $student->id, $framework->get('id'))
        );
        $html = $PAGE->get_renderer('core')->render_from_template(
            'report_evidencereporter/summary',
            $page->export_for_template($PAGE->get_renderer('core'))
        );
        $this->assertStringContainsString('Example Institute', $html);
        $this->assertStringContainsString('Provider 12345', $html);
        $this->assertStringNotContainsString('er-signoff', $html);
        $this->assertStringNotContainsString('er-competency', $html);
        $this->assertStringContainsString('Confidential: for audit use only', $html);

        $pack = new evidence_pack((int) $student->id, $framework->get('id'));
        $pack->build_summary_pdf();
        $summary = $pack->get_rendered_html()[0];
        $this->assertStringNotContainsString('Assessor declaration', $summary);
        $this->assertStringNotContainsString('<h3>CLO1', $summary);
    }

    /**
     * Dual-sector providers: a profile per framework, with branding per profile; documents follow what they cover.
     */
    public function test_profile_per_framework(): void {
        $this->resetAfterTest();
        [$heframework, $student, $assigncmid] = $this->setup_data();
        $gen = $this->getDataGenerator();
        /** @var \core_competency_generator $cgen */
        $cgen = $gen->get_plugin_generator('core_competency');
        $vetframework = $cgen->create_framework(['shortname' => 'Certificate IV', 'idnumber' => 'CERT4']);
        $unit = $cgen->create_competency(['competencyframeworkid' => $vetframework->get('id'), 'shortname' => 'Unit',
            'idnumber' => 'UNIT1']);
        $gen->get_plugin_generator('report_evidencereporter')->create_evidence(['userid' => $student->id,
            'competencyid' => $unit->get('id'), 'cmid' => $assigncmid, 'action' => \core_competency\evidence::ACTION_LOG]);
        $this->setAdminUser();

        // Site profile VET; the outcomes framework is higher education, with its own name and declaration.
        $setting = new \report_evidencereporter\admin\setting_frameworkprofiles();
        $this->assertSame('', $setting->write_setting([0 => '', $heframework->get('id') => terms::HIGHER_ED,
            $vetframework->get('id') => '', 999 => 'nonsense']));
        $this->assertEquals([$heframework->get('id') => terms::HIGHER_ED], terms::get_framework_profiles());
        $this->assertStringContainsString('Site profile (Australian vocational', $setting->output_html($setting->get_setting()));
        set_config('orgname', 'Example Institute', 'report_evidencereporter');
        set_config('orgname_highered', 'Example University', 'report_evidencereporter');
        set_config('signoffstatement', 'Valid, sufficient, authentic and current.', 'report_evidencereporter');
        set_config('signoffstatement_highered', 'Assessed under the academic integrity policy.', 'report_evidencereporter');

        $he = scope::for_framework((int) $student->id, $heframework->get('id'));
        $vet = scope::for_framework((int) $student->id, $vetframework->get('id'));
        $this->assertEquals(terms::HIGHER_ED, $he->get_profile());
        $this->assertEquals(terms::AU_VET, $vet->get_profile());

        $pack = new evidence_pack((int) $student->id, $he);
        $pack->build_summary_pdf();
        $html = $pack->get_rendered_html()[0];
        $this->assertStringContainsString('Example University', $html);
        $this->assertStringContainsString('Learning outcome evidence summary', $html);
        $this->assertStringContainsString('Student', $html);
        $this->assertStringContainsString('Assessed under the academic integrity policy.', $html);

        $pack = new evidence_pack((int) $student->id, $vet);
        $pack->build_summary_pdf();
        $html = $pack->get_rendered_html()[0];
        $this->assertStringContainsString('Example Institute', $html);
        $this->assertStringContainsString('Competency evidence summary', $html);
        $this->assertStringContainsString('Valid, sufficient, authentic and current.', $html);

        // A degree plan uses the degree's words; a plan mixing both sectors uses the site profile.
        $plan = $cgen->create_plan(['userid' => $student->id, 'name' => 'Degree',
            'status' => \core_competency\plan::STATUS_ACTIVE]);
        $outcome = \core_competency\competency::get_record(['idnumber' => 'CLO1']);
        $cgen->create_plan_competency(['planid' => $plan->get('id'), 'competencyid' => $outcome->get('id')]);
        $this->assertEquals(terms::HIGHER_ED, scope::for_plan($plan)->get_profile());
        $cgen->create_plan_competency(['planid' => $plan->get('id'), 'competencyid' => $unit->get('id')]);
        $this->assertEquals(terms::AU_VET, scope::for_plan($plan)->get_profile());

        // Back to the site's words once the document is done.
        terms::use_profile(null);
        $this->assertEquals('Competency', terms::get('competency'));
    }
}
