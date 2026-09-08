<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\ys_core\Traits\ProtectedPropertyTrait;
use Drupal\ys_core\Form\SiteSettingsForm;
use Drupal\ys_media\YaleSitesMediaManager;

/**
 * Tests the site settings form's submit handler.
 *
 * The environment indicator toggle moved to the Platform Admin Settings page
 * (yalesites-org/YaleSites-Internal#1560), so this form no longer renders it
 * and must never write its config key - otherwise a site admin's ordinary save
 * would overwrite whatever a platform admin set.
 *
 * @group ys_core
 * @coversDefaultClass \Drupal\ys_core\Form\SiteSettingsForm
 */
class SiteSettingsFormTest extends UnitTestCase {

  use ProtectedPropertyTrait;

  /**
   * A routine save still writes the site settings this form owns.
   *
   * @covers ::submitForm
   */
  public function testSubmitSavesTheFieldsTheFormStillOwns(): void {
    $written = $this->submit([
      'font_pairing' => 'mallory',
      'cas_app_name' => 'yalesites',
      'google_site_verification' => 'token',
    ]);

    $this->assertSame('mallory', $written['ys_core.site']['font_pairing']);
    $this->assertSame('yalesites', $written['ys_core.site']['cas_app_name']);
    $this->assertSame('token', $written['ys_core.site']['seo.google_site_verification']);
  }

  /**
   * The form never writes the moved environment indicator key.
   *
   * @covers ::submitForm
   */
  public function testSubmitDoesNotWriteMovedEnvironmentIndicatorKey(): void {
    $written = $this->submit(['environment_indicator_show' => 0]);

    $this->assertArrayNotHasKey('environment_indicator.show', $written['ys_core.site']);
  }

  /**
   * Submits the form and returns the config writes, keyed by config name.
   *
   * @param array $values
   *   Submitted form values keyed by element name. The custom vocab name
   *   defaults to the value the stubbed vocabulary already has, so the
   *   vocabulary-rename branch stays out of the way.
   *
   * @return array[]
   *   Written values keyed by config name, then config key.
   */
  private function submit(array $values): array {
    $written = [];
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->willReturnCallback(function (string $name) use (&$written) {
      $written[$name] ??= [];
      $config = $this->createMock(Config::class);
      // The vocabulary rename branch compares this against the submitted name.
      $config->method('get')->willReturnCallback(fn (string $key) => $key === 'name' ? 'Custom Vocab' : NULL);
      $config->method('set')->willReturnCallback(function (string $key, $value) use (&$written, $name, $config) {
        $written[$name][$key] = $value;
        return $config;
      });
      $config->method('save')->willReturnSelf();
      return $config;
    });

    $form_state = new FormState();
    $form_state->setValues($values + ['custom_vocab_name' => 'Custom Vocab']);

    $form_object = (new \ReflectionClass(SiteSettingsForm::class))->newInstanceWithoutConstructor();
    $this->setProtectedProperty($form_object, 'configFactory', $factory);
    $this->setProtectedProperty($form_object, 'ysMediaManager', $this->createMock(YaleSitesMediaManager::class));
    $this->setProtectedProperty($form_object, 'entityTypeManager', $this->createMock(EntityTypeManagerInterface::class));
    $this->setProtectedProperty($form_object, 'cacheDiscovery', $this->createMock(CacheBackendInterface::class));
    $this->setProtectedProperty($form_object, 'messenger', $this->createMock(MessengerInterface::class));
    $this->setProtectedProperty($form_object, 'stringTranslation', $this->getStringTranslationStub());

    $form = [];
    $form_object->submitForm($form, $form_state);

    return $written;
  }

}
