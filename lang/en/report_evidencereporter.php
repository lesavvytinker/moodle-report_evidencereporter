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
 * Language strings for Evidence Reporter.
 *
 * @package    report_evidencereporter
 * @copyright  2026 Daniel Maloney
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['action'] = 'Evidence type';
$string['actioncomplete'] = 'Competency completed';
$string['actionlog'] = 'Logged';
$string['actionoverride'] = 'Rating set';
$string['activity'] = 'Activity';
$string['activitygrade'] = 'Activity grade';
$string['activitytype'] = 'Activity type';
$string['adjustedby'] = 'Adjusted by';
$string['adjustmentcalculation'] = 'Calculation';
$string['adjustmentintegrity'] = 'Academic integrity adjustment: check this evidence is still valid.';
$string['adjustmentoverridden'] = 'The final result was changed by hand in the gradebook.';
$string['applyredaction'] = 'Apply';
$string['assessor'] = 'Assessor';
$string['attemptcount'] = 'Attempts';
$string['attempthistory'] = 'Attempt history';
$string['attemptnumber'] = 'Attempt';
$string['attemptsheading'] = 'Assessment attempts';
$string['attemptsummary'] = 'Attempt {$a}';
$string['attempturl'] = 'Attempt URL (plain text)';
$string['bulkcohort'] = 'Cohort';
$string['bulkcontents'] = 'Contents';
$string['bulkcounts'] = '{$a->included} of {$a->total} learners included';
$string['bulkcovering'] = 'Covering';
$string['bulkcovering_help'] = 'Learning plans: each learner\'s active learning plans together (core and electives), or their most recent plan if none is active. Learners without a learning plan are listed in the index but not included. Choose a framework instead to cover every competency in it.';
$string['bulkcreated'] = 'Created';
$string['bulkdeleted'] = 'Download deleted.';
$string['bulkdownloads'] = 'Bulk evidence downloads';
$string['bulkerror'] = 'Error: {$a}';
$string['bulkexpires'] = 'Deleted on';
$string['bulkfile'] = 'File';
$string['bulkincluded'] = 'Included';
$string['bulkintro'] = 'Download evidence summaries or full evidence packs for every learner on a learning plan template or in a cohort, up to {$a->max} learners at a time. The download is built in the background, using your access: learners whose evidence you can\'t see are skipped and listed in index.csv. You\'ll get a notification when it\'s ready. Downloads are deleted after {$a->days} days.';
$string['bulkmode'] = 'Download';
$string['bulknolearners'] = 'There are no learners here.';
$string['bulknone'] = 'No downloads yet.';
$string['bulknoplan'] = 'Not included: no learning plan';
$string['bulkpacks'] = 'Evidence packs';
$string['bulkpacksdesc'] = 'Evidence packs: one ZIP per learner, with every assessment attempt, submitted files and prior learning';
$string['bulkpending'] = '{$a} download(s) being built. You\'ll get a notification when ready.';
$string['bulkplans'] = 'Learning plans';
$string['bulkqueue'] = 'Build download';
$string['bulkqueued'] = 'Your download is being built. You\'ll get a notification when it\'s ready.';
$string['bulkreadme'] = 'This download holds one evidence summary (PDF) or evidence pack (ZIP) per learner. index.csv lists every learner on the template or in the cohort, and whether they were included. Learners are skipped when they have no evidence in this framework that the person who requested the download is allowed to see.';
$string['bulkready'] = 'Ready to download';
$string['bulkreadybody'] = 'Your bulk evidence download "{$a->filename}" is ready ({$a->counts}).

Download it here: {$a->url}

