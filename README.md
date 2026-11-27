# Evidence Reporter (report_evidencereporter)

A Report Builder data source that lists **every piece of competency evidence** in Moodle, one row per item, with the learner, competency, course, activity, a link to the evidence, the rating, the assessor, the date and the gradebook feedback.

Supports Moodle 4.5 (LTS), 5.1 and 5.2. Tested in CI on PostgreSQL and MariaDB. Licensed under the GNU GPL v3 or later.

## Install

1. Copy the `evidencereporter` folder into `report/` (on Moodle 5.1+ that is `public/report/`).
2. Visit *Site administration → Notifications* to finish the install.
3. Competencies must be enabled (*Site administration → Competencies → Competencies settings*), and so must custom reports (*Site administration → General → Advanced features*).

## Use

*Site administration → Reports → Report builder → Custom reports → New report*, then choose the source **Competency evidence**. Leave "Include default setup" ticked to get the standard layout.

Default columns: Learner, Competency, Course, Date logged, Evidence type, Activity, Evidence link, Activity grade, Rating given, Current rating, Assessor, Feedback, Feedback release status.

**Rating given vs current rating.** An activity completion and the competency being marked complete are separate events, so they appear as separate rows. *Rating given* is the rating set by that row's event (often blank for activity evidence). *Current rating* is where the learner stands on the competency now, and shows on every row.

**One row per piece of evidence.** To hide the outcome rows (competency completed by a rule or by course completion), add the condition *Evidence type* → *Is not equal to* → *Competency completed*.

Also available: evidence description, assessor note, activity type, plain-text evidence URL (for exports), competency ID number/description/framework, and all standard user and course columns.

Default filters: learner, competency, framework, course, activity type, date logged.

## Evidence pack

**Download evidence pack (ZIP)** on the evidence summary builds one ZIP per learner and framework, ready to hand to an auditor or keep for the retention period:

| In the pack | Contents |
|---|---|
| `01 Competency evidence summary.pdf` | The summary: overview, every competency, evidence, attempts and a sign-off block |
| `02 Assessments/<course> - <activity>/Attempt N/` | An attempt record PDF for every assessed attempt (failed attempts included). Assignments: submitted files, online text, feedback files and annotated PDFs. Quizzes: every question with the learner's answer, mark, right answer and all feedback, including marker comments |
| `03 Prior learning/<evidence>/` | Evidence of prior learning details (PDF) and its files |
| `README.txt`, `manifest.csv` | What the pack is, who generated it and when, and every file's size and SHA-256 checksum |

The pack follows the **Include feedback** checkbox and the same access and release rules as the report, so it never holds more than the person downloading it could see in Moodle. Images inside question text and feedback aren't carried into the PDFs. Downloads are logged as *Evidence pack downloaded*.

## Bulk evidence downloads

*Site administration → Reports → Bulk evidence downloads* builds evidence for a whole group at once:

