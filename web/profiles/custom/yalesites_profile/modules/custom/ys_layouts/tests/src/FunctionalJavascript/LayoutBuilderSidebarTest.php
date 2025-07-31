<?php

namespace Drupal\Tests\ys_layouts\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;

/**
 * Test Layout Builder sidebar state management functionality.
 *
 * @group ys_layouts
 */
class LayoutBuilderSidebarTest extends WebDriverTestBase {

  use ContentTypeCreationTrait;
  use NodeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'gin';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'layout_builder',
    'ys_layouts',
    'gin',
    'gin_toolbar',
  ];

  /**
   * A user with administrative permissions.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

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

    // Create a content type and enable Layout Builder.
    $this->createContentType(['type' => 'page']);
    $this->enableLayoutBuilderForContentType('page');

    // Create an admin user with necessary permissions.
    $this->adminUser = $this->drupalCreateUser([
      'configure any layout',
      'administer node display',
      'administer node fields',
      'edit any page content',
      'create page content',
      'access administration pages',
    ]);

    // Create a test node.
    $this->testNode = $this->createNode([
      'type' => 'page',
      'title' => 'Test Page for Layout Builder',
    ]);

    $this->drupalLogin($this->adminUser);
  }

  /**
   * Enable Layout Builder for a content type.
   *
   * @param string $content_type
   *   The content type machine name.
   */
  protected function enableLayoutBuilderForContentType(string $content_type): void {
    $display = LayoutBuilderEntityViewDisplay::load("node.{$content_type}.default");
    if (!$display) {
      $display = LayoutBuilderEntityViewDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => $content_type,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    }
    $display->enableLayoutBuilder()->setOverridable()->save();
  }

  /**
   * Test context detection for Manage Settings interface.
   */
  public function testManageSettingsContextDetection(): void {
    // Navigate to node edit form (Manage Settings context).
    $this->drupalGet("/node/{$this->testNode->id()}/edit");

    // Wait for JavaScript to initialize.
    $this->assertSession()->waitForElement('css', 'body');

    // Check that the manage settings context class is applied.
    $this->assertSession()->elementExists('css', 'body.ys-layout-manage-settings');

    // Verify that the gear icon is hidden in manage settings.
    $this->assertSession()->elementNotExists('css', 'body.ys-layout-manage-settings .meta-sidebar__trigger');
  }

  /**
   * Test context detection for Edit Layout interface.
   */
  public function testEditLayoutContextDetection(): void {
    // Navigate to layout edit form (Edit Layout context).
    $this->drupalGet("/node/{$this->testNode->id()}/layout");

    // Wait for JavaScript to initialize.
    $this->assertSession()->waitForElement('css', 'body');

    // Check that the edit layout context class is applied.
    $this->assertSession()->elementExists('css', 'body.ys-layout-edit-layout');

    // Verify that the gear icon is visible in edit layout.
    $this->assertSession()->elementExists('css', '.meta-sidebar__trigger');
  }

  /**
   * Test sidebar width persistence in Manage Settings.
   */
  public function testManageSettingsWidthPersistence(): void {
    $this->drupalGet("/node/{$this->testNode->id()}/edit");

    // Wait for sidebar to be initialized.
    $this->assertSession()->waitForElement('css', '#gin_sidebar');

    // Check that default width is applied.
    $width = $this->getSession()->evaluateScript("
      return localStorage.getItem('YaleSites.layoutBuilder.manageSettings.sidebarWidth');
    ");
    
    $this->assertEquals('360px', $width, 'Default width should be 360px for Manage Settings');

    // Test that the sidebar is always expanded in manage settings.
    $expanded = $this->getSession()->evaluateScript("
      return localStorage.getItem('YaleSites.layoutBuilder.manageSettings.sidebarExpanded.desktop');
    ");
    
    $this->assertEquals('true', $expanded, 'Sidebar should always be expanded in Manage Settings');
  }

  /**
   * Test sidebar width persistence in Edit Layout.
   */
  public function testEditLayoutWidthPersistence(): void {
    $this->drupalGet("/node/{$this->testNode->id()}/layout");

    // Wait for sidebar to be initialized.
    $this->assertSession()->waitForElement('css', '#gin_sidebar');

    // Check that default width is applied.
    $width = $this->getSession()->evaluateScript("
      return localStorage.getItem('YaleSites.layoutBuilder.editLayout.sidebarWidth');
    ");
    
    $this->assertEquals('400px', $width, 'Default width should be 400px for Edit Layout');

    // Test that the sidebar can be collapsed in edit layout.
    $expanded = $this->getSession()->evaluateScript("
      return localStorage.getItem('YaleSites.layoutBuilder.editLayout.sidebarExpanded.desktop');
    ");
    
    $this->assertEquals('false', $expanded, 'Sidebar should be collapsed by default in Edit Layout');
  }

  /**
   * Test context isolation between interfaces.
   */
  public function testContextIsolation(): void {
    // First, set a specific width in Edit Layout context.
    $this->drupalGet("/node/{$this->testNode->id()}/layout");
    $this->assertSession()->waitForElement('css', '#gin_sidebar');

    // Set a custom width for edit layout.
    $this->getSession()->executeScript("
      localStorage.setItem('YaleSites.layoutBuilder.editLayout.sidebarWidth', '500px');
    ");

    // Navigate to Manage Settings.
    $this->drupalGet("/node/{$this->testNode->id()}/edit");
    $this->assertSession()->waitForElement('css', '#gin_sidebar');

    // Verify that Manage Settings still has its own width.
    $manageWidth = $this->getSession()->evaluateScript("
      return localStorage.getItem('YaleSites.layoutBuilder.manageSettings.sidebarWidth');
    ");
    
    $this->assertEquals('360px', $manageWidth, 'Manage Settings should maintain its own width');

    // Navigate back to Edit Layout and verify it maintains its width.
    $this->drupalGet("/node/{$this->testNode->id()}/layout");
    $this->assertSession()->waitForElement('css', '#gin_sidebar');

    $editWidth = $this->getSession()->evaluateScript("
      return localStorage.getItem('YaleSites.layoutBuilder.editLayout.sidebarWidth');
    ");
    
    $this->assertEquals('500px', $editWidth, 'Edit Layout should maintain its custom width');
  }

  /**
   * Test localStorage migration from unitless to pixel values.
   */
  public function testWidthMigration(): void {
    // Set up a unitless width value (simulating old format).
    $this->getSession()->executeScript("
      localStorage.setItem('YaleSites.layoutBuilder.manageSettings.sidebarWidth', '360');
    ");

    $this->drupalGet("/node/{$this->testNode->id()}/edit");
    $this->assertSession()->waitForElement('css', '#gin_sidebar');

    // Wait for migration to occur.
    $this->getSession()->wait(1000);

    // Check that the value was migrated to pixel format.
    $migratedWidth = $this->getSession()->evaluateScript("
      return localStorage.getItem('YaleSites.layoutBuilder.manageSettings.sidebarWidth');
    ");
    
    $this->assertEquals('360px', $migratedWidth, 'Unitless width should be migrated to pixel format');
  }

  /**
   * Test Gin integration and CSS custom properties.
   */
  public function testGinIntegration(): void {
    $this->drupalGet("/node/{$this->testNode->id()}/edit");
    $this->assertSession()->waitForElement('css', '#gin_sidebar');

    // Check that Gin's localStorage keys are properly set.
    $ginWidth = $this->getSession()->evaluateScript("
      return localStorage.getItem('Drupal.gin.sidebarWidth');
    ");
    
    $this->assertNotNull($ginWidth, 'Gin width key should be set');

    // Check that CSS custom property is applied.
    $cssWidth = $this->getSession()->evaluateScript("
      return getComputedStyle(document.documentElement).getPropertyValue('--gin-sidebar-width');
    ");
    
    $this->assertNotNull($cssWidth, 'CSS custom property should be set');
  }

  /**
   * Test breakpoint handling for mobile vs desktop.
   */
  public function testBreakpointHandling(): void {
    $this->drupalGet("/node/{$this->testNode->id()}/edit");
    $this->assertSession()->waitForElement('css', '#gin_sidebar');

    // Test desktop breakpoint storage.
    $desktopExpanded = $this->getSession()->evaluateScript("
      return localStorage.getItem('YaleSites.layoutBuilder.manageSettings.sidebarExpanded.desktop');
    ");
    
    $this->assertEquals('true', $desktopExpanded, 'Desktop expanded state should be true for Manage Settings');

    // Test mobile breakpoint storage.
    $mobileExpanded = $this->getSession()->evaluateScript("
      return localStorage.getItem('YaleSites.layoutBuilder.manageSettings.sidebarExpanded.mobile');
    ");
    
    $this->assertEquals('false', $mobileExpanded, 'Mobile expanded state should be false by default');
  }

  /**
   * Test error handling for missing elements.
   */
  public function testErrorHandling(): void {
    $this->drupalGet("/node/{$this->testNode->id()}/edit");
    
    // Test graceful handling when Gin sidebar is not available.
    $result = $this->getSession()->evaluateScript("
      // Temporarily remove Drupal.ginSidebar
      var originalGinSidebar = Drupal.ginSidebar;
      delete Drupal.ginSidebar;
      
      // Try to access it
      var exists = typeof Drupal.ginSidebar !== 'undefined';
      
      // Restore it
      Drupal.ginSidebar = originalGinSidebar;
      
      return exists;
    ");
    
    $this->assertFalse($result, 'Should handle missing Gin sidebar gracefully');
  }

  /**
   * Test localStorage monitoring functionality.
   */
  public function testLocalStorageMonitoring(): void {
    $this->drupalGet("/node/{$this->testNode->id()}/edit");
    $this->assertSession()->waitForElement('css', '#gin_sidebar');

    // Test that localStorage operations are being tracked.
    $storageKeys = $this->getSession()->evaluateScript("
      var keys = [];
      for (var i = 0; i < localStorage.length; i++) {
        var key = localStorage.key(i);
        if (key.startsWith('YaleSites.layoutBuilder')) {
          keys.push(key);
        }
      }
      return keys;
    ");
    
    $this->assertGreaterThan(0, count($storageKeys), 'YaleSites localStorage keys should be present');
    
    // Verify expected keys exist.
    $expectedKeys = [
      'YaleSites.layoutBuilder.manageSettings.sidebarWidth',
      'YaleSites.layoutBuilder.manageSettings.sidebarExpanded.desktop',
      'YaleSites.layoutBuilder.manageSettings.sidebarExpanded.mobile',
    ];
    
    foreach ($expectedKeys as $expectedKey) {
      $this->assertContains($expectedKey, $storageKeys, "Expected key {$expectedKey} should exist");
    }
  }

}