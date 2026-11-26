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
    And I click on "All my courses" "link"
    And I should see "Course 1"
    And I should see "all caught up"

  Scenario: Learners don't get the marking page
    Given I am on the "Course 1" "course" page logged in as "student1"
    Then "Marking" "link" should not exist
