<?php

namespace Drupal\Tests\ys_node_access\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\node\Entity\Node;

/**
 * Tests CAS protection form functionality and permissions.
 *
 * @group yalesites
 */
class CasProtectionFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'node',
    'field',
    'ys_node_access',
    'user',
  ];

  /**
   * A user with permission to edit content.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $contentEditor;

  /**
   * A user without permission to edit content.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $regularUser;

  /**
   * A test node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $testNode;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create a content type.
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Page']);

    // Create users with different permissions.
    $this->contentEditor = $this->drupalCreateUser([
      'create page content',
      'edit own page content',
      'edit any page content',
      'access content',
    ]);

    $this->regularUser = $this->drupalCreateUser([
      'access content',
    ]);

    // Create a test node.
    $this->testNode = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Test Page',
      'uid' => $this->contentEditor->id(),
    ]);
  }

  /**
   * Tests that CAS protection field appears on node edit form.
   */
  public function testCasProtectionFieldExists() {
    $this->drupalLogin($this->contentEditor);
    $this->drupalGet('/node/' . $this->testNode->id() . '/edit');

    // Check that the CAS protection field is present.
    $this->assertSession()->fieldExists('field_login_required[value]');
    $this->assertSession()->pageTextContains('CAS Login Required');
  }

  /**
   * Tests that CAS protection field is in the Publishing Settings group.
   */
  public function testCasProtectionFieldGrouping() {
    $this->drupalLogin($this->contentEditor);
    $this->drupalGet('/node/' . $this->testNode->id() . '/edit');

    // Check that the field is in the Publishing Settings fieldset.
    $this->assertSession()->elementExists('css', 'details[data-drupal-selector="edit-group-publishing-settings"] input[name="field_login_required[value]"]');
  }

  /**
   * Tests CAS protection field default value.
   */
  public function testCasProtectionFieldDefaultValue() {
    $this->drupalLogin($this->contentEditor);
    $this->drupalGet('/node/add/page');

    // Check that CAS protection is disabled by default.
    $this->assertSession()->checkboxNotChecked('field_login_required[value]');
  }

  /**
   * Tests saving node with CAS protection enabled.
   */
  public function testSavingNodeWithCasProtection() {
    $this->drupalLogin($this->contentEditor);
    $this->drupalGet('/node/' . $this->testNode->id() . '/edit');

    // Enable CAS protection and save.
    $this->submitForm([
      'field_login_required[value]' => TRUE,
    ], 'Save');

    // Verify the field was saved.
    $this->testNode = Node::load($this->testNode->id());
    $this->assertTrue($this->testNode->field_login_required->value);
  }

  /**
   * Tests that users without edit permission cannot access the field.
   */
  public function testCasProtectionFieldPermissions() {
    $this->drupalLogin($this->regularUser);
    $this->drupalGet('/node/' . $this->testNode->id() . '/edit');

    // User should not have access to edit the node.
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests CAS protection field on different content types.
   */
  public function testCasProtectionFieldOnDifferentContentTypes() {
    // Create additional content types.
    $this->drupalCreateContentType(['type' => 'post', 'name' => 'Post']);
    $this->drupalCreateContentType(['type' => 'event', 'name' => 'Event']);

    $this->drupalLogin($this->contentEditor);

    // Test field exists on post content type.
    $this->drupalGet('/node/add/post');
    $this->assertSession()->fieldExists('field_login_required[value]');

    // Test field exists on event content type.
    $this->drupalGet('/node/add/event');
    $this->assertSession()->fieldExists('field_login_required[value]');
  }

  /**
   * Tests form validation with CAS protection field.
   */
  public function testFormValidationWithCasProtection() {
    $this->drupalLogin($this->contentEditor);
    $this->drupalGet('/node/add/page');

    // Submit form with CAS protection enabled but missing required fields.
    $this->submitForm([
      'field_login_required[value]' => TRUE,
    ], 'Save');

    // Should show validation error for missing title.
    $this->assertSession()->pageTextContains('Title field is required');
  }

  /**
   * Tests CAS protection field accessibility attributes.
   */
  public function testCasProtectionFieldAccessibility() {
    $this->drupalLogin($this->contentEditor);
    $this->drupalGet('/node/' . $this->testNode->id() . '/edit');

    // Check for proper form element structure.
    $checkbox = $this->assertSession()->elementExists('css', 'input[name="field_login_required[value]"]');
    $this->assertEquals('checkbox', $checkbox->getAttribute('type'));

    // Check for associated label.
    $this->assertSession()->elementExists('css', 'label[for="' . $checkbox->getAttribute('id') . '"]');
  }

  /**
   * Tests CAS protection field with different user roles.
   */
  public function testCasProtectionFieldWithUserRoles() {
    // Create a user with limited permissions.
    $limitedUser = $this->drupalCreateUser([
      'create page content',
      'edit own page content',
    ]);

    $this->drupalLogin($limitedUser);

    // Create a node as the limited user.
    $this->drupalGet('/node/add/page');
    $this->submitForm([
      'title[0][value]' => 'Limited User Page',
      'field_login_required[value]' => TRUE,
    ], 'Save');

    // Verify the field was saved correctly.
    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['title' => 'Limited User Page']);
    $node = reset($nodes);
    $this->assertTrue($node->field_login_required->value);
  }

}
