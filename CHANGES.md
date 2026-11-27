# Changelog

## 0.30.0 (2026-12-10)
- **Yes and No columns** for RA and for LLN, nothing ticked to begin with. Only what's ticked is saved, so saving the
  page after correcting one learner no longer records everyone else as No. A learner with one answer shows *Partly
  recorded* and still counts as not recorded. Tick-all boxes on each Yes and No column.
- RA and LLN answers can be blank (not answered yet) in the database.

## 0.29.0 (2026-12-09)
- **RA and LLN gaps flagged:** on the evidence summary and evidence pack, staff see an alert listing the assessments
  still to record, and each competency is flagged *RA/LLN not recorded*, rolled up from criteria to units and
  qualifications; *Evidence complete, RA/LLN missing* when it's rated proficient or everything under it has evidence.
  Learners don't see these on their own summary or pack.
- **Marking page:** *Ready to sign off* waits for RA and LLN (*Ready once RA/LLN recorded* until then). New
  **RA/LLN** column, opening the learner's own RA and LLN page, and **Learning plan** column, linking to the learner's
  plans that cover the units. *RA/LLN to record* counts on each course and on *All my courses* (and in the Marking
  nudge block 0.2.0).
- **RA and LLN for one learner:** every assessment in the course on one page, with the same tick boxes, tick-all boxes
  and details.
- **When recorded:** the date and time each RA and LLN record was saved, and who saved it, on the RA and LLN pages and
  in the summary and evidence pack.
