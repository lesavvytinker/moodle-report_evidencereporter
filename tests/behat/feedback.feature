@report @report_evidencereporter
Feature: Assessment feedback
  In order to show learners are asked for feedback and act on it
  As a course coordinator
  I need learners' feedback on assessments collated without names

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | student1 | Sam       | Student  |
      | teacher1 | Tess      | Teacher  |
      | marker1  | Mia       | Marker   |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | teacher1 | C1     | editingteacher |
      | marker1  | C1     | teacher        |
    And the following "activities" exist:
      | activity | name          | course | idnumber |
      | assign   | AT1 Report    | C1     | AT1      |
      | feedback | AT1 feedback  | C1     | FB1      |

  Scenario: Set which assessment a survey is about, and see it in the report
    Given I am on the "AT1 feedback" "feedback activity editing" page logged in as "teacher1"
    When I set the field "Feedback on" to "AT1 Report"
    And I press "Save and return to course"
    And I am on the "Course 1" "course" page
    And I navigate to "Reports > Assessment feedback" in current page administration
    Then I should see "AT1 Report" in the "AT1 feedback" "table_row"
    And I should see "Overall: 0 responses from 1 learners offered a survey (0%)."

  Scenario: Markers and learners don't see the collated feedback
    Given I am on the "Course 1" "course" page logged in as "marker1"
    Then "Assessment feedback" "link" should not exist
    And I am on the "Course 1" "course" page logged in as "student1"
    And "Assessment feedback" "link" should not exist