1. Choose **Learners from**: a learning plan template (everyone with a plan from it), a cohort, a course (everyone actively enrolled who can be rated on competencies), or a course category (every course in it, with its subcategories unless you untick that), e.g. a whole qualification.
2. Choose what to cover: **Learning plans** (the default: each learner's active plans, or their latest plan; for learners without one, the units they have evidence in) a competency framework, or one unit (listed under its framework). Then choose **Evidence summaries** (one PDF per learner) or **Evidence packs** (one ZIP per learner, as above). Tick or untick **Include feedback**, and any **Redact** options.
3. Press **Build download**. It's built in the background, and you get a notification with the link when it's ready.

The download contains one file per learner, `index.csv` (every learner and whether they were included) and `README.txt`. It's built **as you**, so only learners whose evidence you can see are included; the rest are listed as skipped. Up to 200 learners per download. Finished downloads stay on the page (and in your own file area, which no one else can open) for 7 days, then a scheduled task deletes them.

Needs the capability `report/evidencereporter:bulkdownload` (given to Manager by default). Moodle's background tasks (cron) must be running.

## Validation sampling

*Site administration → Reports → Validation sampling* draws a random sample of **judgements** (one learner's result in one unit, in one course) for validation, moderation or an auditor, and records how it was drawn.

1. **Where from:** a course, or a course category (with subcategories). **Units:** all units of the frameworks used there, one framework, or one unit. A unit's judgement includes ratings of anything below it.
2. **Which judgements:** judged between two dates, by result (any, proficient, not yet proficient) and by marks. A learner's mark is their average percentage across the unit's linked activities with a grade: choose high (80%+), middle (65–79%), borderline (50–64%), low (below 50%) or a custom range.
3. **How many:** a sample size (up to 200), spread evenly across assessors, results, mark bands or courses (or purely random). Tick **That many from each group** for, say, 3 per assessor. A group with too few judgements gives what it has; the rest goes to the others.

Each sample is saved with its filters, the size of the eligible population, a **draw code** and a population check. The same filters and draw code against the same judgements always draw the same sample, so you can show an auditor the sample was random and repeat it. Enter an earlier draw code under *Show more…* to repeat a draw.

**Swaps:** if a sampled judgement can't be used (the learner withdrew, the assessor is on the validation panel), press **Swap** and give a reason. It stays on the sample, marked as swapped out with your reason, and a replacement is drawn from the same group (or any group, if that one has run out). Replacements are drawn from the draw code too.

**Build the evidence:** summaries or packs, with feedback and redaction options as for bulk downloads, but one file per sampled judgement covering that unit. The download also has:

- **Sample register.csv:** every judgement, swaps included, with learner, unit, course, assessor, result, mark, date judged, group, how it was selected, status, its file, and blank **Validator findings** and **Judgement confirmed?** columns.
- **Sampling record.txt:** who drew the sample and when, the filters, the population, the draw code and every swap with its reason.

Uses the same capability as bulk downloads.

## Settings

*Site administration → Plugins → Reports → Evidence Reporter*

| Setting | What it does |
|---|---|
| **Terminology profile** | The words documents use. *Australian VET* (the default), *Higher education* (Student, Learning outcome, Marker, Achieved), *UK apprenticeships* (Apprentice, KSB, Apprenticeship standard, Met), *South African occupational* (Outcome, Qualification, Portfolio of evidence) or *Generic*. To change one word, use Moodle's language customisation on the `term_` strings. |
| **Similarity checks** | Shows the result from your similarity plugin (Turnitin, Ouriginal, Copyleaks or any plugin using Moodle's plagiarism API) beside assignment evidence, as the same badge and link the plugin shows in the assignment. Staff who can grade the activity see it; learners don't. Choose summary and report only (default), also in evidence packs (as text), or off. The full similarity report stays in the similarity tool. |
| **Gradebook adjustments** | When an assessment sits in its own gradebook category with penalty items (late, academic misconduct), documents show the category total as the final result, each penalty with its feedback, the calculation, and any hand override. Choose full detail, "Adjusted" only, or off. Penalties whose names contain academic integrity words (misconduct, plagiarism…) are flagged so reviewers check the evidence is still valid. |
| **Profile per framework** | For dual-sector providers (e.g. VET and degrees): give a framework its own profile. Only frameworks with their own profile are listed; add one with **Add a framework** (or set it back to the site profile to remove it). The rest use the site profile. Documents use the profile of the frameworks they cover; a learning plan mixing frameworks with different profiles uses the site profile. |
| **Branding per profile** | An organisation name and sign-off statement for each profile, e.g. "Example TAFE" for VET and "Example University" for higher education. |
| **Organisation name, logo, header and footer text** | Shown at the top and end of the summary, every PDF and the README. The name defaults to the site name. |
| **Evidence pack file name** | A pattern using `{document}`, `{learner}`, `{covering}`, `{date}` and `{orgname}`. `{learner}` is required, so bulk downloads never collide. |
| **Date format** and **Time format** | How Evidence Reporter shows dates and times everywhere: as the site's language (the default), DD/MM/YYYY, MM/DD/YYYY, YYYY-MM-DD, 27 November 2026, 27 Nov 2026 or November 27, 2026; and the language's clock, 24-hour or 12-hour. File names always use year-month-day. |
| **Required tool details** | The assessment tool details that must be filled in before marking (see *Before marking*). Version, review date and reviewer by default. |
| **Hold grades until RA and LLN are recorded** | For assessments that count toward the final grade: keep grades and feedback hidden until RA and LLN are recorded for every learner (see *RA and LLN before release*). On by default. |
| **Attempts allowed** | For databases, forums, workshops and checklists, which have no attempt limit of their own: the number shown on the marking page ("Attempt 2 of 3") and when **No attempts left** appears. Default 3; 0 for no limit. |
| **Summary sections** | Show or hide the evidence detail, the assessment attempts and the sign-off block. |
| **Sign-off heading and statement** | Your own heading (e.g. "Assessor declaration") and a declaration printed above the signature lines. |

The page also lists the evidence sources found on the site.

## Evidence sources

An evidence source is what Evidence Reporter knows about one activity type: where the learner's work is, what counts as feedback, and what goes in the evidence pack. Built in: **Assignment**, **Quiz**, **Database**, **Forum** (grades, posts and replies), **H5P** (attempts, scores, results by question), **SCORM** (attempts, status and score per SCO), **Workshop** (submission, reviews received with peers anonymised, grade for assessing), **Attendance** (sessions, status and remarks; when installed) and **Checklist** (when installed). Other activities still appear, linked to the activity page with their gradebook feedback.

Other plugins can add a source for their own activity type, or replace a built-in one, with the `\report_evidencereporter\hook\collect_sources` hook. Extend `\report_evidencereporter\local\source\base` and override what applies: `get_url()`, `get_row_fields_sql()`, `has_own_feedback()` and `get_feedback_html()`, `shows_current_state()`, `can_view_unreleased()` and `add_records_to_pack()`. The pack's building blocks (`learner_rows()`, `table_row()`, `html_to_pdf()`, `add_content()`, `add_file()`) are public for this. In your plugin's `db/hooks.php`:

```php
$callbacks = [[
    'hook' => \report_evidencereporter\hook\collect_sources::class,
    'callback' => [\mod_example\local\evidence_source::class, 'register'],
]];
```

`tests/fixtures/test_source.php` is a complete small example.

## Checklist activities

If the [Checklist](https://moodle.org/plugins/mod_checklist) activity is installed and a checklist is linked to a competency:

- **View evidence** opens the learner's checklist report, with their marks and comments on one page.
- **Feedback** lists every item with its mark and the comments on it, with who wrote them. Teacher-marked checklists show the assessor's Yes or No; learner-marked ones show which items the learner ticked.
- The **evidence pack** includes a Checklist record PDF per checklist.

Checklists record competency evidence when they're completed, so set the checklist's activity completion (for example, a required percentage of items) to match what counts as competent.

## Quiz questions as supporting evidence

With a plugin that maps questions to competencies installed, questions mapped in the question bank appear as supporting evidence. Evidence Reporter reads either of these, or both together:

- **Question competencies** (`qbank_competencies`), the companion plugin (Moodle 5.0+). Mappings cover every version of a question and go with course backups.
- **Question Bank Competency Mapping** (`qbank_comp_ext`), a free plugin on the Moodle Marketplace (Moodle 4.5+). It maps each version of a question separately; Evidence Reporter treats a question as mapped if any of its versions is.

The printable summary then shows, under each competency, a **Supporting evidence: quiz questions** table:

- One block per quiz attempt with the **quiz result** (*Satisfactory* or *Not satisfactory*, from the quiz's grade to pass) and grade.
- Each mapped question in that attempt with its number, mark and result (*Passed*, *Not passed*, *Awaiting marking*), and links to **View answer** (that question in the learner's attempt) and, for markers, **Comment or override mark**.
- Flags where things disagree: a question **passed in a quiz that wasn't satisfactory**, a question **not passed in a satisfactory quiz**, and a quiz that **isn't linked to the competency** in its own settings.

Question results are supporting evidence only. They never rate a competency or count as evidence on their own. The same access and release rules apply as for attempts: marks and results stay hidden until the quiz would show them. Questions drawn at random are included for the learners who got them. A question counts as passed with full marks, or with the share of its marks set in Question competencies' settings when that plugin is installed. The section follows the **Assessment attempts** summary setting, and the evidence pack's summary PDF includes it.

## Assessor guides (blank assessment tools)

For quizzes, Evidence Reporter builds the **blank assessor guide**: the assessment tool as an assessor uses it, with no learner in it. Use it for review before use, validation and audits. Download it from **Assessment tool details → Download the assessor guide (PDF)** in the quiz's settings, or at `/report/evidencereporter/assessorguide.php?cmid=…`. Teachers, editing teachers and managers can download it (capability `report/evidencereporter:assessorguide`).

The PDF contains:

- **Tool details**: course, activity, tool version, review date and reviewer.
- **Units** covered: the top-level competencies of everything the quiz and its questions are linked to.
- Blank **learner and assessment details** (name, ID, assessor, dates, location).
- **Instructions and conditions**: assessor instructions, the quiz description, conditions, resources allowed, time limit, attempts, open and close dates, grade to pass.
- **Reasonable adjustment** guidance, with space to record adjustments made.
- **Every question** in order, each with its **model answer**, any marking notes (the question's general feedback), Satisfactory / Not satisfactory boxes, a specific feedback box, and the competencies it's mapped to in small print (with either mapping plugin installed). Model answers come from the question's right answer; essays use **Information for graders**. Random questions list every question in the pool.
- A **mapping matrix**: each competency, the questions mapped to it, and whether the quiz is linked to it.
- An **outcome summary** with a row per allowed attempt (up to three), general feedback, the task outcome and assessor and learner sign-off.
- **Review notes**: what the tool is missing (no model answer, no version or review date, no pass mark, random questions, questions mapped to competencies the quiz isn't linked to).

Images in questions are shown as a note to see the question in Moodle.

### Assessment tool details

Activities Evidence Reporter reads get an **Assessment tool details** section in their settings: tool version, reviewed before use on, reviewed by, assessment conditions, resources allowed, instructions for the assessor and reasonable adjustment guidance. Moodle doesn't hold these anywhere else. They're removed when the activity is deleted. They aren't copied by backup and restore or course copy yet.

### In evidence packs

With **Include blank assessor guides in evidence packs** on (the default), each quiz's folder in the pack gets its blank guide: the tool the learner was assessed with.

## Marking page

A bookmarkable page for assessors: what needs marking, what's missing feedback and which reasonable adjustments need
confirming, per learner and assessment. Open it from the course's **Reports > Marking**, or go to
`/report/evidencereporter/marking.php` for **All my courses** (one line per course you mark in).

- **Pick the unit** (or all units the course delivers): its assessments are the columns, learners the rows. Assessors
  don't need to know the mapping: an assessment belongs to a unit when it's linked to anything in it.
- **Each cell** shows the status and links to where you mark it (the assignment grader, the quiz's manual grading, or
  the gradebook): Not submitted, In progress, To mark (with days waiting), Feedback needed (Not satisfactory with no
  feedback, flagged), Not satisfactory: awaiting resubmission, Marked, not released (marking workflow), Satisfactory,
  with the grade and attempt (e.g. "Attempt 1 of 3"). Satisfactory and Not satisfactory follow the grade item's grade to
  pass.
- **Colours:** blue waiting to be marked; amber after 7 days, red after 14 (site settings *Amber after* and *Red after*).
  Rows with the most urgent marking come first.
- **Reasonable adjustments:** each cell shows "RA: N/A" or how many are recorded, and any Moodle overrides or extensions
  still to confirm, linking to the assessment's Reasonable adjustments page (where the activity records them).
- **Sign-off:** a unit is **Ready to sign off** when all its assessments are Satisfactory and the unit isn't rated in the
  course yet; it links to the learner's rating page. Each row also links to the learner's evidence summary.
- **RA and LLN:** under each assessment heading, how many learners still need RA and LLN recorded, and **Grades held**
  while its grades are held. A unit isn't **Ready to sign off** until RA and LLN are recorded for its assessments; until
  then it shows *Ready once RA/LLN recorded*. The **RA/LLN** column opens the learner's own RA and LLN page (every
  assessment in the course).
- **Learning plan:** links to the learner's learning plans that cover the course's units (or the unit picked).
- **Who:** `report/evidencereporter:marking` (teachers and managers by default), limited to their groups when the course
  uses separate groups.

## Reasonable adjustments

Record each reasonable adjustment made for a learner in an assessment: what was adjusted (extra time, a reader, a scribe,
spoken responses, an alternative format and so on), the details, the reason and who approved it.

- **Which courses:** *Record reasonable adjustments for* picks the terminology profiles that use them (Australian VET
  and UK apprenticeships by default). An activity follows the profile of the frameworks it's linked to, so a higher
  education course shows nothing about adjustments. Untick every profile to turn the feature off.

- **Where:** RA is recorded with LLN on each activity's **More > RA and LLN** page (see *RA and LLN before release*).
  For an adjustment that needs its reason and approver on record, **Detailed reasonable adjustments** at the foot of
  that page (or **Record an adjustment for** on a learner's evidence summary) records one; it then counts as RA: Yes on
  the RA and LLN page, shown there and not asked for again.
- **Moodle's own:** quiz user overrides, assignment user overrides and assignment extensions are shown as a hint under
  the learner's RA details until RA is answered, and can be confirmed with a reason and approver on the detailed page.
- **Where they appear, per assessment:** the evidence summary and the evidence pack's summary PDF list every assessment
  linked to the learner's competencies, with its adjustments or "N/A"; each competency's evidence ends with a
  "Reasonable adjustment" line for its assessments; validation sample registers have a column (the learner's
  adjustments in that course and unit, or "N/A").
- **Who sees them:** staff with `report/evidencereporter:viewadjustments` (teachers and managers by default) see
  adjustments and reasons; `report/evidencereporter:manageadjustments` records them. Learners see their own unless
  *Learners see their own adjustments* is off.
- **Reasons in downloads:** evidence packs, bulk downloads and sample downloads show each adjustment and that a reason
  was recorded, but leave the reason out, unless *Include reasons in downloads* is on. Reasons may touch on disability
  or health.
- Changes are logged (events), the privacy provider exports and deletes them, and they're deleted with their activity.

## RA and LLN before release

For every learner in an assessment, the assessor records whether a **reasonable adjustment (RA)** was made and whether
the learner had **language, literacy and numeracy (LLN)** needs or support, with details for each "yes". Assessments
that count toward the final grade aren't released until every learner is recorded.

- **Where:** each activity's **More > RA and LLN** page, or the *RA/LLN* link under each assessment on the marking page
  (which shows how many learners are still to record, and a **Grades held** badge). The page starts with a short
  explainer of both terms and what to do, and says plainly that grades are held until it's done.
- **How:** for each learner, tick **Yes** or **No** for RA and for LLN; **Yes** opens a details box to describe what was
  done. Nothing is ticked to begin with, and only what you tick is saved: a learner you haven't checked stays *Not
  recorded* (or *Partly recorded* with one of the two answered), so correcting one learner never records the others.
  The tick box in each Yes or No column heading ticks or unticks that whole column, for when everyone's the same. A
  learner with a detailed adjustment on the Reasonable adjustments page has it ready in the RA details box, should RA be
  Yes.
- **What's held:** grade items that count toward the course total. Items with no grade, a weight of 0 (Natural or
  Weighted mean), or in a category that itself doesn't count, aren't held. Held means hidden in the gradebook, which
  also hides assignment feedback. Until every learner is recorded, the whole grade item is hidden; then the hidden
  setting it had is put back. If a teacher shows a held item, it's hidden again and shown once the hold lifts. A learner
  who joins after that has only their own grade held, so other learners' released grades stay visible.
- **Attempts share records:** activities in one gradebook category (no subcategories, two or more activities) that
  takes the **Highest grade**, or whose activities are all linked to the same competencies, are treated as attempts of
  one assessment. A learner recorded in one is recorded in all, so a resubmission isn't held and nothing is entered
  twice. The later attempt's page shows "Carried over from …"; a record is kept for it only if its answers differ.
- **Already graded:** assessments with grades when holds begin (on upgrade, or when the setting is turned on) aren't
  held as a whole, so grades learners can see stay visible. Learners without a grade in them are still held one by
  one.
- **One learner at a time:** the marking page's **RA/LLN** column opens a learner's own RA and LLN page: every
  assessment in the course in one table, with the same tick boxes, tick-all boxes and details, and a *Grades held*
  badge on the assessments whose grades are waiting.
- **Nudges:** the marking page counts *RA/LLN to record* (per course, on *All my courses*, and on the Marking nudge
  block), shows how many learners are left under each assessment, and only offers **Ready to sign off** once RA and
  LLN are recorded (*Ready once RA/LLN recorded* until then, linking to the learner's page). On the evidence summary
  and evidence pack, staff see an alert listing the assessments still to record, and each competency is flagged
  **RA/LLN not recorded**, rolled up from criteria to their units and qualification. The flag becomes **Evidence
  complete, RA/LLN missing** when the competency is rated proficient or everything under it has evidence. Learners
  don't see these flags on their own summary or pack.
- **When:** each record keeps the date and time it was saved and who saved it, shown on the RA and LLN pages and in the
  LLN table of the summary and evidence pack ("Recorded 27/11/2026, 14:05 by …").
- **Where it shows:** the evidence summary and evidence pack list LLN by assessment ("N/A", the details, or "Not
  recorded") beside reasonable adjustments, and each competency's evidence ends with an LLN line; a recorded RA
  appears as "Adjustment made" when there's no detailed adjustment. Validation sample registers have an LLN column.
- **Setting:** *Hold grades until RA and LLN are recorded* (on by default) applies to frameworks that record reasonable
  adjustments (*Record reasonable adjustments for*). Holds are re-checked when learners or grade items change, and
  hourly (*Check release holds for RA and LLN*).
- **Limits:** quizzes show their own marks by their review options, which the hold doesn't change (the gradebook grade
  is held). If a teacher shows a held item and then hides it again before the hold lifts, it's shown when the hold
  lifts.
- Who sees and records them, logging and privacy work as for reasonable adjustments.

## Before marking

The order assessors work in: the assessment tool's details are filled in, the learner's RA and LLN are recorded, then
the work is marked.

- **Check before marking** (on the marking page, each marker's own choice): when something's still to do for a learner,
  their cell opens a short checklist instead of the grader. 1. **Assessment tool details**: done, or which required
  details are missing, with **Fill in the tool details** for those who can edit the activity, or **Let the editing
  teachers know** for those who can't (a Moodle message to everyone who can edit the activity; the checklist then shows
  who told them and when). 2. **Reasonable adjustment and LLN** for this learner, with the same Yes and No tick boxes.
  3. **Start marking** opens the grader. Marking is never blocked: **Mark anyway** works too, and is noted in the log
  (*Marking started before checks*). When everything's done, the cell goes straight to the grader.
- **Warnings where marking happens:** the activities' own marking pages (assignment submissions and grader, quiz
  results, database entries, workshop, checklist report, forum) tell markers what's missing, with the same links.
- **Required tool details** (setting): which assessment tool details must be filled in. By default the tool version,
  the review date and who reviewed it. Example text doesn't count. The marking page shows **Tool details missing**
  under an assessment until they're done.
- **Groups:** assessors only see, and record RA and LLN for, learners in their groups when the course or activity uses
  separate groups.

## Learner identity and redaction

Every official document (the printable summary, each PDF in an evidence pack, the pack README and the bulk index) shows the learner's **name, username, ID number** (if set) and **learner reference**. The reference (e.g. `L-3F9A12C0`) is worked out from the learner's ID and the site, so it's the same on every document for that learner, but it doesn't say who they are.

To share evidence without identifying people, tick any of the **Redact** boxes on the summary page or the bulk download form:

| Option | What's removed |
|---|---|
| Learner name | The learner's name, from every document and file name, and from feedback, comments, notes, evidence descriptions, quiz responses, online text and prior learning details |
| Learner username | Username, ID number and email address, in the same places |
| Staff names | Assessors, markers, raters, comment authors and whoever generated the document |

On the summary page, changing a box reloads the page with the redaction applied, and **Download evidence pack** uses the same choices. Redaction happens on the server, so nothing redacted ever reaches the browser or the ZIP. With the name or username redacted, file names use the learner reference.

Not redacted: files that learners submitted or assessors uploaded (documents, annotated PDFs, prior learning attachments) are included as they are, apart from their file names. Check them before sharing. Each redacted document says what was redacted. Single name parts with no letters in them (for example a surname of "09") are only redacted as part of the full name, so dates and times stay readable.

## Assessment attempts

Moodle only logs competency evidence when an activity's completion first changes to complete. A failed attempt (where completion needs a pass) never becomes evidence, and a resubmission doesn't log anything new. Evidence Reporter reads every attempt from the activities themselves:

- **Assessment attempts** report source: one row per assessed assignment attempt and finished quiz attempt, failed ones included, for each competency linked to the activity. It shows the attempt number, dates, grade with Pass/Fail, marker, *that attempt's* feedback, release status and a link. For quizzes the feedback is a link to the attempt review with every question on one page: the learner's answers with all their feedback and any marker comments. Filter by *Outcome* (Pass / Fail / No grade to pass) or *Latest attempt only*.
- **Attempt history** column on the evidence report: the full attempt history of the row's activity in one cell.
- **Assessment attempts** table on the printable summary, under each competency.

Release rules carry over: assignment attempts follow marking workflow and gradebook hiding, and quiz marks and feedback follow the quiz's review options, so learners never see more than the activity itself would show them. Supported: assignments (each graded attempt, with files from every submission and feedback plugin, e.g. Poodll audio and video recordings and feedback; for add-ons that keep content outside Moodle, such as Cloud Poodll, the link or transcript the plugin shows), quizzes (each finished attempt) and databases (each **rated** entry, whatever the rating, with all the entry's comments, dated and named, as the feedback). Forums don't have attempts; instead, under each competency a forum is linked to, the summary lists the learner's **forum contributions**: the whole-forum grade first (with its rubric or marking guide), then the ratings result, then each post with what it replied to, its rating, attachments and similarity results (staff only). A reply to another learner shows only "another learner", the subject and a 30-word excerpt, so other learners' names and work stay out of the evidence; staff are named unless redacted. Ungraded submissions and unrated entries don't appear until they are graded or rated.

**Attempt history for databases, forums, workshops and checklists.** These activities have no attempts of their own: the learner edits the same entry, post or checklist, and the assessor re-marks it and edits their comments. So when an assessor marks one **Not satisfactory** (below the grade item's *Grade to pass*), Evidence Reporter keeps a snapshot: the result, the assessor, the date and the feedback (gradebook feedback, checklist comments, comments on database entries, and the assessor's replies to the learner's forum posts). The snapshot keeps gathering feedback until the learner does more work or is marked again, then it is closed, so later edits and overwritten comments don't change it. Re-marking before the learner does anything updates the same attempt. Satisfactory results aren't snapshotted; the live result is the latest attempt. Assessors mark as they always have. After upgrading, earlier Not satisfactory marking is filled in from the gradebook's history (feedback only; comments from back then can't be dated to an attempt). The trail shows on the summary, in the evidence pack and on the marking page ("Attempt 2 of 3", **No attempts left**); the number allowed is the **Attempts allowed** setting (default 3).

**Databases as assessments:** turn on *Ratings* in the database settings (for example, a *Not satisfactory, Satisfactory* scale with *Maximum rating*), set a *Grade to pass*, and use the completion condition *Receive a grade → Passing grade*. Assessors rate each entry and write their feedback as comments on it.

## Printable evidence summary

Each learner has a **Competency evidence summary**, suitable for printing or saving as a PDF to hand to an auditor. Open it from:

- the **Print evidence summary** column in the report, or
- the learner's profile → *Reports* → *Competency evidence summary*.

### What it covers

Use **Covering** at the top of the page to choose:

| Option | Covers |
|---|---|
| **Active learning plans** (the default) | All the learner's active plans together, e.g. a core plan from a template plus an electives plan. Each plan gets its own heading. |
| **Learning plan: …** | One plan, active or completed |
| **Units with evidence** | Every unit the learner has evidence in, and everything beneath it, grouped by framework. The default for learners without a learning plan, so plans are optional: link competencies to activities in courses and the evidence shows up. Units not started (e.g. electives not taken) are left out. |
| **Unit: …** | One unit and everything beneath it. Handy for clustered delivery: when an auditor samples one unit, shared tasks show only against that unit. Units are those in the learner's plans, then the top level of each framework (or the children of a framework's only top-level competency, e.g. the qualification). |
| **Competency framework: …** | Every competency in the framework, including ones the learner isn't enrolled in |

A plan covers each competency in it **and everything beneath it**. If a plan holds units, the summary also shows their elements, performance criteria and evidence items, which is usually where the evidence sits.

If the learner has no active plan, the default is their most recent plan. With no plans at all, it's the framework (or you choose, if there's more than one). Draft plans are left out until they're active.

For a **completed** plan, ratings are shown as they were when the plan was completed, so the summary matches what was signed off even if a rating changed later. The page says so.

### What it shows

- the learner, and the plans covered (status, template, completion or due date) or the framework
- an overview
- every competency covered, in order, with its current rating, the latest judgement (rating, date, assessor) and how many evidence items it has. Competencies without evidence are marked, so gaps stand out.
- the detail for each competency: date, source, evidence URL (printed in full), rating given, assessor and note, and the full feedback
- a review and sign-off block

Untick **Include feedback** at the top of the page to print without assessor feedback. The printout then says that feedback was deliberately left out.

The summary follows the same access and release rules as the report. Viewing it is logged as an *Evidence summary viewed* event.

## Where the evidence link goes

1. The URL stored on the evidence, if there is one (evidence of prior learning, SMS links).
2. Otherwise a link for that activity type:
   - Assignment: the grader, opened on this learner
   - Quiz: the learner's latest finished attempt (or the quiz page)
   - Forum: the learner's posts in that course (the summary also lists each post, see below)
   - Database: the learner's latest entry (or the database page)
   - Anything else: the activity page

The target page always does its own permission check.

## Who sees which rows

Custom reports don't filter rows by permission on their own, so the data source adds this rule. A viewer sees a row if:

- they have `moodle/competency:usercompetencyview` at system level (managers, admins), or
- the evidence is about them, or
- the evidence came from a course where they have `moodle/competency:usercompetencyview` (teachers by default), or
- they have that capability in the learner's user context (mentor/supervisor roles).

Evidence of prior learning sits in the learner's user context. Course teachers therefore don't see it unless they also hold a user-context role. This matches core Moodle.

## Feedback and release

Feedback comes from the gradebook (`grade_grades.feedback`). It counts as **released** when the grade item and grade aren't hidden (or their hidden-until date has passed), and, for assignments that use marking workflow, the state is "released".

- Learners, and anyone else without the capabilities below, see **Pending release** until the feedback is released.
- Users with `moodle/grade:viewhidden`, or `mod/assign:grade` for assignments, see the feedback with a **Not released to student** label.

The gradebook only stores a learner's *current* feedback. It is shown against the latest evidence from each activity. Earlier rows from that activity show **Superseded by a later attempt**, so old evidence never displays newer feedback.

## Test data

A seed script and a full test-environment checklist live in the separate **evidencereporter-devtools** repository, so nothing that creates or deletes users ships with the plugin.

## Seeing the SQL

`cli/show_sql.php` prints the SQL a custom report runs, as a chosen user, without running it. It works for any custom report.

```
php report/evidencereporter/cli/show_sql.php --list
php report/evidencereporter/cli/show_sql.php --id=3 --user=er_teacher_elearn
php report/evidencereporter/cli/show_sql.php --id=3 --user=er_teacher_elearn --inline > report.sql
```

`--inline` fills in the parameter values so the output can be pasted straight into a SQL client.

## Tests

```
vendor/bin/phpunit public/report/evidencereporter/tests/reportbuilder/datasource/evidence_test.php
```

The tests cover default content, row access (teacher / learner / unrelated user), feedback release, superseded feedback, assessor fallback, evidence links, and a stress test of every column, aggregation and condition. Behat features in `tests/behat` cover creating the report and row access in the browser.

CI runs on every push (GitHub Actions, moodle-plugin-ci): code checks, PHPUnit and Behat on Moodle 4.5, 5.1 and 5.2 with PostgreSQL and MariaDB.

## Known limitations and next steps

- **Attempts from added sources.** Sources added by other plugins supply links, feedback and evidence pack records, but don't yet appear in the *Assessment attempts* report source, which is a single query over assignments, quizzes and database entries.
- **Deleted users.** When a user is deleted, Moodle deletes their competency evidence, so their rows disappear from this report. Given the two-year retention requirement, suspend accounts rather than delete them.
- **Summary view** (one row per learner × competency): this can be built now with Report Builder aggregation. A ready-made report template is planned.

## Licence

Copyright 2026 Daniel Maloney. Licensed under the GNU GPL v3 or later; see LICENSE.
