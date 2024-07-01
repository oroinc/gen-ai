@regression
@behat-test-env
@feature-BB-24021

Feature: Vertex AI Integration

  Scenario: Check Vertex AI Integration validation rules
    Given I login as administrator
    And I go to System/Integrations/Manage Integrations
    When I click "Create Integration"
    And I fill "Vertex AI Integration Form" with:
      | Type | Vertex AI |
    Then Model field should has "text-bison@001" value
    Then Location field should has "us-central1" value
    When I click "Check Vertex AI connection"
    Then I should see "Vertex AI Integration Form" validation errors:
      | Name         | This value should not be blank. |
      | Label        | This value should not be blank. |
      | API Endpoint | This value should not be blank. |
      | Project ID   | This value should not be blank. |
    And I click "Cancel"
    And I click "Create Integration"
    When I fill "Vertex AI Integration Form" with:
      | Type | Vertex AI |
    And I save form
    Then I should see "Vertex AI Integration Form" validation errors:
      | Name         | This value should not be blank. |
      | Label        | This value should not be blank. |
      | API Endpoint | This value should not be blank. |
      | Project ID   | This value should not be blank. |
    When I fill "Vertex AI Integration Form" with:
      | Name         | Vertex AI |
      | Label        | Vertex AI |
      | API Endpoint | Endpoint  |
      | Project ID   | vertex_id |
    And I save form
    Then I should see "Vertex AI config file should not be blank."
    When I fill "Vertex AI Integration Form" with:
      | Config File | cat1.jpg |
    And I save form
    Then I should see "Vertex AI Integration Form" validation errors:
      | Config File | The mime type of the file is invalid ("image/jpeg"). Allowed mime types are "application/json". |

  Scenario: Check Vertex AI Integration connection
    Given I fill "Vertex AI Integration Form" with:
      | Name         | Vertex AI |
      | Label        | Vertex AI |
      | API Endpoint | Endpoint  |
      | Project ID   | vertex_id |
    When I click "Check Vertex AI connection"
    Then I should see "Connection could not be established" flash message
    When I fill "Vertex AI Integration Form" with:
      | Name         | Vertex AI |
      | Label        | Vertex AI |
      | API Endpoint | Endpoint  |
      | Project ID   | Wrong ID  |
      | Config File  | file.json |
    When I save form
    Then I should see "Integration saved" flash message
    And I should see "config.json"
    When I click "Check Vertex AI connection"
    Then I should see "Connection could not be established" flash message
    And I fill "Vertex AI Integration Form" with:
      | Project ID | Correct project ID |
    And I save form
    When I click "Check Vertex AI connection"
    Then I should see "Connection established successfully" flash message

  Scenario: Check Vertex AI Integration on the grid
    Given I click "Cancel"
    Then I should see following grid:
      | Name      | Type      | Status |
      | Vertex AI | Vertex AI | Active |

  Scenario: Enable WYSIWYG Editor
    Given I set configuration property "oro_form.wysiwyg_enabled" to "1"

  Scenario: Check Vertex AI-Powered Content Assistant button is hidden for Wysiwyg editor
    Given I go to Activities/Tasks
    When I press "Create Task"
    And I click "AdditionalToolbarItemsWysiwygButton"
    Then I should not see an "Open AI-Powered Content Assistant" element

  Scenario: Check Vertex AI-Powered Content Assistant button is hidden for GrapesJs editor
    Given I go to Marketing/Landing Pages
    When I press "Create Landing Page"
    Then I should not see an "AI-Powered Content Assistant" element

  Scenario: Enable AI Content Generation
    Given I go to System/Configuration
    And I follow "System Configuration/Integrations/AI Content Generation" on configuration sidebar
    When I fill "System Config Form" with:
      | Enable AI Content Generation | true |
    And I save form
    Then I should see "AI Generator"
    And I fill "System Config Form" with:
      | AI Generator | Vertex AI |
    And I save form

  Scenario: Check Vertex AI-Powered Content Assistant button is visible for Wysiwyg editor
    Given I go to Activities/Tasks
    When I press "Create Task"
    And I click "AdditionalToolbarItemsWysiwygButton"
    Then I should see an "Open AI-Powered Content Assistant" element

  Scenario: Check Vertex AI-Powered Content Assistant button is visible for GrapesJs editor
    Given I go to Marketing/Landing Pages
    When I press "Create Landing Page"
    Then I should see an "AI-Powered Content Assistant" element

  Scenario: Deactivate Vertex AI Integration
    Given I go to System/Integrations/Manage Integrations
    When I click deactivate "Vertex AI" in grid
    Then should see "Integration has been deactivated successfully" flash message

  Scenario: Check Vertex AI-Powered Content Assistant button is hidden for Wysiwyg editor after deactivating the integration
    Given I go to Activities/Tasks
    When I press "Create Task"
    And I click "AdditionalToolbarItemsWysiwygButton"
    Then I should not see an "Open AI-Powered Content Assistant" element

  Scenario: Check Vertex AI-Powered Content Assistant button is hidden for GrapesJs editor after deactivating the integration
    Given I go to Marketing/Landing Pages
    When I press "Create Landing Page"
    Then I should not see an "AI-Powered Content Assistant" element
