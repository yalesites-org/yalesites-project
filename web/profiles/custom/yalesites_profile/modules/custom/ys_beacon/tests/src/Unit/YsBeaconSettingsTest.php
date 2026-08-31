<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Form\YsBeaconSettings;

/**
 * Tests the site Beacon settings form's submit handler.
 *
 * Enabling the chat widget and driving indexing moved to the platform admin
 * settings page (yalesites-org/YaleSites-Internal#1430), so the site form now
 * persists only the presentation settings. It must never write enable_chat -
 * doing so would overwrite the value a platform admin set - nor the
 * platform-admin-managed enable_metadata_fields.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Form\YsBeaconSettings
 */
class YsBeaconSettingsTest extends UnitTestCase {

  /**
   * The form saves the presentation settings the site admin can edit.
   *
   * @covers ::submitForm
   */
  public function testSubmitSavesPresentationSettings(): void {
    $saved = $this->submit([
      'floating_button' => TRUE,
      'floating_button_text' => 'Ask Beacon',
      'prompts' => ['One', '', 'Two'],
      'disclaimer' => 'Be careful.',
      'footer' => 'Footer text.',
    ]);

    $this->assertTrue($saved['floating_button']);
    $this->assertSame('Ask Beacon', $saved['floating_button_text']);
    $this->assertSame(YsBeaconSettings::FLOATING_BUTTON_ICON, $saved['floating_button_icon']);
    // Empty prompts are filtered out and the list is re-indexed.
    $this->assertSame(['One', 'Two'], $saved['prompts']);
    $this->assertSame('Be careful.', $saved['disclaimer']);
    $this->assertSame('Footer text.', $saved['footer']);
  }

  /**
   * The form never writes the platform-admin-managed chat/indexing settings.
   *
   * Enable_chat is owned by the platform admin settings page; writing it here
   * would overwrite the platform admin's value. enable_metadata_fields is
   * likewise a platform-admin concern and must be left untouched.
   *
   * @covers ::submitForm
   */
  public function testSubmitDoesNotWritePlatformAdminSettings(): void {
    $saved = $this->submit([
      'floating_button' => FALSE,
      'floating_button_text' => 'Beacon Chat',
      'prompts' => [],
      'disclaimer' => '',
      'footer' => '',
    ]);

    $this->assertArrayNotHasKey('enable_chat', $saved);
    $this->assertArrayNotHasKey('enable_metadata_fields', $saved);
  }

  /**
   * Submits the form with the given values and returns the saved config map.
   *
   * @param array $values
   *   Submitted form values keyed by element name.
   *
   * @return array
   *   The keys written to ys_beacon.settings mapped to their saved values.
   */
  private function submit(array $values): array {
    $saved = [];
    $config = $this->createMock(Config::class);
    $config->method('set')->willReturnCallback(function (string $key, $value) use (&$saved, $config) {
      $saved[$key] = $value;
      return $config;
    });
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $factory->method('getEditable')->willReturn($config);

    $form_state = $this->createMock(FormStateInterface::class);
    $form_state->method('getValue')->willReturnCallback(fn (string $key) => $values[$key] ?? NULL);

    $form = (new \ReflectionClass(YsBeaconSettings::class))->newInstanceWithoutConstructor();
    $this->setProtected($form, 'configFactory', $factory);
    $this->setProtected($form, 'messenger', $this->createMock(MessengerInterface::class));
    $this->setProtected($form, 'stringTranslation', $this->getStringTranslationStub());

    $form_array = [];
    $form->submitForm($form_array, $form_state);

    return $saved;
  }

  /**
   * Sets a protected/inherited property on an object via reflection.
   */
  private function setProtected(object $object, string $property, mixed $value): void {
    $reflection = new \ReflectionProperty($object, $property);
    $reflection->setAccessible(TRUE);
    $reflection->setValue($object, $value);
  }

}
