<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\ys_core\Traits\ProtectedPropertyTrait;
use Drupal\ys_core\Form\HeaderSettingsForm;

/**
 * Tests the header settings form's submit handler.
 *
 * The Site Name Image and sitewide branding fields moved to the Platform Admin
 * Settings page (yalesites-org/YaleSites-Internal#1560), so this form no longer
 * renders them and must never write their config keys. It previously needed a
 * ys_core_allow_secret_items() guard around those writes purely to stop a site
 * admin's ordinary save from overwriting them with NULL; the move deletes the
 * guard rather than relocating it, and these tests are what keep that safe.
 *
 * @group ys_core
 * @coversDefaultClass \Drupal\ys_core\Form\HeaderSettingsForm
 */
class HeaderSettingsFormTest extends UnitTestCase {

  use ProtectedPropertyTrait;

  /**
   * The config keys that moved to the Platform Admin Settings page.
   */
  private const MOVED_KEYS = [
    'site_name_image',
    'site_wide_branding_name',
    'site_wide_branding_link',
  ];

  /**
   * A routine save writes the header settings this form still owns.
   *
   * @covers ::submitForm
   */
  public function testSubmitSavesTheFieldsTheFormStillOwns(): void {
    $written = $this->submit([
      'header_variation' => 'mega',
      'nav_position' => 'center',
      'enable_search_form' => 1,
      'enable_cas_search' => 1,
      'enable_all_yale_search' => 0,
    ]);

    $this->assertSame('mega', $written['header_variation']);
    $this->assertSame('center', $written['nav_position']);
    $this->assertSame(1, $written['search.enable_search_form']);
    $this->assertSame(1, $written['search.enable_cas_search']);
    $this->assertSame(0, $written['search.enable_all_yale_search']);
  }

  /**
   * The form never writes the moved platform-admin-owned branding keys.
   *
   * This is the regression guard for the move: any site admin saving Header
   * settings used to be one missing conditional away from NULLing the site's
   * branding name, link, and logo.
   *
   * @covers ::submitForm
   */
  public function testSubmitDoesNotWriteMovedBrandingKeys(): void {
    $written = $this->submit(['header_variation' => 'basic']);

    foreach (self::MOVED_KEYS as $key) {
      $this->assertArrayNotHasKey($key, $written);
    }
  }

  /**
   * Submits the form with the given values and returns the saved config map.
   *
   * @param array $values
   *   Submitted form values keyed by element name.
   *
   * @return array
   *   The keys written to ys_core.header_settings mapped to their values.
   */
  private function submit(array $values): array {
    $written = [];
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturn(NULL);
    $config->method('set')->willReturnCallback(function (string $key, $value) use (&$written, $config) {
      $written[$key] = $value;
      return $config;
    });
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $factory->method('getEditable')->willReturn($config);

    $form_state = new FormState();
    $form_state->setValues($values);

    $form_object = (new \ReflectionClass(HeaderSettingsForm::class))->newInstanceWithoutConstructor();
    $this->setProtectedProperty($form_object, 'configFactory', $factory);
    $this->setProtectedProperty($form_object, 'cacheRender', $this->createMock(CacheBackendInterface::class));
    $this->setProtectedProperty($form_object, 'messenger', $this->createMock(MessengerInterface::class));
    $this->setProtectedProperty($form_object, 'stringTranslation', $this->getStringTranslationStub());

    $form = [];
    $form_object->submitForm($form, $form_state);

    return $written;
  }

}
