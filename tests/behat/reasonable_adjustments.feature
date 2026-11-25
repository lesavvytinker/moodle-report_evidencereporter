@report @report_evidencereporter
Feature: Reasonable adjustments
  In order to show that adjustments were made fairly and recorded
  As a teacher
  I need to record reasonable adjustments for learners in an assessment

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | teacher1 | Tess      | Teacher  |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity | name           | course | idnumber |
      | quiz     | Knowledge quiz | C1     | QUIZ1    |

  Scenario: A teacher records an adjustment and can change it
    Given I am on the "Knowledge quiz" "quiz activity" page logged in as "teacher1"
    When I navigate to "Reasonable adjustments" in current page administration
    Then I should see "Sam Student"
    And I should see "None recorded"
    And I click on "Record an adjustment" "link"
    And I set the following fields to these values:
      | Adjustment         | Reader                     |
      | What was adjusted  | Questions read aloud       |
      | Reason             | Learning support plan      |
      | Approved by        | Learner support officer    |
    And I press "Save changes"
    And I should see "Adjustment saved."
    And I should see "Questions read aloud"
    And I should see "Learning support plan"
    And I should see "Learner support officer"
    And I should not see "None recorded"

  Scenario: Learners don't get the adjustments page
    Given I am on the "Knowledge quiz" "quiz activity" page logged in as "student1"
    Then "Reasonable adjustments" "link" should not exist
