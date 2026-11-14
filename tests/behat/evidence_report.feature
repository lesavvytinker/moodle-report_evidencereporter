@report @report_evidencereporter
Feature: Competency evidence report source
  In order to show assessors and auditors the evidence behind competency judgements
  As a manager
  I need a custom report listing evidence, limited to what each viewer may see

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | teacher1 | Tess      | One      |
      | teacher2 | Toby      | Two      |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
      | Course 2 | C2        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student1 | C2     | student        |
      | teacher1 | C1     | editingteacher |
      | teacher2 | C2     | editingteacher |
    And the following "core_competency > frameworks" exist:
      | shortname | idnumber |
      | Framework | FW1      |
    And the following "core_competency > competencies" exist:
      | shortname          | idnumber | competencyframework |
      | Identify hazards   | C-1      | FW1                 |
      | Control risks      | C-2      | FW1                 |
    And the following "core_competency > course_competencies" exist:
      | course | competency |
      | C1     | C-1        |
      | C2     | C-2        |
    And the following "activities" exist:
      | activity | name           | course | idnumber |
      | assign   | Hazard task    | C1     | ASSIGN1  |
      | assign   | Risk task      | C2     | ASSIGN2  |
    And the following "report_evidencereporter > evidence" exist:
      | user     | competency | activity |
      | student1 | C-1        | ASSIGN1  |
      | student1 | C-2        | ASSIGN2  |
    And the following "core_reportbuilder > Reports" exist:
      | name             | source                                                         | default |
      | Evidence report  | report_evidencereporter\reportbuilder\datasource\evidence     | 1       |
    And the following "core_reportbuilder > Audiences" exist:
      | report          | classname                                          | configdata |
      | Evidence report | core_reportbuilder\reportbuilder\audience\allusers |            |

  Scenario: Admin sees every evidence row with a link to the submission
    Given I am on the "Evidence report" "reportbuilder > View" page logged in as "admin"
    Then I should see "Sam Student" in the "Identify hazards" "table_row"
    And I should see "Course 1" in the "Identify hazards" "table_row"
    And I should see "Course 2" in the "Control risks" "table_row"
    And I should see "Hazard task" in the "Identify hazards" "table_row"
    And "View evidence" "link" should exist in the "Identify hazards" "table_row"

  Scenario: A teacher only sees evidence from their own course
    Given I am on the "Evidence report" "reportbuilder > View" page logged in as "teacher1"
    Then I should see "Identify hazards"
    And I should not see "Control risks"

  Scenario: A learner sees their own evidence
    Given I am on the "Evidence report" "reportbuilder > View" page logged in as "student1"
    Then I should see "Identify hazards"
    And I should see "Control risks"

  Scenario: Teacher opens a learner's printable evidence summary from the report
    Given I am on the "Evidence report" "reportbuilder > View" page logged in as "teacher1"
    When I click on "Print evidence summary" "link" in the "Identify hazards" "table_row"
    Then I should see "Competency evidence summary"
    And I should see "Sam Student"
    And I should see "Identify hazards"
    And I should see "This summary only includes evidence you have access to"
    And I should see "No evidence recorded" in the "Control risks" "table_row"
    And I should see "student1" in the "Username" "table_row"
    And I should see "Learner reference"

  Scenario: Teacher redacts the learner's name and username on the summary
    Given I am on the "Evidence report" "reportbuilder > View" page logged in as "teacher1"
    And I click on "Print evidence summary" "link" in the "Identify hazards" "table_row"
    When I set the field "Learner name" to "1"
    And I set the field "Learner username" to "1"
    And I press "Apply"
    Then I should not see "Sam Student"
    And I should not see "student1"
    And I should see "[redacted]" in the "Username" "table_row"
    And I should see "Redacted: learner name, learner username"
    And the field "Learner name" matches value "1"

  Scenario: A learner finds their evidence summary on their profile
    Given I log in as "student1"
    When I follow "Profile" in the user menu
    And I follow "Competency evidence summary"
    Then I should see "Competency evidence summary"
    And I should see "Identify hazards"
    And I should not see "This summary only includes evidence you have access to"
