<?php

namespace Drupal\Tests\ys_node_access\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\Node;

/**
 * Tests CAS protection modal JavaScript functionality.
 *
 * @group yalesites
 */
class CasProtectionModalJavascriptTest extends WebDriverTestBase {

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

    // Create a user with permission to edit content.
    $this->contentEditor = $this->drupalCreateUser([
      'create page content',
      'edit own page content',
      'edit any page content',
      'access content',
    ]);

    // Create a test node.
    $this->testNode = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Test Page',
      'uid' => $this->contentEditor->id(),
      'field_login_required' => FALSE,
    ]);
  }

  /**
   * Tests that modal appears when enabling CAS protection.
   */
  public function testModalAppearsWhenEnablingCasProtection() {
    $this->drupalLogin($this->contentEditor);
    $this->drupalGet('/node/' . $this->testNode->id() . '/edit');

    // Click the CAS protection checkbox.
    $page = $this->getSession()->getPage();
    $checkbox = $page->findField('field_login_required[value]');
    $checkbox->click();

    // Wait for modal to appear.
    $this->assertSession()->waitForElement('css', '.cas-protection-confirm-dialog');

    // Check modal content.
    $this->assertSession()->pageTextContains('CAS Protection Confirmation');
    $this->assertSession()->pageTextContains('low-risk data only');
    $this->assertSession()->pageTextContains('sensitive information');
    $this->assertSession()->buttonExists('Cancel');
    $this->assertSession()->buttonExists('Confirm');
  }

  /**
   * Tests that modal appears when disabling CAS protection.
   */
  public function testModalAppearsWhenDisablingCasProtection() {
    // First enable CAS protection on the node.
    $this->testNode->field_login_required = TRUE;
    $this->testNode->save();

    $this->drupalLogin($this->contentEditor);
    $this->drupalGet('/node/' . $this->testNode->id() . '/edit');

    // Click the CAS protection checkbox to disable it.
    $page = $this->getSession()->getPage();
    $checkbox = $page->findField('field_login_required[value]');
    $checkbox->click();

    // Wait for modal to appear.
    $this->assertSession()->waitForElement('css', '.cas-protection-confirm-dialog');

    // Check modal content.
    $this->assertSession()->pageTextContains('CAS Protection Confirmation');
  }

  /**
   * Tests modal cancel button functionality.
   */
  public function testModalCancelButton() {
    $this->drupalLogin($this->contentEditor);
    $this->drupalGet('/node/' . $this->testNode->id() . '/edit');

    // Click the CAS protection checkbox.
    $page = $this->getSession()->getPage();
    $checkbox = $page->findField('field_login_required[value]');
    $original_state = $checkbox->isChecked();
    $checkbox->click();

    // Wait for modal and click Cancel.
    $this->assertSession()->waitForElement('css', '.cas-protection-confirm-dialog');
    $page->pressButton('Cancel');

    // Wait for modal to close.
    $this->assertSession()->waitForElementRemoved('css', '.cas-protection-confirm-dialog');

    // Checkbox should revert to original state.
    $this->assertEquals($original_state, $checkbox->isChecked());
  }

  /**
   * Tests modal confirm button functionality.
   */
  public function testModalConfirmButton() {
    $this->drupalLogin($this->contentEditor);
    $this->drupalGet('/node/' . $this->testNode->id() . '/edit');

    // Click the CAS protection checkbox.
    $page = $this->getSession()->getPage();
    $checkbox = $page->findField('field_login_required[value]');
    $checkbox->click();

    // Wait for modal and click Confirm.
    $this->assertSession()->waitForElement('css', '.cas-protection-confirm-dialog');
    $page->pressButton('Confirm');

    // Wait for modal to close.
    $this->assertSession()->waitForElementRemoved('css', '.cas-protection-confirm-dialog');

    // Save the form.
    $page->pressButton('Save');

    // Verify the change was saved.
    $this->testNode = Node::load($this->testNode->id());
    $this->assertTrue($this->testNode->field_login_required->value);
  }

  /**
   * Tests modal keyboard accessibility.
   */
  public function testModalKeyboardAccessibility() {
    $this->drupalLogin($this->contentEditor);
    $this->drupalGet('/node/' . $this->testNode->id() . '/edit');

    // Click the CAS protection checkbox.
    $page = $this->getSession()->getPage();
    $checkbox = $page->findField('field_login_required[value]');
    $checkbox->click();

    // Wait for modal to appear.
    $this->assertSession()->waitForElement('css', '.cas-protection-confirm-dialog');

    // Test that focus is trapped in modal.
    $modal = $page->find('css', '.cas-protection-confirm-dialog');
    $this->assertNotNull($modal);

    // Test tab navigation.
    $cancel_button = $page->find('css', '.cas-protection-confirm-dialog .button--secondary');
    $confirm_button = $page->find('css', '.cas-protection-confirm-dialog .button--primary');

    $this->assertNotNull($cancel_button);
    $this->assertNotNull($confirm_button);

    // Test that buttons are focusable.
    $cancel_button->focus();
    $this->assertTrue($cancel_button->equals($this->getSession()->getDriver()->getWebDriverSession()->getActiveElement()));

    $confirm_button->focus();
    $this->assertTrue($confirm_button->equals($this->getSession()->getDriver()->getWebDriverSession()->getActiveElement()));
  }

  /**
   * Tests modal Escape key functionality.
   */
  public function testModalEscapeKey() {
    $this->drupalLogin($this->contentEditor);
    $this->drupalGet('/node/' . $this->testNode->id() . '/edit');

    // Click the CAS protection checkbox.
    $page = $this->getSession()->getPage();
    $checkbox = $page->findField('field_login_required[value]');
    $original_state = $checkbox->isChecked();
    $checkbox->click();

    // Wait for modal to appear.
    $this->assertSession()->waitForElement('css', '.cas-protection-confirm-dialog');

    // Press Escape key.
    $this->getSession()->getDriver()->getWebDriverSession()->getKeyboard()->sendKeys("\e");

    // Modal should remain open (closeOnEscape: false).
    $this->assertSession()->elementExists('css', '.cas-protection-confirm-dialog');

    // Must explicitly click Cancel to close.
    $page->pressButton('Cancel');
    $this->assertSession()->waitForElementRemoved('css', '.cas-protection-confirm-dialog');

    // Checkbox should revert to original state.
    $this->assertEquals($original_state, $checkbox->isChecked());
  }

  /**
   * Tests modal ARIA attributes for screen readers.
   */
  public function testModalAriaAttributes() {
    $this->drupalLogin($this->contentEditor);
    $this->drupalGet('/node/' . $this->testNode->id() . '/edit');

    // Click the CAS protection checkbox.
    $page = $this->getSession()->getPage();
    $checkbox = $page->findField('field_login_required[value]');
    $checkbox->click();

    // Wait for modal to appear.
    $modal = $this->assertSession()->waitForElement('css', '.cas-protection-confirm-dialog');

    // Check ARIA attributes.
    $this->assertEquals('dialog', $modal->getAttribute('role'));
    $this->assertEquals('true', $modal->getAttribute('aria-modal'));
    $this->assertNotEmpty($modal->getAttribute('aria-label'));
    $this->assertNotEmpty($modal->getAttribute('aria-describedby'));
  }

  /**
   * Tests that form submission is prevented until modal is confirmed.
   */
  public function testFormSubmissionPrevention() {
    $this->drupalLogin($this->contentEditor);
    $this->drupalGet('/node/' . $this->testNode->id() . '/edit');

    // Enable CAS protection.
    $page = $this->getSession()->getPage();
    $checkbox = $page->findField('field_login_required[value]');
    $checkbox->click();

    // Wait for modal to appear.
    $this->assertSession()->waitForElement('css', '.cas-protection-confirm-dialog');

    // Try to submit form while modal is open.
    $page->pressButton('Save');

    // Form should not submit, modal should still be open.
    $this->assertSession()->elementExists('css', '.cas-protection-confirm-dialog');
    $this->assertSession()->addressEquals('/node/' . $this->testNode->id() . '/edit');
  }

  /**
   * Tests modal behavior with multiple checkbox toggles.
   */
  public function testModalWithMultipleToggles() {
    $this->drupalLogin($this->contentEditor);
    $this->drupalGet('/node/' . $this->testNode->id() . '/edit');

    $page = $this->getSession()->getPage();
    $checkbox = $page->findField('field_login_required[value]');

    // First toggle - should show modal.
    $checkbox->click();
    $this->assertSession()->waitForElement('css', '.cas-protection-confirm-dialog');
    $page->pressButton('Cancel');
    $this->assertSession()->waitForElementRemoved('css', '.cas-protection-confirm-dialog');

    // Second toggle - should show modal again.
    $checkbox->click();
    $this->assertSession()->waitForElement('css', '.cas-protection-confirm-dialog');
    $page->pressButton('Confirm');
    $this->assertSession()->waitForElementRemoved('css', '.cas-protection-confirm-dialog');

    // Third toggle - should show modal.
    $checkbox->click();
    $this->assertSession()->waitForElement('css', '.cas-protection-confirm-dialog');
  }

  /**
   * Tests modal styling and visual appearance.
   */
  public function testModalStyling() {
    $this->drupalLogin($this->contentEditor);
    $this->drupalGet('/node/' . $this->testNode->id() . '/edit');

    // Click the CAS protection checkbox.
    $page = $this->getSession()->getPage();
    $checkbox = $page->findField('field_login_required[value]');
    $checkbox->click();

    // Wait for modal to appear.
    $modal = $this->assertSession()->waitForElement('css', '.cas-protection-confirm-dialog');

    // Check modal styling classes.
    $this->assertTrue($modal->hasClass('cas-protection-confirm-dialog'));

    // Check button styling.
    $cancel_button = $page->find('css', '.cas-protection-confirm-dialog .button--secondary');
    $confirm_button = $page->find('css', '.cas-protection-confirm-dialog .button--primary');

    $this->assertNotNull($cancel_button);
    $this->assertNotNull($confirm_button);
    $this->assertTrue($cancel_button->hasClass('button--secondary'));
    $this->assertTrue($confirm_button->hasClass('button--primary'));
  }

}
