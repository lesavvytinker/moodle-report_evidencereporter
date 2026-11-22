@report @report_evidencereporter
Feature: Validation sampling
  In order to give validators and auditors a fair, reproducible sample
  As a manager
  I need to draw a random sample of judgements, swap items with a reason, and build the evidence for it

  Background:
    Given the following "users" exist:
      | username | firstname | lastname |
      | student1 | Ava       | Learner  |
      | student2 | Ben       | Learner  |
      | student3 | Cal       | Learner  |
      | student4 | Dee       | Learner  |
      | alex     | Alex      | Assessor |
      | bo       | Bo        | Assessor |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | student4 | C1     | student        |
      | alex     | C1     | editingteacher |
      | bo       | C1     | editingteacher |
    And the following "core_competency > frameworks" exist:
      | shortname | idnumber |
      | WHS 101   | WHS101   |
    And the following "core_competency > competencies" exist:
      | shortname        | idnumber | competencyframework |
      | Identify hazards | WHS1     | WHS101              |
    And the following "core_competency > course_competencies" exist:
      | course | competency |
      | C1     | WHS1       |
    And the following "report_evidencereporter > course ratings" exist:
      | user     | competency | course | grade | assessor |
      | student1 | WHS1       | C1     | 2     | alex     |
      | student2 | WHS1       | C1     | 2     | alex     |
      | student3 | WHS1       | C1     | 2     | bo       |
      | student4 | WHS1       | C1     | 1     | bo       |

  Scenario: Draw a sample spread across assessors, swap one out and build the evidence
    Given I log in as "admin"
    And I navigate to "Reports > Validation sampling" in site administration
    When I set the following fields to these values:
      | Name            | WHS validation  |
      | Sample from     | Course category |
      | Course category | Category 1      |
      | Spread across   | Assessors       |
      | Sample size     | 2               |
    And I press "Draw sample"
    Then I should see "Sample drawn."
    And I should see "2 of 4 eligible judgements"
    And I should see "Alex Assessor"
    And I should see "Bo Assessor"
    And I click on "Swap" "link"
    And I press "Swap"
    And I should see "Required"
    And I set the field "Reason" to "Learner withdrew"
    And I press "Swap"
    And I should see "Swapped, and a replacement drawn from the same group."
    And I should see "Learner withdrew"
    And I should see "Replacement for #1"
    And I press "Build download"
    And I should see "Your download is being built"
    And I run all adhoc tasks
    And I reload the page
    And I should see "Evidence packs - WHS validation"
    And I should see "2 of 2 learners included"
