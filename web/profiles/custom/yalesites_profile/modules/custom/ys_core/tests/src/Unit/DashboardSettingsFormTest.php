<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\ys_core\Traits\ProtectedPropertyTrait;
use Drupal\ys_core\DashboardAnnouncements;
use Drupal\ys_core\Form\DashboardSettingsForm;

/**
 * Tests the dashboard settings form after the source section moved off it.
 *
 * The announcements source fields moved to the Platform Admin Settings page
 * (yalesites-org/YaleSites-Internal#1560), so this form no longer renders them
 * and must never write their config keys. It previously hid the section behind
 * #access and needed a matching conditional in submitForm() purely to stop a
 * site admin's ordinary save from clobbering the platform's setting; the move
 * deletes both rather than relocating them, and these tests are what keep that
 * safe.
 *
 * @group ys_core
 * @coversDefaultClass \Drupal\ys_core\Form\DashboardSettingsForm
 */
class DashboardSettingsFormTest extends UnitTestCase {

  use ProtectedPropertyTrait;

  /**
   * The config keys that moved to the Platform Admin Settings page.
   */
  private const MOVED_KEYS = [
    'announcements_source_enabled',
    'announcements_source_term',
  ];

  /**
   * The form renders only the consume-side section site admins own.
   *
   * @covers ::buildForm
   */
  public function testBuildOmitsTheMovedSourceSection(): void {
    $form = $this->build();

    $this->assertArrayHasKey('announcements', $form);
    $this->assertArrayNotHasKey('source', $form);
  }

  /**
   * A routine save writes the settings this form still owns.
   *
   * @covers ::submitForm
   */
  public function testSubmitSavesTheFieldsTheFormStillOwns(): void {
    $written = $this->submit([
      'announcements_enabled' => 1,
      'announcements_limit' => '5',
      'announcements_max_age' => '900',
    ]);

    $this->assertTrue($written['announcements_enabled']);
    $this->assertSame(5, $written['announcements_limit']);
    $this->assertSame(900, $written['announcements_max_age']);
  }

  /**
   * The form never writes the moved platform-admin-owned source keys.
   *
   * This is the regression guard for the move: with the #access gate and its
   * matching submit conditional both gone, a site admin's save must simply not
   * touch these keys rather than rely on a conditional to skip them.
   *
   * @covers ::submitForm
   */
  public function testSubmitDoesNotWriteMovedSourceKeys(): void {
    $written = $this->submit([
      'announcements_enabled' => 1,
      'announcements_limit' => '3',
      'announcements_max_age' => '3600',
      // Present in form state as they would be on a crafted POST, and still
      // never written.
      'announcements_source_enabled' => 1,
      'announcements_source_term' => 'Injected',
    ]);

    foreach (self::MOVED_KEYS as $key) {
      $this->assertArrayNotHasKey($key, $written);
    }
  }

  /**
   * Builds the form and returns its render array.
   */
  private function build(): array {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturn(NULL);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $factory->method('getEditable')->willReturn($config);

    $form_object = $this->formObject($factory);

    return $form_object->buildForm([], new FormState());
  }

  /**
   * Submits the form with the given values and returns the saved config map.
   *
   * @param array $values
   *   Submitted form values keyed by element name.
   *
   * @return array
   *   The keys written to ys_core.dashboard_settings mapped to their values.
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

    $form = [];
    $this->formObject($factory)->submitForm($form, $form_state);

    return $written;
  }

  /**
   * A form object wired with just the collaborators these paths touch.
   */
  private function formObject(ConfigFactoryInterface $factory): DashboardSettingsForm {
    $form_object = (new \ReflectionClass(DashboardSettingsForm::class))->newInstanceWithoutConstructor();
    $this->setProtectedProperty($form_object, 'configFactory', $factory);
    $this->setProtectedProperty($form_object, 'announcements', $this->createMock(DashboardAnnouncements::class));
    $this->setProtectedProperty($form_object, 'messenger', $this->createMock(MessengerInterface::class));
    $this->setProtectedProperty($form_object, 'stringTranslation', $this->getStringTranslationStub());

    return $form_object;
  }

}
