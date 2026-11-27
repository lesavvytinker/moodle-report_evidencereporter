@report @report_evidencereporter
Feature: Marking page
  In order to see what needs marking, feedback or adjustments
  As an assessor
  I need one page per course, and one for all my courses

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | teacher1 | Tess      | Teacher  |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
      | teacher1 | C1     | teacher |
    And the following "core_competency > frameworks" exist:
      | shortname | idnumber |
      | Unit one  | U1-FW    |
    And the following "core_competency > competencies" exist:
      | shortname   | idnumber | competencyframework |
      | Unit one    | U1       | U1-FW               |
    And the following "core_competency > course_competencies" exist:
      | course | competency |
      | C1     | U1         |
    And the following "activities" exist:
      | activity | name      | course | idnumber |
      | assign   | Hazard task | C1   | ASSIGN1  |
    And the following "report_evidencereporter > activity competencies" exist:
      | activity | competency |
      | ASSIGN1  | U1         |

  Scenario: An assessor sees the course's learners and assessments, and all their courses
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    When I navigate to "Reports > Marking" in current page administration
    Then I should see "Marking"
    And I should see "Hazard task"
    And I should see "Sam Student"
    And I should see "Not submitted"
    And I should see "RA/LLN: 1 to record"
    And I click on "All my courses" "link"
    And I should see "Course 1"
    And I should see "RA/LLN to record"
    And I should not see "all caught up"

  Scenario: Recording RA and LLN for a learner from the marking page
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I navigate to "Reports > Marking" in current page administration
    When I click on "RA/LLN: 1 to record" "link" in the "Sam Student" "table_row"
    Then I should see "RA and LLN: Sam Student"
    And I should see "Hazard task"
    And I should see "1 assessment(s) still to record."
    And I set the following fields to these values:
      | RA: No for Hazard task  | 1 |
      | LLN: No for Hazard task | 1 |
    And I press "Save RA and LLN"
    And I should see "RA and LLN saved for 1 learner(s)."
    And I should see "RA and LLN are recorded for every assessment."
    And I click on "Back to marking" "link"
    And I should see "RA/LLN recorded" in the "Sam Student" "table_row"
    And I click on "All my courses" "link"
    And I should see "all caught up"

  Scenario: Learners don't get the marking page
    Given I am on the "Course 1" "course" page logged in as "student1"
    Then "Marking" "link" should not exist

  Scenario: Check before marking: tool details, then RA and LLN, then marking
    Given I am on the "Course 1" "course" page logged in as "teacher1"
    And I navigate to "Reports > Marking" in current page administration
    And I should see "Tool details missing"
    When I click on "Check before marking" "link"
    And I click on "Not submitted" "link" in the "Sam Student" "table_row"
    Then I should see "Before marking: Sam Student"
    And I should see "The assessment tool details are missing: Tool version, Reviewed before use on, Reviewed by."
    And I should see "Not done yet: assessment tool details, RA and LLN."
    And I click on "Let the editing teachers know" "link"
    And I should see "No one in this course can edit the activity, so no one was told."
    And I set the following fields to these values:
      | RA: No for Sam Student  | 1 |
      | LLN: Yes for Sam Student | 1 |
      | LLN details for Sam Student | Glossary of key terms |
    And I press "Save RA and LLN"
    And I should see "RA and LLN saved for 1 learner(s)."
    And I should see "Not done yet: assessment tool details."
    And I click on "Mark anyway" "link"
    And I should see "Hazard task"
