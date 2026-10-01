@mod @mod_edpreset
Feature: Offer each preset according to its release status
  In order to work on a preset without teachers picking it up half done
  As a curator
  I need draft presets kept from everyone, and presets ready for review shown only to reviewers

  Background:
    Given the following "courses" exist:
      | fullname         | shortname | format | numsections |
      | Preset templates | TPL       | topics | 2           |
      | Teaching course  | C1        | topics | 2           |
    And the following "users" exist:
      | username  | firstname | lastname | email              |
      | teacher1  | Terry     | Teacher  | teacher1@test.com  |
      | reviewer1 | Rita      | Reviewer | reviewer1@test.com |
    And the following "course enrolments" exist:
      | user      | course | role           |
      | teacher1  | C1     | editingteacher |
      | reviewer1 | C1     | editingteacher |
    # Managers can review presets by default.
    And the following "role assigns" exist:
      | user      | role    | contextlevel | reference |
      | reviewer1 | manager | Course       | C1        |
    And the following "activities" exist:
      | activity | course | section | name          | idnumber |
      | page     | TPL    | 1       | Released page | released |
      | page     | TPL    | 1       | Review page   | review   |
      | page     | TPL    | 1       | Draft page    | draft    |
    And the following "mod_edpreset > preset details" exist:
      | activity | presetname    | description                | status   |
      | released | Released page | Ready for every teacher.   | released |
      | review   | Review page   | Waiting for a second look. | review   |
      | draft    | Draft page    | Still being written.       | draft    |
    And the following "mod_edpreset > template courses" exist:
      | course |
      | TPL    |
    And the mod_edpreset presets have been scanned

  Scenario: A teacher is offered only released presets
    Given I log in as "teacher1"
    When I open the preset chooser for course "C1" section "1"
    Then I should see "Released page"
    And I should not see "Review page"
    And I should not see "Draft page"

  Scenario: A reviewer is also offered presets ready for review, marked as such
    Given I log in as "reviewer1"
    When I open the preset chooser for course "C1" section "1"
    Then I should see "Released page"
    And I should see "For review" in the "Review page" "mod_edpreset > Preset"
    And I should not see "For review" in the "Released page" "mod_edpreset > Preset"
    And I should not see "Draft page"

  Scenario: A reviewer can add a preset that is ready for review
    Given I log in as "reviewer1"
    When I open the preset chooser for course "C1" section "1"
    And I click on "Add to course" "link" in the "Review page" "mod_edpreset > Preset"
    Then I should see "Review page" in the "#section-1 [data-for='cmlist']" "css_element"
