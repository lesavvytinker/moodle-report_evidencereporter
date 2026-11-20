@report @report_evidencereporter
Feature: Bulk evidence downloads
  In order to prepare for an audit
  As a manager
  I need to download evidence summaries for every learner in a group at once

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | student1 | Ava       | Learner  |
      | student2 | Ben       | Learner  |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
      | student2 | C1     | student |
    And the following "core_competency > frameworks" exist:
      | shortname | idnumber |
      | WHS 101   | WHS101   |
    And the following "core_competency > competencies" exist:
      | shortname        | idnumber | competencyframework |
      | Identify hazards | WHS1     | WHS101              |
    And the following "cohorts" exist:
      | name       | idnumber |
      | WHS Jan 25 | WHSJAN25 |
    And the following "cohort members" exist:
      | user     | cohort   |
      | student1 | WHSJAN25 |
      | student2 | WHSJAN25 |
    And the following "activities" exist:
      | activity | name | course | idnumber |
      | assign   | Task | C1     | ASSIGN1  |
    And the following "report_evidencereporter > evidence" exist:
      | user     | competency | activity |
      | student1 | WHS1       | ASSIGN1  |
      | student2 | WHS1       | ASSIGN1  |

  Scenario: Queue a bulk download of summaries for a cohort and download it
    Given I log in as "admin"
    And I navigate to "Reports > Bulk evidence downloads" in site administration
    When I set the following fields to these values:
      | Learners from        | Cohort     |
      | Cohort               | WHS Jan 25 |
      | Covering             | WHS 101    |
    And I press "Build download"
    Then I should see "Your download is being built"
    And I should see "1 download(s) being built"
    And I run all adhoc tasks
    And I reload the page
    And I should see "Evidence summaries - WHS Jan 25 - WHS101"
    And I should see "2 of 2 learners included"
    And I should not see "being built"

  Scenario: Queue a bulk download for everyone enrolled in a course category
    Given I log in as "admin"
    And I navigate to "Reports > Bulk evidence downloads" in site administration
    When I set the following fields to these values:
      | Learners from        | Course category |
      | Course category      | Category 1      |
      | Covering             | WHS 101         |
    And I press "Build download"
    And I run all adhoc tasks
    And I reload the page
    Then I should see "Category 1 (category and subcategories)"
    And I should see "2 of 2 learners included"
