@regression
@behat-test-env
@feature-BB-24021

Feature: OpenAI Integration

  Scenario: Check OpenAI Integration validation rules
    Given I login as administrator
    And I go to System/Integrations/Manage Integrations
    When I click "Create Integration"
    And I fill "OpenAI Integration Form" with:
      | Type | OpenAI |
    Then Model field should has "gpt-3.5-turbo" value
    When I click "Check OpenAI connection"
    Then I should see "OpenAI Integration Form" validation errors:
      | Name  | This value should not be blank. |
      | Label | This value should not be blank. |
      | Token | This value should not be blank. |
    And I reload the page
    When I fill "OpenAI Integration Form" with:
      | Type | OpenAI |
    And I save form
    Then I should see "OpenAI Integration Form" validation errors:
      | Name  | This value should not be blank. |
      | Label | This value should not be blank. |
      | Token | This value should not be blank. |

  Scenario: Check OpenAI Integration connection
    Given I fill "OpenAI Integration Form" with:
      | Name  | OpenAI     |
      | Label | OpenAI     |
      | Token | Wrong token |
    When I save form
    Then I should see "Integration saved" flash message
    When I click "Check OpenAI connection"
    Then I should see "Connection could not be established" flash message
    And I fill "OpenAI Integration Form" with:
      | Token | Correct token |
    And I save form
    When I click "Check OpenAI connection"
    Then I should see "Connection established successfully" flash message

  Scenario: Check OpenAI Integration on the grid
    Given I click "Cancel"
    Then I should see following grid:
      | Name    | Type    | Status |
      | OpenAI | OpenAI | Active |

  Scenario: Enable WYSIWYG Editor
    Given I set configuration property "oro_form.wysiwyg_enabled" to "1"

  Scenario: Check Open AI Powered Content Assistant button is hidden for Wysiwyg editor
    Given I go to Activities/Tasks
    When I press "Create Task"
    And I click "AdditionalToolbarItemsWysiwygButton"
    Then I should not see an "Open AI Powered Content Assistant" element

  Scenario: Check Open AI Powered Content Assistant button is hidden for GrapesJs editor
    Given I go to Marketing/Landing Pages
    When I press "Create Landing Page"
    Then I should not see a "AI Powered Content Assistant" element

  Scenario: Enable AI Content Generation
    Given I go to System/Configuration
    And I follow "System Configuration/Integrations/AI Content Generation" on configuration sidebar
    When I fill "System Config Form" with:
      | Enable AI Content Generation | true |
    And I save form
    Then I should see "AI Generator"
    And I fill "System Config Form" with:
      | AI Generator | OpenAI |
    And I save form

  Scenario: Check Open AI Powered Content Assistant button is visible for Wysiwyg editor
    Given I go to Activities/Tasks
    When I press "Create Task"
    And I click "AdditionalToolbarItemsWysiwygButton"
    Then I should see an "Open AI Powered Content Assistant" element

  Scenario: Check generation content without predefined content for Wysiwyg editor
    Given I click "Open AI Powered Content Assistant"
    Then Keywords and Features field is empty
    And I should see the following options for "Task" select:
      | Generate content with custom provided prompt |
    And I should see the following options for "Text tone" select:
      | Formal       |
      | Casual       |
      | Instructive  |
      | Persuasive   |
      | Humorous     |
      | Professional |
      | Emotional    |
      | Sarcastic    |
      | Narrative    |
      | Analytical   |
      | Descriptive  |
      | Informative  |
      | Optimistic   |
      | Cautious     |
      | Reassuring   |
      | Educational  |
      | Dramatic     |
      | Poetic       |
      | Satirical    |

    When I click "Generate"
    Then I should see "AI Powered Content Assistant Popup Form" validation errors:
      | Keywords and Features | This value should not be blank. |
    And I fill "AI Powered Content Assistant Popup Form" with:
      | Keywords and Features | Test text |
      | Text tone             | Casual    |
    When I click "Generate"
    Then Content preview field should has "Generated content by OpenAI" value
    And Keywords and Features field is empty

    And I fill "AI Powered Content Assistant Popup Form" with:
      | Keywords and Features | Test text |
    When I click "Generate again"
    Then Content preview field should has "Generated content by OpenAI" value
    And Keywords and Features field is empty
    When I click "Add content"
    Then Description field should has "Generated content by OpenAI" value

  Scenario: Check available tasks when description is not empty for Wysiwyg editor
    Given I click "Open AI Powered Content Assistant"
    Then I should see the following options for "Task" select:
      | Correct grammar                              |
      | Expand text                                  |
      | Shorten text                                 |
      | Generate content with custom provided prompt |
    When I click "Generate"
    Then Content preview field should has "Generated content by OpenAI" value
    And I should see "Generate again"
    And I click "Cancel" in modal window
    And I click "Cancel"

  Scenario: Check Open AI Powered Content Assistant button is visible for GrapesJs editor
    Given I go to Marketing/Landing Pages
    When I press "Create Landing Page"
    Then I should see an "AI Powered Content Assistant" element

  Scenario: Check generation content without predefined content for Landing Page
    Given I click "AI Powered Content Assistant"
    Then Keywords and Features field is empty
    And I should see the following options for "Task" select:
      | Generate content with custom provided prompt |
    And should see the following options for "Text tone" select:
      | Formal       |
      | Casual       |
      | Instructive  |
      | Persuasive   |
      | Humorous     |
      | Professional |
      | Emotional    |
      | Sarcastic    |
      | Narrative    |
      | Analytical   |
      | Descriptive  |
      | Informative  |
      | Optimistic   |
      | Cautious     |
      | Reassuring   |
      | Educational  |
      | Dramatic     |
      | Poetic       |
      | Satirical    |

    When I click "Generate"
    Then I should see "AI Powered Content Assistant Popup Form" validation errors:
      | Keywords and Features | This value should not be blank. |
    And I fill "AI Powered Content Assistant Popup Form" with:
      | Keywords and Features | Test text |
      | Text tone             | Casual    |
    When I click "Generate"
    Then Content preview field should has "Generated content by OpenAI" value
    And Keywords and Features field is empty

    And I fill "AI Powered Content Assistant Popup Form" with:
      | Keywords and Features | Test text |
    When I click "Generate again"
    Then Content preview field should has "Generated content by OpenAI" value
    And Keywords and Features field is empty
    When I click "Add content"

  Scenario: Check generation content with predefined content for Landing Page
    Given I fill in Landing Page Titles field with "Test page"
    And I click "OpenBlocksTab"
    When I click "AI Powered Content Assistant"
    Then I should see the following options for "Task" select:
      | Correct grammar                              |
      | Expand text                                  |
      | Shorten text                                 |
      | Generate content with custom provided prompt |
      | Landing page content generation              |
    When I fill "AI Powered Content Assistant Popup Form" with:
      | Task | Landing page content generation |
    Then Keywords and Features field should has "Page title Test page" value
    When I click "Generate"
    Then Content preview field should has "Generated content by OpenAI" value
    And I should see "Generate again"
    And I click "Cancel" in modal window
    And I click "Cancel"

  Scenario: Check generation content without predefined content for Content Block
    Given I go to Marketing/Content Blocks
    And I press "Create Content Block"
    And I click "Add Content"
    When I click "AI Powered Content Assistant"
    Then I should see the following options for "Task" select:
      | Generate content with custom provided prompt |
    And I fill "AI Powered Content Assistant Popup Form" with:
      | Keywords and Features | Test text |
    When I click "Generate"
    Then Content preview field should has "Generated content by OpenAI" value
    And Keywords and Features field is empty
    And I click "Add content"

  Scenario: Check generation content with predefined content for Content Block
    Given I fill "Content Block Form" with:
      | Alias  | test_alias |
      | Titles | Test Title |
    And I click "OpenBlocksTab"
    When I click "AI Powered Content Assistant"
    Then I should see the following options for "Task" select:
      | Correct grammar                              |
      | Expand text                                  |
      | Shorten text                                 |
      | Generate content with custom provided prompt |
      | Content block description generation         |
    When I fill "AI Powered Content Assistant Popup Form" with:
      | Task | Content block description generation |
    Then Keywords and Features field should has "Content block title Test Title" value
    When I click "Generate"
    Then Content preview field should has "Generated content by OpenAI" value
    And I click "Cancel" in modal window
    And I click "Cancel"

  Scenario: Check generation content without predefined content for Email
    Given I click My Emails in user menu
    And I click "Compose"
    And I click "AdditionalToolbarItemsWysiwygButton"
    When I click "Open AI Powered Content Assistant"
    Then I should see the following options for "Task" select:
      | Generate content with custom provided prompt |
    And I fill "AI Powered Content Assistant Popup Form" with:
      | Keywords and Features | Test text |
    When I click "Generate"
    Then Content preview field should has "Generated content by OpenAI" value
    And Keywords and Features field is empty
    And I click "Add content"

  Scenario: Check generation content with predefined content for Email
    Given fill "Email Form" with:
      | To      | [user_org@test.domain] |
      | Subject | Test Subject           |
    When I click "Open AI Powered Content Assistant"
    Then I should see the following options for "Task" select:
      | Correct grammar                              |
      | Expand text                                  |
      | Shorten text                                 |
      | Generate content with custom provided prompt |
      | Email text generation                        |
    When I fill "AI Powered Content Assistant Popup Form" with:
      | Task | Email text generation |
    Then Keywords and Features field should has "Goal Test Subject" value
    When I click "Generate"
    Then Content preview field should has "Generated content by OpenAI" value
    And I click "Add content"
    And I click "Cancel" in modal window

  Scenario: Check generation content without predefined content for Product
    Given I go to Products/Products
    And I click "Create Product"
    And I click "Continue"
    When I click "Open AI Powered Content Assistant"
    Then Keywords and Features field is empty
    And I should see the following options for "Task" select:
      | Generate content with custom provided prompt     |
    And I fill "AI Powered Content Assistant Popup Form" with:
      | Task                  | Generate content with custom provided prompt     |
      | Keywords and Features | Test text                                        |
    When I click "Generate"
    Then Content preview field should has "Generated content by OpenAI" value
    And I click "Add content"

    When I click "AI Powered Content Assistant"
    Then Keywords and Features field is empty
    And I should see the following options for "Task" select:
      | Generate content with custom provided prompt     |
    And I fill "AI Powered Content Assistant Popup Form" with:
      | Keywords and Features | Test text |
    When I click "Generate"
    Then Content preview field should has "Generated content by OpenAI" value
    And I click "Add content"

  Scenario: Check generation content with predefined content for Product
    Given fill "Product Form" with:
      | Name       | Product Name    |
      | SKU        | A005            |
    When I click "Open AI Powered Content Assistant"
    Then I should see the following options for "Task" select:
      | Correct grammar                                     |
      | Expand text                                         |
      | Shorten text                                        |
      | Generate content with custom provided prompt        |
      | Extract product features from the description       |
      | Generate product description with an open prompt    |
      | Populate short description based on the description |
    When I fill "AI Powered Content Assistant Popup Form" with:
      | Task | Generate product description with an open prompt |
    Then Keywords and Features field should has "Name Product Name, sku A005" value
    When I click "Generate"
    Then Content preview field should has "Generated content by OpenAI" value
    And I should see "Generate again"
    And I click "Cancel" in modal window

    When I click "OpenBlocksTab"
    And I click "AI Powered Content Assistant"
    Then I should see the following options for "Task" select:
      | Correct grammar                                     |
      | Expand text                                         |
      | Shorten text                                        |
      | Generate content with custom provided prompt        |
      | Extract product features from the description       |
      | Generate product description with an open prompt    |
    When I click "Generate"
    Then Content preview field should has "Generated content by OpenAI" value
    And I click "Cancel" in modal window

  Scenario: Check that form reloaded when error occurred
    Given click "Open AI-Powered Content Assistant"
    When I fill "AI-Powered Content Assistant Popup Form" with:
      | Task | Generate product description with an open prompt |
    And I fill "AI-Powered Content Assistant Popup Form" with:
      | Keywords and Features | |
    And I click "Generate"
    Then I should see "AI-Powered Content Assistant Popup Form" validation errors:
      | Keywords and Features  | This value should not be blank. |
