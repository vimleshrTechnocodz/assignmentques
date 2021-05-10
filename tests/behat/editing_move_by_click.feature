@mod @mod_assignmentques
Feature: Edit assignmentques page - drag-and-drop
  In order to change the layout of a assignmentques I built
  As a teacher
  I need to be able to drag and drop questions to reorder them.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | T1        | Teacher1 | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | C1        | Test questions |
    And the following "questions" exist:
      | questioncategory | qtype     | name       | questiontext        |
      | Test questions   | truefalse | Question A | This is question 01 |
      | Test questions   | truefalse | Question B | This is question 02 |
      | Test questions   | truefalse | Question C | This is question 03 |
    And the following "activities" exist:
      | activity   | name   | course | idnumber |
      | assignmentques       | Assignmentques 1 | C1     | assignmentques1    |
    And assignmentques "Assignmentques 1" contains the following questions:
      | question   | page |
      | Question A | 1    |
      | Question B | 1    |
      | Question C | 2    |
    And I log in as "teacher1"
    And I am on the "Assignmentques 1" "mod_assignmentques > Edit" page

  @javascript
  Scenario: Re-order questions by clicking on the move icon.
    Then I should see "Question A" on assignmentques page "1"
    And I should see "Question B" on assignmentques page "1"
    And I should see "Question C" on assignmentques page "2"

    When I move "Question A" to "After Question 2" in the assignmentques by clicking the move icon
    Then I should see "Question B" on assignmentques page "1"
    And I should see "Question A" on assignmentques page "1"
    And I should see "Question B" before "Question A" on the edit assignmentques page
    And I should see "Question C" on assignmentques page "2"

    When I move "Question A" to "After Page 2" in the assignmentques by clicking the move icon
    Then I should see "Question B" on assignmentques page "1"
    And I should see "Question A" on assignmentques page "2"
    And I should see "Question C" on assignmentques page "2"
    And I should see "Question A" before "Question C" on the edit assignmentques page

    When I move "Question B" to "After Question 2" in the assignmentques by clicking the move icon
    Then I should see "Question A" on assignmentques page "1"
    And I should see "Question B" on assignmentques page "1"
    And I should see "Question C" on assignmentques page "1"
    And I should see "Question A" before "Question B" on the edit assignmentques page
    And I should see "Question B" before "Question C" on the edit assignmentques page

    When I move "Question B" to "After Page 1" in the assignmentques by clicking the move icon
    Then I should see "Question B" on assignmentques page "1"
    And I should see "Question A" on assignmentques page "1"
    And I should see "Question C" on assignmentques page "1"
    And I should see "Question B" before "Question A" on the edit assignmentques page
    And I should see "Question A" before "Question C" on the edit assignmentques page

    When I click on the "Add" page break icon after question "Question A"
    When I open the "Page 2" add to assignmentques menu
    And I choose "a new question" in the open action menu
    And I set the field "item_qtype_description" to "1"
    And I press "submitbutton"
    Then I should see "Adding a description"
    And I set the following fields to these values:
      | Question name | Question D  |
      | Question text | Useful info |
    And I press "id_submitbutton"
    Then I should see "Question B" on assignmentques page "1"
    And I should see "Question A" on assignmentques page "1"
    And I should see "Question C" on assignmentques page "2"
    And I should see "Question D" on assignmentques page "2"
    And I should see "Question B" before "Question A" on the edit assignmentques page
    And I should see "Question C" before "Question D" on the edit assignmentques page

    And "Question B" should have number "1" on the edit assignmentques page
    And "Question A" should have number "2" on the edit assignmentques page
    And "Question C" should have number "3" on the edit assignmentques page
    And "Question D" should have number "i" on the edit assignmentques page

    When I move "Question D" to "After Question 2" in the assignmentques by clicking the move icon
    Then I should see "Question B" on assignmentques page "1"
    And I should see "Question D" on assignmentques page "1"
    And I should see "Question A" on assignmentques page "1"
    And I should see "Question C" on assignmentques page "2"
    And I should see "Question B" before "Question A" on the edit assignmentques page
    And I should see "Question A" before "Question D" on the edit assignmentques page

    And "Question B" should have number "1" on the edit assignmentques page
    And "Question D" should have number "i" on the edit assignmentques page
    And "Question A" should have number "2" on the edit assignmentques page
    And "Question C" should have number "3" on the edit assignmentques page
