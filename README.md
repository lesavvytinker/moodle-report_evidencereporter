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

## Settings

*Site administration → Plugins → Reports → Evidence Reporter*

| Setting | What it does |
|---|---|
| **Terminology profile** | The words documents use. *Australian VET* (the default), *Higher education* (Student, Learning outcome, Marker, Achieved), *UK apprenticeships* (Apprentice, KSB, Apprenticeship standard, Met), *South African occupational* (Outcome, Qualification, Portfolio of evidence) or *Generic*. To change one word, use Moodle's language customisation on the `term_` strings. |
| **Similarity checks** | Shows the result from your similarity plugin (Turnitin, Ouriginal, Copyleaks or any plugin using Moodle's plagiarism API) beside assignment evidence, as the same badge and link the plugin shows in the assignment. Staff who can grade the activity see it; learners don't. Choose summary and report only (default), also in evidence packs (as text), or off. The full similarity report stays in the similarity tool. |
| **Gradebook adjustments** | When an assessment sits in its own gradebook category with penalty items (late, academic misconduct), documents show the category total as the final result, each penalty with its feedback, the calculation, and any hand override. Choose full detail, "Adjusted" only, or off. Penalties whose names contain academic integrity words (misconduct, plagiarism…) are flagged so reviewers check the evidence is still valid. |
| **Profile per framework** | For dual-sector providers (e.g. VET and degrees): give a framework its own profile. Documents use the profile of the frameworks they cover; a learning plan mixing frameworks with different profiles uses the site profile. |
| **Branding per profile** | An organisation name and sign-off statement for each profile, e.g. "Example TAFE" for VET and "Example University" for higher education. |
| **Organisation name, logo, header and footer text** | Shown at the top and end of the summary, every PDF and the README. The name defaults to the site name. |
| **Evidence pack file name** | A pattern using `{document}`, `{learner}`, `{covering}`, `{date}` and `{orgname}`. `{learner}` is required, so bulk downloads never collide. |
| **Summary sections** | Show or hide the evidence detail, the assessment attempts and the sign-off block. |
| **Sign-off heading and statement** | Your own heading (e.g. "Assessor declaration") and a declaration printed above the signature lines. |

The page also lists the evidence sources found on the site.

## Evidence sources

An evidence source is what Evidence Reporter knows about one activity type: where the learner's work is, what counts as feedback, and what goes in the evidence pack. Built in: **Assignment**, **Quiz**, **Database**, **Forum** (grades, posts and replies) and **Checklist** (when installed). Other activities still appear, linked to the activity page with their gradebook feedback.

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
