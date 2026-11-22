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

/**
 * Settings and admin menu links for Evidence Reporter.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$ADMIN->add('reports', new admin_externalpage(
    'report_evidencereporter_bulk',
    get_string('bulkdownloads', 'report_evidencereporter'),
    new moodle_url('/report/evidencereporter/bulk.php'),
    'report/evidencereporter:bulkdownload'
));
$ADMIN->add('reports', new admin_externalpage(
    'report_evidencereporter_sampling',
    get_string('sampling', 'report_evidencereporter'),
    new moodle_url('/report/evidencereporter/sampling.php'),
    'report/evidencereporter:bulkdownload'
));

if ($hassiteconfig && $settings) {
    // Terminology.
    $settings->add(new admin_setting_heading(
        'report_evidencereporter/termsheading',
        get_string('settings_terms', 'report_evidencereporter'),
        ''
    ));
    $settings->add(new admin_setting_configselect(
        'report_evidencereporter/profile',
        get_string('settings_profile', 'report_evidencereporter'),
        get_string('settings_profile_desc', 'report_evidencereporter'),
        \report_evidencereporter\local\terms::AU_VET,
        \report_evidencereporter\local\terms::get_profiles()
    ));

    $settings->add(new \report_evidencereporter\admin\setting_frameworkprofiles());

    // Branding.
    $settings->add(new admin_setting_heading(
        'report_evidencereporter/brandingheading',
        get_string('settings_branding', 'report_evidencereporter'),
        get_string('settings_branding_desc', 'report_evidencereporter')
    ));
    $settings->add(new admin_setting_configtext(
        'report_evidencereporter/orgname',
        get_string('settings_orgname', 'report_evidencereporter'),
        get_string('settings_orgname_desc', 'report_evidencereporter'),
        ''
    ));
    $settings->add(new admin_setting_configstoredfile(
        'report_evidencereporter/logo',
        get_string('settings_logo', 'report_evidencereporter'),
        get_string('settings_logo_desc', 'report_evidencereporter'),
        'logo',
        0,
        ['maxfiles' => 1, 'accepted_types' => ['.png', '.jpg', '.jpeg']]
    ));
    $settings->add(new admin_setting_configtextarea(
        'report_evidencereporter/documentheader',
        get_string('settings_documentheader', 'report_evidencereporter'),
        get_string('settings_documentheader_desc', 'report_evidencereporter'),
        '',
        PARAM_TEXT,
        60,
        3
    ));
    $settings->add(new admin_setting_configtextarea(
        'report_evidencereporter/documentfooter',
        get_string('settings_documentfooter', 'report_evidencereporter'),
        get_string('settings_documentfooter_desc', 'report_evidencereporter'),
        '',
        PARAM_TEXT,
        60,
        3
    ));
    $settings->add(new admin_setting_configtext(
        'report_evidencereporter/filenamepattern',
        get_string('settings_filenamepattern', 'report_evidencereporter'),
        get_string('settings_filenamepattern_desc', 'report_evidencereporter'),
        \report_evidencereporter\local\branding::DEFAULT_FILENAME,
        PARAM_TEXT,
        60
    ));

    // Summary sections and sign-off.
    $settings->add(new admin_setting_heading(
        'report_evidencereporter/sectionsheading',
        get_string('settings_sections', 'report_evidencereporter'),
        ''
    ));
    foreach (['evidencedetail', 'attempts', 'signoff'] as $section) {
        $settings->add(new admin_setting_configcheckbox(
            'report_evidencereporter/show' . $section,
            get_string('settings_show' . $section, 'report_evidencereporter'),
            get_string('settings_show' . $section . '_desc', 'report_evidencereporter'),
            1
        ));
    }
    $settings->add(new admin_setting_configtext(
        'report_evidencereporter/signoffheading',
        get_string('settings_signoffheading', 'report_evidencereporter'),
        get_string('settings_signoffheading_desc', 'report_evidencereporter'),
        ''
    ));
    $settings->add(new admin_setting_configtextarea(
        'report_evidencereporter/signoffstatement',
        get_string('settings_signoffstatement', 'report_evidencereporter'),
        get_string('settings_signoffstatement_desc', 'report_evidencereporter'),
        '',
        PARAM_TEXT,
        60,
        4
    ));

    // Gradebook adjustments: penalties in an assessment's own category.
    $settings->add(new admin_setting_heading(
        'report_evidencereporter/adjustmentsheading',
        get_string('settings_adjustments', 'report_evidencereporter'),
        get_string('settings_adjustments_desc', 'report_evidencereporter')
    ));
    $settings->add(new admin_setting_configselect(
        'report_evidencereporter/adjustmentdetail',
        get_string('settings_adjustmentdetail', 'report_evidencereporter'),
        get_string('settings_adjustmentdetail_desc', 'report_evidencereporter'),
        \report_evidencereporter\local\grade_adjustment::DETAIL_FULL,
        [
            'full' => get_string('settings_adjustmentdetail_full', 'report_evidencereporter'),
            'flag' => get_string('settings_adjustmentdetail_flag', 'report_evidencereporter'),
            'off' => get_string('settings_adjustmentdetail_off', 'report_evidencereporter'),
        ]
    ));
    $settings->add(new admin_setting_configtext(
        'report_evidencereporter/integritywords',
        get_string('settings_integritywords', 'report_evidencereporter'),
        get_string('settings_integritywords_desc', 'report_evidencereporter'),
        \report_evidencereporter\local\grade_adjustment::DEFAULT_INTEGRITY_WORDS,
        PARAM_TEXT,
        60
    ));

    // Similarity checks (Turnitin, Ouriginal, Copyleaks...) through Moodle's plagiarism API.
    $settings->add(new admin_setting_heading(
        'report_evidencereporter/similarityheading',
        get_string('settings_similarity', 'report_evidencereporter'),
        get_string('settings_similarity_desc', 'report_evidencereporter')
    ));
    $settings->add(new admin_setting_configselect(
        'report_evidencereporter/similarity',
        get_string('settings_similarityshow', 'report_evidencereporter'),
        get_string('settings_similarityshow_desc', 'report_evidencereporter'),
        \report_evidencereporter\local\similarity::SHOW_SUMMARY,
        [
            'off' => get_string('settings_similarityshow_off', 'report_evidencereporter'),
            'summary' => get_string('settings_similarityshow_summary', 'report_evidencereporter'),
            'packs' => get_string('settings_similarityshow_packs', 'report_evidencereporter'),
        ]
    ));

    // Organisation name and sign-off statement per terminology profile (e.g. "X TAFE" and "X University").
    $settings->add(new admin_setting_heading(
        'report_evidencereporter/profilebrandingheading',
        get_string('settings_profilebranding', 'report_evidencereporter'),
        get_string('settings_profilebranding_desc', 'report_evidencereporter')
    ));
    foreach (\report_evidencereporter\local\terms::get_profiles() as $profile => $profilename) {
        $settings->add(new admin_setting_configtext(
            'report_evidencereporter/orgname_' . $profile,
            get_string('settings_orgname_profile', 'report_evidencereporter', $profilename),
            '',
            ''
        ));
        $settings->add(new admin_setting_configtextarea(
            'report_evidencereporter/signoffstatement_' . $profile,
            get_string('settings_signoffstatement_profile', 'report_evidencereporter', $profilename),
            '',
            '',
            PARAM_TEXT,
            60,
            2
        ));
    }

    // Evidence sources found on this site.
    if ($ADMIN->fulltree) {
        $items = [];
        foreach (\report_evidencereporter\local\sources::all() as $source) {
            $items[] = html_writer::tag('strong', s($source->get_name())) . ': ' . s($source->get_description());
        }
        $settings->add(new admin_setting_heading(
            'report_evidencereporter/sourcesheading',
            get_string('settings_sources', 'report_evidencereporter'),
            get_string('settings_sources_desc', 'report_evidencereporter') . html_writer::alist($items)
        ));
    }
}
