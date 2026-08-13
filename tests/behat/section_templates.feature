@mod @mod_edpreset
Feature: Add a whole section of preset activities at once
  In order to set a course section up the way my faculty intends
  As a teacher
  I need to add a curated set of activities in one action, and say where they go

  Background:
    Given the following "courses" exist:
      | fullname         | shortname | format | numsections |
      | Preset templates | TPL       | topics | 3           |
      | Teaching course  | C1        | topics | 3           |
    And the following "users" exist:
      | username | firstname | lastname | email             |
      | teacher1 | Terry     | Teacher  | teacher1@test.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "mod_edpreset > sections" exist:
      | course | section | name                    | summary                                     |
      | TPL    | 1       | Single activities       | Activities offered one at a time.           |
      | TPL    | 2       | Weekly cycle [Template] | A prepare, engage and consolidate sequence. |
    And the following "activities" exist:
      | activity | course | section | name              | idnumber |
      | page     | TPL    | 1       | Standalone page   | solo     |
      | page     | TPL    | 2       | Prepare for class | prepare  |
      | forum    | TPL    | 2       | Engage in class   | engage   |
      | page     | TPL    | 2       | Consolidate       | consolid |
    And the following "mod_edpreset > preset details" exist:
      | activity | presetname        | description                   | tags      |
      | solo     | Standalone page   | A page on its own.            | Reference |
      | prepare  | Prepare for class | Reading to do before class.   | Prepare   |
      | engage   | Engage in class   | A discussion to run in class. | Engage    |
      | consolid | Consolidate       | A summary to write up after.  | Prepare   |
    And the following "mod_edpreset > template courses" exist:
      | course |
      | TPL    |
    And the mod_edpreset presets have been baked
    And I log in as "teacher1"

  Scenario: A template section is offered as one card naming its activities and tags
    When I open the preset chooser for course "C1" section "1"
    Then I should see "Section templates"
    And I should see "Weekly cycle"
    And I should not see "Weekly cycle [Template]"
    And I should see "A prepare, engage and consolidate sequence."
    And I should see "3 activities"
    And I should see "Page, Forum"

  Scenario: The activities inside a template are not offered individually
    When I open the preset chooser for course "C1" section "1"
    Then I should see "Standalone page"
    And I should not see "Prepare for class"
    And I should not see "Consolidate"

  Scenario: Adding a template to an empty section adds every activity in template order
    When I open the preset chooser for course "C1" section "1"
    And I click on "Add to course" "link" in the "Weekly cycle" "mod_edpreset > Section template"
    Then I should see "Prepare for class"
    # Scoped, for two reasons. The "Added to your course" notification names every added activity in
    # one element, and the text selector matches the innermost element containing the string - so
    # unscoped, both halves of a comparison resolve to that same notification. And [data-for=cmlist]
    # alone is not enough: the course index drawer uses it too, and being JavaScript-only it would
    # make a scenario pass or fail on its tags. #section-N belongs to the content area only.
    And "Prepare for class" "text" should appear before "Engage in class" "text" in the "#section-1 [data-for='cmlist']" "css_element"
    And "Engage in class" "text" should appear before "Consolidate" "text" in the "#section-1 [data-for='cmlist']" "css_element"

  Scenario: Adding a template records it as the course's default section template
    When I open the preset chooser for course "C1" section "1"
    And I click on "Add to course" "link" in the "Weekly cycle" "mod_edpreset > Section template"
    Then the default section template for course "C1" should be "Weekly cycle"

  Scenario: Once a template has been used, the others are shown but cannot be chosen
    Given the following "mod_edpreset > sections" exist:
      | course | section | name                      | summary                    |
      | TPL    | 3       | Assessment block [Template] | A two-part assessment set. |
    And the following "activities" exist:
      | activity | course | section | name             | idnumber |
      | page     | TPL    | 3       | Assessment brief | brief    |
    And the following "mod_edpreset > preset details" exist:
      | activity | presetname       | description            | tags       |
      | brief    | Assessment brief | The brief to hand out. | Assessment |
    And the mod_edpreset presets have been baked
    And I open the preset chooser for course "C1" section "1"
    And I click on "Add to course" "link" in the "Weekly cycle" "mod_edpreset > Section template"
    When I open the preset chooser for course "C1" section "2"
    Then I should see "Assessment block"
    And I should see "You cannot select this template because a different template has already been used in the course."
    And "Add to course" "button" should exist in the "Assessment block" "mod_edpreset > Section template"
    And "Add to course" "link" should exist in the "Weekly cycle" "mod_edpreset > Section template"

  Scenario: Mixing can be allowed site-wide
    Given the following "mod_edpreset > sections" exist:
      | course | section | name                        | summary                    |
      | TPL    | 3       | Assessment block [Template] | A two-part assessment set. |
    And the following "activities" exist:
      | activity | course | section | name             | idnumber |
      | page     | TPL    | 3       | Assessment brief | brief    |
    And the following "mod_edpreset > preset details" exist:
      | activity | presetname       | description            | tags       |
      | brief    | Assessment brief | The brief to hand out. | Assessment |
    And the following config values are set as admin:
      | preventmixing | 0 | mod_edpreset |
    And the mod_edpreset presets have been baked
    And I open the preset chooser for course "C1" section "1"
    And I click on "Add to course" "link" in the "Weekly cycle" "mod_edpreset > Section template"
    When I open the preset chooser for course "C1" section "2"
    Then I should not see "You cannot select this template"
    And "Add to course" "link" should exist in the "Assessment block" "mod_edpreset > Section template"

  Scenario: A recorded template that no longer exists releases the course
    Given the following "mod_edpreset > sections" exist:
      | course | section | name                        | summary                    |
      | TPL    | 3       | Assessment block [Template] | A two-part assessment set. |
    And the following "activities" exist:
      | activity | course | section | name             | idnumber |
      | page     | TPL    | 3       | Assessment brief | brief    |
    And the following "mod_edpreset > preset details" exist:
      | activity | presetname       | description            | tags       |
      | brief    | Assessment brief | The brief to hand out. | Assessment |
    And the mod_edpreset presets have been baked
    And I open the preset chooser for course "C1" section "1"
    And I click on "Add to course" "link" in the "Weekly cycle" "mod_edpreset > Section template"
    And the following "mod_edpreset > sections" exist:
      | course | section | name                      | summary                                     |
      | TPL    | 2       | Renamed cycle [Template]  | A prepare, engage and consolidate sequence. |
    And the mod_edpreset presets have been baked
    When I open the preset chooser for course "C1" section "2"
    Then I should not see "You cannot select this template"
    And "Add to course" "link" should exist in the "Assessment block" "mod_edpreset > Section template"

  Scenario: The section id entry point shows only the section templates
    When I open the section templates page for course "C1" section "1"
    Then I should see "Section templates"
    And I should see "Weekly cycle"
    And I should not see "Standalone page"
    And I should not see "Single activities"

  @javascript
  Scenario: A section that already has activities offers the reorder dialogue
    Given the following "activities" exist:
      | activity | course | section | name           | idnumber |
      | page     | C1     | 1       | My first page  | c1first  |
      | page     | C1     | 1       | My second page | c1second |
    When I open the preset chooser for course "C1" section "1"
    And I click on "Add to course" "link" in the "Weekly cycle" "mod_edpreset > Section template"
    Then I should see "Weekly cycle" in the ".modal-title" "css_element"
    And I should see "Your section will look like this"
    And I should see "Already in your course"
    And I should see "Template" in the ".edpreset-reorder-item-template" "css_element"
    And I should see "My first page" in the ".edpreset-reorder-item-course" "css_element"
    And ".edpreset-reorder-item-template .edpreset-reorder-handle-fixed" "css_element" should exist
    And ".edpreset-reorder-item-course [data-drag-type='move']" "css_element" should exist

  @javascript
  Scenario: Confirming without dragging puts the template first and the rest below
    Given the following "activities" exist:
      | activity | course | section | name           | idnumber |
      | page     | C1     | 1       | My first page  | c1first  |
      | page     | C1     | 1       | My second page | c1second |
    When I open the preset chooser for course "C1" section "1"
    And I click on "Add to course" "link" in the "Weekly cycle" "mod_edpreset > Section template"
    And I click on "Add to course" "button" in the "Weekly cycle" "dialogue"
    # Scoped for the same reasons as the earlier ordering scenario.
    Then "Prepare for class" "text" should appear before "Consolidate" "text" in the "#section-1 [data-for='cmlist']" "css_element"
    And "Consolidate" "text" should appear before "My first page" "text" in the "#section-1 [data-for='cmlist']" "css_element"
    And "My first page" "text" should appear before "My second page" "text" in the "#section-1 [data-for='cmlist']" "css_element"
