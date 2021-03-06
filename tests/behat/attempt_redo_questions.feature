@mod @mod_assignmentques
Feature: Allow students to redo questions in a practice assignmentques, without starting a whole new attempt
  In order to practice particular skills I am struggling with
  As a student
  I need to be able to redo each question in a assignmentques as often as necessary without starting a whole new attempt, if my teacher allows it.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | student  | Student   | One      | student@example.com |
      | teacher  | Teacher   | One      | teacher@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student  | C1     | student |
      | teacher  | C1     | teacher |
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | C1        | Test questions |
    And the following "questions" exist:
      | questioncategory | qtype       | name  | questiontext    |
      | Test questions   | truefalse   | TF1   | First question  |
      | Test questions   | truefalse   | TF2   | Second question |
    And the following "activities" exist:
      | activity   | name   | intro              | course | idnumber | preferredbehaviour | canredoquestions |
      | assignmentques       | Assignmentques 1 | Assignmentques 1 description | C1     | assignmentques1    | immediatefeedback  | 1                |
    And assignmentques "Assignmentques 1" contains the following questions:
      | question | page | maxmark |
      | TF1      | 1    | 2       |
      | TF2      | 1    | 1       |

  @javascript
  Scenario: After completing a question, there is a redo question button that restarts the question
    Given I am on the "Assignmentques 1" "mod_assignmentques > View" page logged in as "student"
    When I press "Attempt assignmentques now"
    And I click on "False" "radio" in the "First question" "question"
    And I click on "Check" "button" in the "First question" "question"
    And I press "Try another question like this one"
    Then the state of "First question" question is shown as "Not complete"
    And I should see "Marked out of 2.00" in the "First question" "question"

  @javascript
  Scenario: The redo question button is visible but disabled for teachers
    Given I am on the "Assignmentques 1" "mod_assignmentques > View" page logged in as "student"
    When I press "Attempt assignmentques now"
    And I click on "False" "radio" in the "First question" "question"
    And I click on "Check" "button" in the "First question" "question"
    And I log out
    And I am on the "Assignmentques 1" "mod_assignmentques > View" page logged in as "teacher"
    And I follow "Attempts: 1"
    And I follow "Review attempt"
    Then the "Try another question like this one" "button" should be disabled

  @javascript
  Scenario: The redo question buttons are no longer visible after the attempt is submitted.
    Given I am on the "Assignmentques 1" "mod_assignmentques > View" page logged in as "student"
    When I press "Attempt assignmentques now"
    And I click on "False" "radio" in the "First question" "question"
    And I click on "Check" "button" in the "First question" "question"
    And I press "Finish attempt ..."
    And I press "Submit all and finish"
    And I click on "Submit all and finish" "button" in the "Confirmation" "dialogue"
    Then "Try another question like this one" "button" should not exist

  @javascript @_switch_window
  Scenario: Teachers reviewing can see all the questions attempted in a slot
    Given I am on the "Assignmentques 1" "mod_assignmentques > View" page logged in as "student"
    When I press "Attempt assignmentques now"
    And I click on "False" "radio" in the "First question" "question"
    And I click on "Check" "button" in the "First question" "question"
    And I press "Try another question like this one"
    And I press "Finish attempt ..."
    And I press "Submit all and finish"
    And I click on "Submit all and finish" "button" in the "Confirmation" "dialogue"
    And I log out
    And I am on the "Assignmentques 1" "mod_assignmentques > View" page logged in as "teacher"
    And I follow "Attempts: 1"
    And I follow "Review attempt"
    And I click on "1" "link" in the "First question" "question"
    And I switch to "reviewquestion" window
    Then the state of "First question" question is shown as "Incorrect"
    And I click on "1" "link" in the "First question" "question"
    And the state of "First question" question is shown as "Not complete"
    And I switch to the main window
    And the state of "First question" question is shown as "Not answered"
    And I should not see "Submit" in the ".history" "css_element"
    And I navigate to "Results > Statistics" in current page administration
    And I follow "TF1"
    And "False" row "Frequency" column of "assignmentquesresponseanalysis" table should contain "100.00%"
    And "True" row "Frequency" column of "assignmentquesresponseanalysis" table should contain "0.00%"
    And "[No response]" row "Frequency" column of "assignmentquesresponseanalysis" table should contain "100.00%"

  @javascript
  Scenario: Redoing question 1 should save any changes to question 2 on the same page
    Given I am on the "Assignmentques 1" "mod_assignmentques > View" page logged in as "student"
    When I press "Attempt assignmentques now"
    And I click on "False" "radio" in the "First question" "question"
    And I click on "Check" "button" in the "First question" "question"
    And I click on "True" "radio" in the "Second question" "question"
    And I press "Try another question like this one"
    And I click on "Check" "button" in the "Second question" "question"
    Then the state of "Second question" question is shown as "Correct"

  @javascript
  Scenario: Redoing questions should work with random questions as well
    Given the following "questions" exist:
      | questioncategory | qtype       | name                    | questiontext |
      | Test questions   | random      | Random (Test questions) | 0            |
    And the following "activities" exist:
      | activity   | name   | intro              | course | idnumber | preferredbehaviour | canredoquestions |
      | assignmentques       | Assignmentques 2 | Assignmentques 2 description | C1     | assignmentques2    | immediatefeedback  | 1                |
    And assignmentques "Assignmentques 2" contains the following questions:
      | question                | page |
      | Random (Test questions) | 1    |
    And user "student" has started an attempt at assignmentques "Assignmentques 2" randomised as follows:
      | slot | actualquestion |
      | 1    | TF1            |
    And I am on the "Assignmentques 2" "mod_assignmentques > View" page logged in as "student"
    When I press "Continue the last attempt"
    And I should see "First question"
    And I click on "False" "radio"
    And I click on "Check" "button"
    And I press "Try another question like this one"
    Then I should see "Second question"
    And "Check" "button" should exist