- **Date and time formats:** new settings *Date format* (site's language, DD/MM/YYYY, MM/DD/YYYY, YYYY-MM-DD,
  27 November 2026, 27 Nov 2026, November 27, 2026) and *Time format* (site's language, 24-hour, 12-hour), used for
  every date Evidence Reporter shows: pages, summaries, evidence packs, sample registers and its report sources.

## 0.28.0 (2026-12-08)
- **RA and LLN before release.** A new **RA and LLN** page for each assessment: for every learner, tick whether a
  reasonable adjustment was made and whether they had language, literacy and numeracy needs or support, with details
  for each "yes". Tick boxes in the column headings tick or untick a whole column. The page explains both terms and
  what to do, and that grades are held until it's done.
- **Grades held until recorded:** for grade items that count toward the final grade, the item stays hidden in the
  gradebook (hiding assignment feedback too) until every learner is recorded, then its hidden setting is put back.
  Learners who join later are held one by one. Assessments already graded when holds begin are exempt. New setting
  *Hold grades until RA and LLN are recorded* (on by default) and an hourly check.
- **Attempts share records:** activities in a Highest grade category, or a category whose activities are all linked to
  the same competencies, share one record per learner; a later attempt keeps its own only if the answers differ.
- The marking page shows RA/LLN still to record and **Grades held** under each assessment.
- LLN appears by assessment on the evidence summary and evidence pack, on each competency's evidence, and as a new
  column in validation sample registers. A recorded RA shows as "Adjustment made" when there's no detailed adjustment.
- Privacy provider covers the new records.

## 0.27.0 (2026-12-07)
- **Attempt history for databases, forums, workshops and checklists.** These activities don't have attempts of their
  own, so when an assessor marks one Not satisfactory, Evidence Reporter keeps a snapshot: the result, who marked it,
  when, and the feedback given (gradebook feedback, checklist comments, database comments, and the assessor's replies to
  the learner's forum posts). The snapshot keeps collecting feedback until the learner does more work or is marked
  again, then it's closed, so later edits and overwritten comments don't change it. Satisfactory results aren't
  snapshotted; the live result is shown as the latest attempt. Assessors don't do anything differently.
- Existing Not satisfactory marking is filled in from the gradebook's history after upgrading.
- Shown under each competency on the printable summary and in the evidence pack ("Attempt 1", "Attempt 2",
  "Attempt 3 (latest)"), and on the marking page as "Attempt n of 3" with a **No attempts left** flag when the last one
  is Not satisfactory.
- New setting **Attempts allowed** (default 3) for these activities.
- Privacy: snapshots are exported for the learner and the assessor, and deleted with the learner or the activity.

## 0.26.0 (2026-12-06)
- **Marking page** for assessors (course Reports > Marking, and All my courses): learners by assessment for a unit or
  all units, most urgent first, with each cell's status (to mark with days waiting, feedback needed, not satisfactory,
  not released, satisfactory), grade and attempt, a link to mark it, and its reasonable adjustments. Amber after 7 days,
  red after 14 (new settings). Units ready to sign off are flagged, with links to the rating page, evidence summary and
  learning plan.
- New capability `report/evidencereporter:marking`.

## 0.25.3 (2026-12-05)
- New setting **Record reasonable adjustments for**: the terminology profiles whose frameworks record reasonable
  adjustments (Australian VET and UK apprenticeships by default). An activity follows the frameworks it's linked to, so
  higher education courses get no Reasonable adjustments page, menu entry or "N/A" lines. Untick all to turn the
  feature off.

## 0.25.2 (2026-12-05)
- Learning plans changed in the same second are listed in a fixed order (oldest first), so units always appear in the
  same order.

## 0.25.1 (2026-12-05)
- Reasonable adjustments are shown per assessment: the summary and the evidence pack list every assessment linked to the
  learner's competencies, with "Reasonable adjustment: N/A" where none was made; each competency's evidence ends with
  its assessments' adjustments; sample registers show "N/A" too. "N/A" appears only where the viewer may see
  adjustments.

## 0.25.0 (2026-12-05)
- **Reasonable adjustments:** record what was adjusted for a learner in an assessment, why, and who approved it, from
  the activity's **More > Reasonable adjustments** page or the learner's evidence summary. Quiz user overrides,
  assignment user overrides and assignment extensions are offered to confirm with a reason.
- Adjustments appear on the evidence summary, in the evidence pack's summary PDF, and in a new column of validation
  sample registers.
- New capabilities `report/evidencereporter:viewadjustments` and `report/evidencereporter:manageadjustments`; settings
  for whether learners see their own, and whether reasons go into downloads (off by default). New events, and the
  privacy provider covers them.

## 0.24.0 (2026-12-04)
- Quiz question evidence now also reads mappings from **Question Bank Competency Mapping** (`qbank_comp_ext`, on the
  Moodle Marketplace), as well as Question competencies (`qbank_competencies`). Either or both can be installed; a
  question mapped in both shows once. `qbank_comp_ext` maps each version of a question separately: a mapping on any
  version counts, so editing a question doesn't lose it. It works on Moodle 4.5, where Question competencies needs 5.0.
- Assessor guides list a question's competencies from either plugin.
- Questions pass with full marks, or with Question competencies' pass setting when that plugin is installed.

## 0.23.1 (2026-12-03)
- Assessment tool details start from example text for quizzes with nothing saved yet. Each text box begins with "EXAMPLE:" (assessor instructions, conditions, resources as open book with the learner guide, reasonable adjustment), and the assessor guide's review notes list any example text left in. Admins can change the examples under Language customisation (`toolexample_*` strings).
- **Print assessor guide** button in the quiz's Assessment tool details section, and **Assessor guide** in the quiz's More menu. Both open the PDF in a new tab ready to print or save (add `download=1` to save it straight away).

## 0.23.0 (2026-12-02)
- **Quiz questions as supporting evidence** (with the new Question competencies plugin, `qbank_competencies`): questions mapped to competencies in the question bank appear under each competency, grouped by quiz attempt with the whole quiz's result (Satisfactory / Not satisfactory) and grade.
- Each question shows its number, mark and result, with links to the learner's answer to that question and, for markers, to comment on it or override the mark.
- Flags: a question passed in a quiz that wasn't satisfactory, a question not passed in a satisfactory quiz, and a quiz that isn't linked to the competency.
- Question results never rate a competency. They follow the same access and release rules as attempts, and appear in the evidence pack's summary PDF.
- **Assessor guides** for quizzes: a PDF of the blank assessment tool (tool details, units, blank learner details, instructions and conditions, reasonable adjustment, every question with its model answer, Satisfactory / Not satisfactory boxes, specific feedback and mapped competencies, mapping matrix, outcome summary, sign-off, and review notes listing what's missing). New capability `report/evidencereporter:assessorguide` (teachers, editing teachers, managers).
- **Assessment tool details** in activity settings: tool version, review date, reviewer, conditions, resources, assessor instructions and reasonable adjustment guidance.
- Evidence packs include each quiz's blank assessor guide (setting *Include blank assessor guides in evidence packs*, on by default).
- New terms for each profile: Satisfactory and Not satisfactory (Pass / Fail for higher education and UK apprenticeships, Competent / Not yet competent for South African occupational).

## 0.22.0 (2026-12-01)
- **Validation sampling** (*Reports → Validation sampling*): draw a random sample of judgements (a learner's result in a unit) from a course or category, for a unit, a framework or all, filtered by date judged, result and marks (high, middle, borderline, low or a custom range), and spread evenly across assessors, results, mark bands or courses, in total or per group.
- Each sample is recorded with its filters, the eligible population, a draw code and a population check, so it's provably random and reproducible.
- **Swaps:** swap a judgement out with a reason; it stays on the record and a replacement is drawn from the same group.
- **Evidence for a sample:** summaries or packs, one per sampled judgement covering its unit, plus a Sample register spreadsheet with columns for the validator's findings and a Sampling record. Redaction applies throughout.
- New events: validation sample drawn, judgement swapped out. The privacy provider covers samples (learners are removed; staff are anonymised).

## 0.21.0 (2026-11-30)
- **Workshop:** under each linked competency, the learner's submission (with attachments and similarity results), the grade for the submission (and any override, with feedback), each assessment received with the reviewer's grade and feedback, and the grade for the assessments they gave. Peer reviewers are shown as "Peer reviewer"; staff are named unless redacted. Learners see grades once the workshop is closed. The pack gets a Workshop record PDF and the attachments.
- **Attendance** (when the Attendance plugin is installed): every session recorded for the learner, with date, length, status, points, remarks and who took it, and a summary such as "6 sessions recorded, 83% of points (Present 5, Absent 1)". The pack gets an Attendance record PDF.
- The forum contributions block is now a general "activity records" block that any evidence source can add to, and sources for optional plugins add their own "has the learner done anything here" test only when installed.

## 0.20.0 (2026-11-29)
- **H5P activities:** each tracked attempt appears in the attempt history with its grade (or raw score, e.g. "4 / 5", when the activity isn't graded) and a link to the attempt report. The evidence pack has the score, completion, success, time spent and the result for each question.
- **SCORM packages:** each attempt the package wrote to appears with its grade, worked out the way the activity grades attempts. Staff link to the attempt report; learners, who can't open SCORM reports, link to the activity. The evidence pack has the status, score, time spent and last access for each section (SCO).
- Both count towards "Units with evidence" and are available in the Attempts custom report source.

## 0.19.0 (2026-11-28)
- **Assignment add-ons in evidence packs:** packs now include files from every enabled assignment submission and feedback plugin, not just file uploads, annotated PDFs and feedback files. Poodll audio and video recordings (and Poodll audio or video feedback) are included, as is any other add-on that stores its files in Moodle. Tested with Poodll Assignment Submission 3.1.14 and Poodll Assignment Feedback.
- For submission plugins that keep their content outside Moodle (e.g. Cloud Poodll), the attempt record includes what the plugin shows for the submission, such as a link or transcript, under "Other submission content".

## 0.18.0 (2026-11-27)
- **Forums:** for any forum linked to competencies, the summary shows the learner's contributions under each competency: the whole-forum grade first (with its rubric or marking guide), then the ratings result, then every post they wrote. Each post shows whether it started a discussion or replied, the date, its rating, attachments, similarity results (staff only) and a link to the post. Replies show the post they answered for context: staff are named, but another learner's post shows only "another learner", the subject and a short excerpt. The evidence pack gets a Forum posts PDF and the attachments in the forum's folder.
- Forums graded as a whole now show that grade, rather than the ratings grade, wherever an activity grade appears.
- Forum posts count towards "Units with evidence".

## 0.17.0 (2026-11-26)
- **Bulk downloads by course or category:** "Learners from" can now be a course (everyone with an active enrolment who can be rated on competencies) or a course category, with its subcategories by default, e.g. every learner across a qualification's unit courses.

## 0.16.0 (2026-11-25)
- **Learning plans are optional.** For a learner without a plan, the summary and evidence pack now default to **Units with evidence**: every unit the learner has evidence in (or an assessed attempt linked to), with everything below it, grouped by framework. Units they haven't started, such as electives they didn't take, are left out. It's also in the Covering menu for learners who do have plans.
- **Bulk downloads** default to learning plans, or units with evidence for learners without one, so learners without plans are no longer skipped. Only learners with neither are listed as not included.
- New terminology key `units` (plural).

## 0.15.0 (2026-11-24)
- **One unit:** "Covering" on the summary, the evidence pack and bulk downloads can now be a single unit: that competency and everything below it. Useful when an auditor samples one unit from a clustered course, where shared tasks also cover other units. Units are each competency in the learner's plans, then the top level of each framework (or, when a framework's only top-level competency is the qualification, its children).
- New terminology key `unit` (Unit / Learning outcome / KSB / Module / Competency by profile).

## 0.14.0 (2026-11-23)
- **Similarity checks:** assignment evidence shows the result from the site's similarity plugin (Turnitin, Ouriginal, Copyleaks or any plugin using Moodle's plagiarism API), for each submitted file and the online text. It uses the plugin's own badge and link, so no vendor-specific code. Staff only.
- New setting: **Show similarity results**: summary and report only (default), also in evidence packs as text, or off.
- New report column: **Similarity check**.
- Evidence sources can supply similarity results for other activity types (`get_similarity_html()`).

## 0.13.0 (2026-11-22)
- **Gradebook adjustments:** when an assessment is the only activity in its own gradebook category (e.g. alongside late or academic misconduct penalty items), and the category total differs from the assessment grade or was overridden, the summary, evidence pack and report show the final result, marked "Adjusted", with each penalty, its feedback, and the calculation in plain names. Penalties named with academic integrity words are flagged for the reviewer. Hidden penalties stay hidden from learners, and feedback follows redaction.
- New settings: **Show adjustments** (full, "Adjusted" only, or off) and **Academic integrity words**.
- New report column: **Final result (if adjusted)**.

## 0.12.0 (2026-11-21)
- **Dual-sector providers:** each competency framework can have its own terminology profile (e.g. VET for certificates, higher education for degrees), with the site profile as the default. The summary, evidence pack and bulk downloads use the profile of the frameworks they cover; a learning plan mixing frameworks with different profiles uses the site profile. In bulk downloads, each learner's pack follows its own frameworks.
- **Branding per profile:** an organisation name and sign-off statement for each profile, falling back to the site-wide ones.

## 0.11.0 (2026-11-20)
- **Settings page** (*Site administration → Plugins → Reports → Evidence Reporter*).
- **Terminology profiles**: Australian VET (default), Higher education, UK apprenticeships, South African occupational and Generic. The summary, evidence pack PDFs, README, file names and bulk index use the profile's words for learner, competency, framework, assessor, "proficient" and the document titles. Any single word can be changed through language customisation.
- **Document branding**: organisation name, logo, header and footer text on the summary, every PDF and the README; your own sign-off heading and declaration; show or hide the evidence detail, attempts and sign-off sections; a file name pattern for evidence packs.
- **Evidence sources**: everything Evidence Reporter knows about each activity type (assignment, quiz, database, forum, checklist) now sits in one module per type. Other plugins can add sources for their own activities, or replace a built-in one, through the new `collect_sources` hook. The settings page lists the sources found. Behaviour of the built-in sources is unchanged.

## 0.10.0 (2026-11-19)
- **Summaries and evidence packs per learning plan.** A new **Covering** choice on the summary page offers *Active learning plans* (all the learner's active plans together, e.g. core plus electives), any single plan (active or completed), or a competency framework.
- **Learning plans are now the default.** The summary and pack cover the learner's active plans, or their most recent plan if none is active. With no plans, they fall back to the framework, as before.
- A plan covers each of its competencies **and everything beneath them** (elements, performance criteria, evidence items), where the evidence usually sits. When several plans are covered, each gets its own heading.
- **Completed plans** show the ratings frozen when the plan was completed, with a note saying so.
- The summary, pack PDF and README name the plans covered, with their status, template and completion or due date. Pack file names use the plan name.
- **Bulk downloads** can cover each learner's learning plans (now the default) instead of a framework. Learners without a plan are listed in the index as not included.
- The report's *Print evidence summary* link opens the learner's plans, falling back to that competency's framework for learners without a plan.
- Events record which plan, plans or framework a summary or pack covered.

## 0.9.2 (2026-11-17)
- When Moodle logs completion evidence more than once for the same database or checklist activity (for example, Complete and then Complete (pass) once the grade arrives), only the latest row shows the entry's comments or the checklist's marks. Earlier rows say "Superseded by later evidence from this activity" instead of repeating them. Every rated database entry is still listed in the assessment attempts.

## 0.9.1 (2026-11-17)
- Every link in the report, the evidence summary and the attempts report now opens in a new tab: View evidence, View attempt, activity names, Print evidence summary, quiz feedback, and links that assessors put in their feedback. Each tells screen reader users it opens in a new window.

## 0.9.0 (2026-11-17)
- **Checklist activities** (the mod_checklist add-on): when a checklist is linked to a competency, the evidence link opens the learner's checklist report (their marks and comments on one page), and the feedback shows every item with its mark (assessor Yes / No, or the learner's tick for learner-marked checklists) and the comments on it, with who wrote them. Hidden items are left out.
- The evidence pack adds a **Checklist record** PDF for each linked checklist: every item, its mark, the assessor, the date and the comments. Without feedback, the comments are left out and the marks stay.
- Redaction covers checklist comments and assessor names.
- Sites without the Checklist plugin are unaffected.

## 0.8.0 (2026-11-16)
- **Username on every official document**: the printable summary, every PDF in the evidence pack (summary, attempt and entry records, prior learning details), the pack README and the bulk index now show the learner's name, username, ID number (if set) and a **learner reference** (e.g. L-3F9A12C0). The reference stays the same for a learner on this site but doesn't reveal who they are, so redacted documents can still be matched up.
- **Redaction** on the summary page, the evidence pack and bulk downloads, with three tick-boxes:
  - *Learner name*: removed from every document and file name, and from feedback, comments, notes, evidence descriptions, quiz responses, online text and prior learning details. The learner's own comments are shown as [redacted] too.
  - *Learner username*: removes the username, ID number and email address in the same places.
  - *Staff names*: assessors, markers, raters, comment authors and the person who generated the document.
  Redaction happens on the server, so the page, the pack and the download only ever hold the redacted text. The summary page's choices carry through to its pack link. File names use the learner reference when the name or username is redacted. Each redacted document says what was redacted. Files submitted by learners or uploaded by assessors are included as they are, and the README says so.
- The summary viewed, pack downloaded and bulk download created events record which redaction options were used.

## 0.7.0 (2026-11-15)
- **Database activities**: every *rated* entry is now an assessment, whatever the rating ("Not satisfactory" included), in the Assessment attempts source, the Attempt history column, the printable summary and the evidence pack. Each shows the entry's grade (its ratings combined the way the activity's aggregate type sets), Pass/Fail against the grade to pass, the rater(s), and **all the entry's comments as feedback**, oldest first, each with who wrote it and when. Links open that entry in single view. Unrated entries are left out.
- Database evidence rows in the main report link to the learner's latest **rated** entry and show its comments in the Feedback column.
- Evidence packs hold a record PDF per rated entry: the entry's content, every rating and every comment, plus files attached to the entry.

## 0.6.0 (2026-11-14)
- **Bulk evidence downloads** (Site administration → Reports → Bulk evidence downloads): evidence summaries (one PDF per learner) or full evidence packs (one ZIP per learner) for every learner on a learning plan template or in a cohort, up to 200 at a time. Built in the background as the person who asked, so their access and the release rules apply; learners they can't see are skipped and listed in index.csv. A notification links to the download when it's ready. Downloads are kept in the requester's own file area for 7 days, then deleted by a scheduled task.
- New capability report/evidencereporter:bulkdownload (Manager by default).
- Privacy provider now covers stored bulk downloads (export and delete).
- New event: Bulk evidence download created.

## 0.5.1 (2026-11-13)
- Database evidence links state the single view explicitly (mode=single) for the learner's latest entry; new test covers it.

## 0.5.0 (2026-11-13)
- **Evidence pack**: "Download evidence pack (ZIP)" on the evidence summary. One ZIP per learner and framework containing:
  - the evidence summary as a PDF;
  - every assessed assignment and quiz attempt (failed ones included), each with an attempt record PDF; for assignments, the submitted files, online text, feedback files and annotated PDFs; for quizzes, every question with the learner's answer, mark, right answer and all feedback, including marker comments;
  - evidence of prior learning details and files;
  - README.txt and manifest.csv with the size and SHA-256 checksum of every file.
  Follows the Include feedback checkbox, and the same access and release rules as the report. Downloads are logged (Evidence pack downloaded event).
- Learners with only assessment attempts in a framework (for example, failed attempts never logged as competency evidence) now get a summary and pack for it.

## 0.4.1 (2026-11-12)
- Quiz attempts: the Feedback column is now a "View answers and feedback" link to the attempt review with every question on one page (answers with specific, general and overall feedback and marker comments), in place of the quiz's generic overall feedback. Shown when the quiz's review options let the learner see feedback; staff with access to unreleased grades always see it.
- Quiz evidence links open the attempt review with all questions on one page.

## 0.4.0 (2026-11-12)
- New report source **Assessment attempts**: one row per assessed assignment attempt and finished quiz attempt, failed ones included, for each competency linked to the activity. Columns: attempt number, dates submitted and graded, grade with Pass/Fail, marker, that attempt's own feedback, release status, latest attempt, link to the attempt. Filters: outcome (Pass / Fail / No grade to pass), latest attempt only, activity type, date graded.
- New **Attempt history** column (default) on the evidence report: every attempt at the activity in one cell, with grade, marker, date and feedback.
- The printable evidence summary lists **Assessment attempts** under each competency, including competencies where the learner only has failed attempts and so no competency evidence. New Attempts count in the summary table. Include feedback applies to attempt feedback too.
- Release rules for attempts: assignment attempts follow marking workflow and gradebook hiding (earlier attempts count as released once reopened); quiz marks and overall feedback follow the quiz's own review options for learners.

## 0.3.2 (2026-11-11)
- New "Activity grade" column (default) and grade line on the evidence summary: the learner's gradebook grade for the activity, in the gradebook's display format, with the maximum and Pass/Fail when the activity has a grade to pass. Same release and superseded rules as feedback.
- Fix: the unreleased-feedback permission check is now cached per user, so scheduled reports sent to several people each get the right result.

## 0.3.1 (2026-11-11)
- "Include feedback" checkbox on the evidence summary: untick to leave assessor feedback out of the screen view and the printout. The printout then states that feedback was left out.

## 0.3.0 (2026-11-11)
- Printable competency evidence summary (print.php) for one learner and one framework: overview, every competency in framework order with current rating and judgement (gaps marked "No evidence recorded"), evidence detail with full feedback, printed URLs and a review sign-off block. Print or save as PDF from the browser.
- Same row access as the report: course teachers see only their courses' evidence (with a notice), learners see their own, release rules apply.
- Opens from the new "Print evidence summary" report column and from the learner's profile (Reports section).
- New event: Evidence summary viewed.

## 0.2.1 (2026-11-10)
- New "Current rating" column: the learner's current rating for the competency, shown on every evidence row (default column).
- "Rating" column renamed "Rating given" (the rating set by that particular evidence event).

## 0.2.0 (2026-11-10)
- Repository set up for Moodle Marketplace: GPL licence, GitHub Actions CI (Moodle 4.5, 5.1, 5.2 on PostgreSQL and MariaDB), Behat tests and a Behat/PHPUnit data generator.
- Test-data seed script moved to the separate evidencereporter-devtools repository.

## 0.1.4
- Assessor column falls back to whoever graded the activity when the evidence has no user recorded (activity-completion evidence).

## 0.1.3
- "Superseded" feedback is worked out per competency, so one activity linked to several competencies shows feedback on every row.
- Evidence links go to the learner's submission, attempt, posts or entry, even when core stored the activity page as the evidence URL.

## 0.1.2
- Seed script purge only touches its own courses and accounts.

## 0.1.1
- cli/show_sql.php prints the SQL behind any custom report, as a chosen user.

## 0.1.0
- First build: Competency evidence report source with evidence links, feedback release rules and row-level permissions.
