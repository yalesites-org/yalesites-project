<?php

namespace Drupal\Tests\ys_node_access\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_node_access\Service\CasProtectionModalService;

/**
 * Tests for CAS protection confirmation modal logic.
 *
 * @group yalesites
 */
class CasProtectionModalTest extends UnitTestCase {

  /**
   * The CAS protection modal service.
   *
   * @var \Drupal\ys_node_access\Service\CasProtectionModalService
   */
  protected $modalService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // This will fail until we create the service.
    $this->modalService = new CasProtectionModalService();
  }

  /**
   * Tests that modal content includes required data security messaging.
   *
   * @covers \Drupal\ys_node_access\CasProtectionModalService::getModalContent
   */
  public function testModalContentIncludesDataSecurityMessage() {
    // This will fail until we implement the service method.
    $content = $this->modalService->getModalContent();

    $this->assertStringContainsString('low-risk data only', $content);
    $this->assertStringContainsString('sensitive information', $content);
    $this->assertStringContainsString('should not be published', $content);
  }

  /**
   * Tests that modal content uses plain language.
   *
   * @covers \Drupal\ys_node_access\CasProtectionModalService::getModalContent
   */
  public function testModalContentUsesPlainLanguage() {
    $content = $this->modalService->getModalContent();

    // Should not contain technical jargon.
    $this->assertStringNotContainsString('authentication', $content);
    $this->assertStringNotContainsString('CAS protocol', $content);
    $this->assertStringNotContainsString('SSO', $content);

    // Should use accessible language.
    $this->assertStringContainsString('login requirements', $content);
    $this->assertStringContainsString('Yale', $content);
  }

  /**
   * Tests modal button configuration.
   *
   * @covers \Drupal\ys_node_access\CasProtectionModalService::getModalButtons
   */
  public function testModalButtonConfiguration() {
    $buttons = $this->modalService->getModalButtons();

    $this->assertCount(2, $buttons);

    // Test Cancel button.
    $this->assertEquals('Cancel', $buttons[0]['text']);
    $this->assertStringContainsString('button--secondary', $buttons[0]['class']);

    // Test Confirm button.
    $this->assertEquals('Confirm', $buttons[1]['text']);
    $this->assertStringContainsString('button--primary', $buttons[1]['class']);
  }

  /**
   * Tests modal configuration options.
   *
   * @covers \Drupal\ys_node_access\CasProtectionModalService::getModalConfig
   */
  public function testModalConfiguration() {
    $config = $this->modalService->getModalConfig();

    $this->assertEquals('CAS Protection Confirmation', $config['title']);
    $this->assertEquals(600, $config['width']);
    $this->assertTrue($config['resizable']);
    $this->assertFalse($config['closeOnEscape']);
    $this->assertStringContainsString('cas-protection-confirm-dialog', $config['dialogClass']);
  }

  /**
   * Tests modal trigger conditions.
   *
   * @covers \Drupal\ys_node_access\CasProtectionModalService::shouldShowModal
   */
  public function testModalTriggerConditions() {
    // Modal should trigger when enabling CAS protection.
    $this->assertTrue($this->modalService->shouldShowModal(FALSE, TRUE));

    // Modal should trigger when disabling CAS protection.
    $this->assertTrue($this->modalService->shouldShowModal(TRUE, FALSE));

    // Modal should not trigger when no change occurs.
    $this->assertFalse($this->modalService->shouldShowModal(TRUE, TRUE));
    $this->assertFalse($this->modalService->shouldShowModal(FALSE, FALSE));
  }

  /**
   * Tests accessibility attributes for modal.
   *
   * @covers \Drupal\ys_node_access\CasProtectionModalService::getModalAccessibilityAttributes
   */
  public function testModalAccessibilityAttributes() {
    $attributes = $this->modalService->getModalAccessibilityAttributes();

    $this->assertEquals('dialog', $attributes['role']);
    $this->assertEquals('CAS Protection Confirmation', $attributes['aria-label']);
    $this->assertTrue($attributes['aria-modal']);
    $this->assertNotEmpty($attributes['aria-describedby']);
  }

}
