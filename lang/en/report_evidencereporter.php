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
$string['bulkpacks'] = 'Evidence packs';
$string['bulkpacksdesc'] = 'Evidence packs: one ZIP per learner, with every assessment attempt, submitted files and prior learning';
$string['bulkpending'] = '{$a} download(s) being built. You\'ll get a notification when ready.';
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
$string['framework'] = 'Competency framework';
$string['generated'] = 'Generated';
$string['gradefail'] = 'Fail';
$string['gradepass'] = 'Pass';
$string['includefeedback'] = 'Include feedback';
$string['judgement'] = 'Judgement date';
$string['judgementnotice'] = 'Judgement is the most recent rating or completion recorded for the competency. Current rating is the learner\'s overall rating; ratings made in courses that don\'t update learning plans appear under Judgement only.';
$string['latestattempt'] = 'Latest attempt';
$string['learnerreference'] = 'Learner reference';
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
$string['signature'] = 'Signature';
$string['summaryheading'] = 'Summary';
$string['summarytitle'] = 'Competency evidence summary';
$string['taskbulkdownload'] = 'Build bulk evidence download';
$string['taskcleanupbulk'] = 'Delete expired bulk evidence downloads';
$string['timecreated'] = 'Date logged';
$string['timegraded'] = 'Date graded';
$string['timesubmitted'] = 'Date submitted';
$string['viewattempt'] = 'View attempt';
$string['viewattemptfeedback'] = 'View answers and feedback';
$string['viewevidence'] = 'View evidence';