It will be deleted after {$a->days} days.';
$string['bulkreadysubject'] = 'Your bulk evidence download is ready';
$string['bulkskipped'] = 'Skipped: no evidence you can access in this framework';
$string['bulksource'] = 'Learners from';
$string['bulkstatus'] = 'Status';
$string['bulksummaries'] = 'Evidence summaries';
$string['bulksummariesdesc'] = 'Evidence summaries: one PDF per learner (quicker, smaller)';
$string['bulktemplate'] = 'Learning plan template';
$string['bulktoomany'] = 'This would include {$a->count} learners. The limit is {$a->max} per download: choose a smaller template or cohort.';
$string['checklistitem'] = 'Item';
$string['checklistitems'] = 'Checklist items';
$string['checklistmark'] = 'Mark';
$string['checklistnotmarked'] = 'Not marked';
$string['checklistticked'] = 'Ticked by learner';
$string['chooseframework'] = 'Choose a competency framework for {$a}';
$string['competency'] = 'Competency';
$string['competencydescription'] = 'Competency description';
$string['competencyidnumber'] = 'Competency ID number';
$string['competencyname'] = 'Competency name';
$string['currentrating'] = 'Current rating';
$string['datasource'] = 'Competency evidence';
$string['datasourceattempts'] = 'Assessment attempts';
$string['detailheading'] = 'Evidence by competency';
$string['downloadpack'] = 'Download evidence pack (ZIP)';
$string['downloadpackterm'] = 'Download {$a} (ZIP)';
$string['entityattempt'] = 'Attempt';
$string['entityevidence'] = 'Evidence';
$string['entitylearner'] = 'Learner';
$string['entrysummary'] = 'Entry {$a}';
$string['eventbulkcreated'] = 'Bulk evidence download created';
$string['eventpackdownloaded'] = 'Evidence pack downloaded';
$string['eventsummaryviewed'] = 'Evidence summary viewed';
$string['evidencecount'] = '({$a} evidence items)';
$string['evidencedescription'] = 'Evidence description';
$string['evidenceitems'] = 'Evidence items';
$string['evidencelink'] = 'Evidence link';
$string['evidencepack'] = 'Evidence pack';
$string['evidencereporter:bulkdownload'] = 'Download evidence summaries and packs for many learners at once';
$string['evidencesource'] = 'Source and evidence';
$string['evidenceurl'] = 'Evidence URL (plain text)';
$string['feedback'] = 'Feedback';
$string['feedbackexcluded'] = 'Assessor feedback has been left out of this printout.';
$string['feedbackinattempt'] = 'Feedback is on the attempt review';
$string['feedbacksuperseded'] = 'Superseded by a later attempt';
$string['feedbacksupersededlater'] = 'Superseded by later evidence from this activity';
$string['finalresult'] = 'Final result';
$string['finalresultadjusted'] = 'Adjusted';
$string['finalresultcolumn'] = 'Final result (if adjusted)';
$string['framework'] = 'Competency framework';
$string['frozenratings'] = 'Ratings for a completed learning plan are shown as they were when the plan was completed.';
$string['generated'] = 'Generated';
$string['gradefail'] = 'Fail';
$string['gradepass'] = 'Pass';
$string['includefeedback'] = 'Include feedback';
$string['judgement'] = 'Judgement date';
$string['judgementnotice'] = 'Judgement is the most recent rating or completion recorded for the competency. Current rating is the learner\'s overall rating; ratings made in courses that don\'t update learning plans appear under Judgement only.';
$string['latestattempt'] = 'Latest attempt';
$string['learnerreference'] = 'Learner reference';
$string['learningplan'] = 'Learning plan';
$string['learningplans'] = 'Learning plans';
$string['linknotice'] = 'Links point to records in the learning management system and need an account with access to open them.';
$string['marker'] = 'Marker';
$string['messageprovider:bulkready'] = 'Bulk evidence download is ready';
$string['noattempts'] = 'No attempts';
$string['noevidence'] = 'No evidence recorded';
$string['nogradetopass'] = 'No grade to pass';
$string['nopermissiontoviewevidence'] = 'There is no competency evidence for this learner that you can view.';
$string['note'] = 'Assessor note';
$string['notreleased'] = 'Not released to student';
$string['outcome'] = 'Outcome';
$string['overview'] = 'Overview';
$string['overviewcounts'] = '{$a->total} competencies · {$a->evidence} with evidence · {$a->proficient} proficient';
$string['packassessments'] = 'Assessments';
$string['packattemptrecord'] = 'Attempt record';
$string['packbuilding'] = 'Building the evidence pack can take a little while for learners with many submissions.';
$string['packchecklistrecord'] = 'Checklist record';
$string['packcomment'] = 'Marker comment';
$string['packdetails'] = 'Details';
$string['packentrycontent'] = 'Entry content';
$string['packfailed'] = 'The evidence pack could not be created.';
$string['packfeedbackfiles'] = 'Feedback files';
$string['packmark'] = 'Mark';
$string['packonlinetext'] = 'Online text';
$string['packpriorlearning'] = 'Prior learning';
$string['packratings'] = 'Ratings';
$string['packreadme'] = 'This pack holds a copy of the competency evidence and assessment records for one learner and one competency framework, taken from the learning management system on the date above. "01 Competency evidence summary.pdf" summarises every competency. The Assessments folder holds every assessed attempt (failed attempts included) with its record, submitted files and feedback. The Prior learning folder holds evidence of prior learning. manifest.csv lists every file with its size and SHA-256 checksum, so the pack can be shown to be unchanged. Only items the person who generated the pack was allowed to see are included.';
$string['packresponse'] = 'Response';
$string['packseeassessments'] = 'Each attempt is in the Assessments folder, with its record, submitted files and feedback.';
$string['packsubmission'] = 'Submission';
$string['packsubmittedfiles'] = 'Submitted files';
$string['pendingrelease'] = 'Pending release';
$string['pluginname'] = 'Evidence Reporter';
$string['printsummary'] = 'Print or save as PDF';
$string['printsummarylink'] = 'Print evidence summary';
$string['privacy:metadata:bulkfiles'] = 'Bulk evidence downloads you request are stored in your own file area for a few days, then deleted. They contain other learners\' competency evidence.';
$string['proficient'] = 'Proficient';
$string['profile_auvet'] = 'Australian vocational education and training (VET)';
$string['profile_generic'] = 'Generic';
$string['profile_highered'] = 'Higher education';
$string['profile_ukappr'] = 'UK apprenticeships';
$string['profile_zaocc'] = 'South African occupational qualifications';
$string['rating'] = 'Rating given';
$string['redacted'] = '[redacted]';
$string['redactednamepart'] = 'learner name';
$string['redactedstaffpart'] = 'staff names';
$string['redactedusernamepart'] = 'learner username, ID number and email';
$string['redactionfilesnote'] = 'Files submitted by the learner or uploaded by assessors are included as they are and are not redacted. Check them before sharing this download.';
$string['redactionnote'] = 'Redacted: {$a}. The learner reference identifies the learner without naming them.';
$string['redactlearnername'] = 'Learner name';
$string['redactoptions'] = 'Redact';
$string['redactoptions_help'] = 'Leave out names and usernames in what is generated: the summary, the record PDFs, file names, README and index. Learner name removes the learner\'s name, also where it appears in feedback, comments and quiz responses. Learner username removes the username, ID number and email address. Staff names removes assessors, markers, raters and comment authors. Every document still carries the learner reference, which stays the same for a learner on this site. Files the learner submitted or assessors uploaded are not changed.';
$string['redactstaff'] = 'Staff names';
$string['redactusername'] = 'Learner username';
$string['released'] = 'Released';
$string['releasestatus'] = 'Feedback release status';
$string['restrictednotice'] = 'This summary only includes evidence you have access to (for example, from courses you teach). Competencies may show "No evidence recorded" when evidence exists elsewhere.';
$string['reviewedby'] = 'Reviewed by';
$string['reviewheading'] = 'Review';
$string['scopeactiveplans'] = 'Active learning plans';
$string['scopechoice'] = 'Covering';
$string['scopecompleted'] = 'completed {$a}';
$string['scopedue'] = 'due {$a}';
$string['scopeshow'] = 'Show';
$string['scopetemplate'] = 'from template: {$a}';
$string['settings_adjustmentdetail'] = 'Show adjustments';
$string['settings_adjustmentdetail_desc'] = 'Full shows each penalty with its grade and feedback, the calculation, and flags academic integrity penalties. "Adjusted" only shows the final result marked as adjusted, without reasons, e.g. if documents go to people who shouldn\'t see misconduct findings.';
$string['settings_adjustmentdetail_flag'] = 'Final result marked "Adjusted", no reasons';
$string['settings_adjustmentdetail_full'] = 'Final result with reasons';
$string['settings_adjustmentdetail_off'] = 'Don\'t show';
$string['settings_adjustments'] = 'Gradebook adjustments';
$string['settings_adjustments_desc'] = 'When an assessment sits in its own gradebook category with other items, such as late or academic misconduct penalties, the category total is its final result. If that differs from the assessment grade, or was changed by hand, documents show the final result and why. Categories holding more than one activity are ignored.';
$string['settings_branding'] = 'Branding';
$string['settings_branding_desc'] = 'How the evidence summary, evidence pack PDFs and README look.';
$string['settings_documentfooter'] = 'Footer text';
$string['settings_documentfooter_desc'] = 'Shown at the end of the summary, every PDF and the README, e.g. a confidentiality statement or contact details.';
$string['settings_documentheader'] = 'Header text';
$string['settings_documentheader_desc'] = 'Shown under the organisation name on the summary, every PDF and the README, e.g. a registration number.';
$string['settings_filenamepattern'] = 'Evidence pack file name';
$string['settings_filenamepattern_desc'] = 'Placeholders: {document} (e.g. Evidence pack), {learner}, {covering} (the plan or framework), {date}, {orgname}. {learner} is required; without it the default is used.';
$string['settings_frameworkprofiles'] = 'Profile per framework';
$string['settings_frameworkprofiles_default'] = 'Site profile ({$a})';
$string['settings_frameworkprofiles_desc'] = 'For providers offering more than one kind of qualification, e.g. VET and degrees. Documents use the profile of the frameworks they cover. A learning plan with competencies from frameworks with different profiles uses the site profile.';
$string['settings_frameworkprofiles_none'] = 'There are no competency frameworks yet.';
$string['settings_integritywords'] = 'Academic integrity words';
$string['settings_integritywords_desc'] = 'Comma-separated. A penalty whose name contains one of these is flagged as an academic integrity matter, since it can mean the evidence itself is in question. Penalties without them (e.g. late penalties) aren\'t flagged.';
$string['settings_logo'] = 'Logo';
$string['settings_logo_desc'] = 'Shown at the top of the summary and every PDF. PNG or JPEG.';
$string['settings_orgname'] = 'Organisation name';
$string['settings_orgname_desc'] = 'Shown at the top of every document. Leave empty to use the site name.';
$string['settings_orgname_profile'] = 'Organisation name: {$a}';
$string['settings_profile'] = 'Terminology profile';
$string['settings_profile_desc'] = 'The words documents use for learners, competencies, assessors and so on. To change a single word, use Moodle\'s language customisation (strings starting with term_).';
$string['settings_profilebranding'] = 'Branding per profile';
$string['settings_profilebranding_desc'] = 'For dual-sector providers: an organisation name and sign-off statement for documents in each profile, e.g. "Example TAFE" for VET and "Example University" for higher education. Leave empty to use the ones above.';
$string['settings_sections'] = 'Summary sections';
$string['settings_showattempts'] = 'Show assessment attempts';
$string['settings_showattempts_desc'] = 'Every attempt at linked activities, with grade and marker, under each competency.';
$string['settings_showevidencedetail'] = 'Show evidence detail';
$string['settings_showevidencedetail_desc'] = 'The detail for each competency below the summary table: every piece of evidence with source, rating, assessor and feedback.';
$string['settings_showsignoff'] = 'Show sign-off block';
$string['settings_showsignoff_desc'] = 'Lines for a reviewer\'s name, signature and date at the end.';
$string['settings_signoffheading'] = 'Sign-off heading';
$string['settings_signoffheading_desc'] = 'Leave empty for "Review".';
$string['settings_signoffstatement'] = 'Sign-off statement';
$string['settings_signoffstatement_desc'] = 'A declaration printed above the sign-off lines, e.g. "I have reviewed this evidence and confirm it is valid, sufficient, authentic and current."';
$string['settings_signoffstatement_profile'] = 'Sign-off statement: {$a}';
$string['settings_sources'] = 'Evidence sources';
$string['settings_sources_desc'] = 'Activity types Evidence Reporter knows how to link to and report on. Other activities still appear, linked to the activity page with their gradebook feedback. Other plugins can add sources.';
$string['settings_terms'] = 'Terminology';
$string['signature'] = 'Signature';
$string['sourcedesc_assign'] = 'links to the grader on the learner; every graded attempt with feedback; submitted files, online text, feedback files and annotated PDF in the pack.';
$string['sourcedesc_checklist'] = 'links to the learner\'s checklist; each item\'s mark and comments as feedback; a checklist record in the pack.';
$string['sourcedesc_data'] = 'every rated entry is an assessment; links to the learner\'s latest entry; ratings as the grade and comments as feedback.';
$string['sourcedesc_forum'] = 'links to the learner\'s posts in the course.';
$string['sourcedesc_quiz'] = 'links to the review of the latest attempt; every finished attempt under the quiz\'s review rules; a record of every question in the pack.';
$string['summaryheading'] = 'Summary';
$string['summarytitle'] = 'Competency evidence summary';
$string['taskbulkdownload'] = 'Build bulk evidence download';
$string['taskcleanupbulk'] = 'Delete expired bulk evidence downloads';
$string['term_assessor'] = 'Assessor';
$string['term_assessor_highered'] = 'Marker';
$string['term_competencies'] = 'competencies';
$string['term_competencies_highered'] = 'learning outcomes';
$string['term_competencies_ukappr'] = 'KSBs';
$string['term_competencies_zaocc'] = 'outcomes';
$string['term_competency'] = 'Competency';
$string['term_competency_highered'] = 'Learning outcome';
$string['term_competency_ukappr'] = 'KSB';
$string['term_competency_zaocc'] = 'Outcome';
$string['term_evidencepack'] = 'Evidence pack';
$string['term_evidencepack_zaocc'] = 'Portfolio of evidence';
$string['term_framework'] = 'Competency framework';
$string['term_framework_highered'] = 'Outcomes framework';
$string['term_framework_ukappr'] = 'Apprenticeship standard';
$string['term_framework_zaocc'] = 'Qualification';
$string['term_learner'] = 'Learner';
$string['term_learner_highered'] = 'Student';
$string['term_learner_ukappr'] = 'Apprentice';
$string['term_overviewcounts'] = '{$a->total} competencies · {$a->evidence} with evidence · {$a->proficient} proficient';
$string['term_overviewcounts_highered'] = '{$a->total} learning outcomes · {$a->evidence} with evidence · {$a->proficient} achieved';
$string['term_overviewcounts_ukappr'] = '{$a->total} KSBs · {$a->evidence} with evidence · {$a->proficient} met';
$string['term_overviewcounts_zaocc'] = '{$a->total} outcomes · {$a->evidence} with evidence · {$a->proficient} achieved';
$string['term_proficient'] = 'Proficient';
$string['term_proficient_highered'] = 'Achieved';
$string['term_proficient_ukappr'] = 'Met';
$string['term_proficient_zaocc'] = 'Achieved';
$string['term_summarytitle'] = 'Competency evidence summary';
$string['term_summarytitle_highered'] = 'Learning outcome evidence summary';
$string['term_summarytitle_ukappr'] = 'Apprenticeship evidence summary';
$string['term_summarytitle_zaocc'] = 'Portfolio of evidence summary';
$string['timecreated'] = 'Date logged';
$string['timegraded'] = 'Date graded';
$string['timesubmitted'] = 'Date submitted';
$string['viewattempt'] = 'View attempt';
$string['viewattemptfeedback'] = 'View answers and feedback';
$string['viewevidence'] = 'View evidence';
