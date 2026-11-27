@report @report_evidencereporter
Feature: RA and LLN before release
  In order to release grades only once reasonable adjustments and LLN are on record
  As an assessor
  I need to record RA and LLN for every learner in an assessment

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | student2 | Ria       | Reader   |
      | teacher1 | Tess      | Teacher  |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity | name       | course | idnumber | grade |
      | assign   | AT1 Report | C1     | AT1      | 100   |

  @javascript
  Scenario: Tick a whole column, describe each tick, and save to release
    Given I am on the "AT1 Report" "assign activity" page logged in as "teacher1"
    When I navigate to "RA and LLN" in current page administration
    Then I should see "won't be released to learners until RA and LLN are recorded and saved for every learner"
    And I should see "Grades are held now: 2 learner(s) still to record."
    And the "LLN details for Sam Student" "field" should be disabled
    And I click on "LLN for every learner" "checkbox"
    And the "LLN details for Sam Student" "field" should be enabled
    And the "LLN details for Ria Reader" "field" should be enabled
    And I click on "LLN needs or support for Sam Student" "checkbox"
    And the "LLN details for Sam Student" "field" should be disabled
    And I press "Save RA and LLN"
    And I should see "Nothing was saved. Add the details for each tick, or untick it."
    And I should see "Describe the LLN needs or support."
    And I set the field "LLN details for Ria Reader" to "Glossary of key terms"
    And I press "Save RA and LLN"
    And I should see "RA and LLN saved for 2 learner(s)."
    And I should see "Every learner is recorded: grades can be released as usual."
    And the field "LLN details for Ria Reader" matches value "Glossary of key terms"

  Scenario: Saving with nothing ticked records No for everyone and lifts the hold
    Given I am on the "AT1 Report" "assign activity" page logged in as "teacher1"
    When I navigate to "RA and LLN" in current page administration
    Then I should see "Grades are held now: 2 learner(s) still to record."
    And I should see "Not recorded"
    And I press "Save RA and LLN"
    And I should see "RA and LLN saved for 2 learner(s)."
    And I should see "Every learner is recorded: grades can be released as usual."
    And I should not see "Not recorded"

  Scenario: Learners don't get the RA and LLN page
    Given I am on the "AT1 Report" "assign activity" page logged in as "student1"
    Then "RA and LLN" "link" should not exist
