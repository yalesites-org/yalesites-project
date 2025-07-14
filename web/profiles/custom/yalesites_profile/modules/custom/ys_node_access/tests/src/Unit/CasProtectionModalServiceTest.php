<?php

namespace Drupal\Tests\ys_node_access\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_node_access\Service\CasProtectionModalService;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Tests for CAS protection modal service with proper mocking.
 *
 * @group yalesites
 */
class CasProtectionModalServiceTest extends UnitTestCase {

  /**
   * The CAS protection modal service.
   *
   * @var \Drupal\ys_node_access\Service\CasProtectionModalService
   */
  protected $modalService;

  /**
   * The mocked string translation service.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $stringTranslation;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Mock the string translation service.
    $this->stringTranslation = $this->createMock(TranslationInterface::class);
    $this->stringTranslation->method('translate')
      ->willReturnCallback(function ($string, array $args = [], array $options = []) {
        return strtr($string, $args);
      });

    $this->modalService = new CasProtectionModalService();
    $this->modalService->setStringTranslation($this->stringTranslation);
  }

  /**
   * Tests that shouldShowModal works correctly.
   *
   * @covers \Drupal\ys_node_access\Service\CasProtectionModalService::shouldShowModal
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
   * Tests modal content includes required data security messaging.
   *
   * @covers \Drupal\ys_node_access\Service\CasProtectionModalService::getModalContent
   */
  public function testModalContentIncludesDataSecurityMessage() {
    $content = $this->modalService->getModalContent();

    $this->assertStringContainsString('low-risk data only', $content);
    $this->assertStringContainsString('sensitive information', $content);
  }

  /**
   * Tests modal buttons configuration.
   *
   * @covers \Drupal\ys_node_access\Service\CasProtectionModalService::getModalButtons
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
   * @covers \Drupal\ys_node_access\Service\CasProtectionModalService::getModalConfig
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
   * Tests accessibility attributes for modal.
   *
   * @covers \Drupal\ys_node_access\Service\CasProtectionModalService::getModalAccessibilityAttributes
   */
  public function testModalAccessibilityAttributes() {
    $attributes = $this->modalService->getModalAccessibilityAttributes();

    $this->assertEquals('dialog', $attributes['role']);
    $this->assertEquals('CAS Protection Confirmation', $attributes['aria-label']);
    $this->assertTrue($attributes['aria-modal']);
    $this->assertNotEmpty($attributes['aria-describedby']);
  }

}
