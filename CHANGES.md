# Changelog

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
