<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\Plugin\PlatformAdminSetting\EnvironmentIndicatorPlatformAdminSetting;

/**
 * Tests the environment indicator platform admin setting plugin.
 *
 * Whether the dev/test/live banner shows is platform infrastructure, not site
 * content, so this checkbox moved off Site settings - where it was hidden from
 * site admins - onto the Platform Admin Settings page
 * (yalesites-org/YaleSites-Internal#1560). Its config key is unchanged.
 *
 * @group ys_core
 * @coversDefaultClass \Drupal\ys_core\Plugin\PlatformAdminSetting\EnvironmentIndicatorPlatformAdminSetting
 */
class EnvironmentIndicatorPlatformAdminSettingTest extends UnitTestCase {

  /**
   * Builds the plugin over the given stored config, tracking config writes.
   *
   * @param array $stored
   *   The ys_core.site values to report as already stored, keyed by the dotted
   *   config path Config::get() would be called with.
   * @param array $written
   *   Populated with each set() key/value pair, by reference.
   */
  private function plugin(array $stored, array &$written): EnvironmentIndicatorPlatformAdminSetting {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $stored[$key] ?? NULL);
    $config->method('set')->willReturnCallback(function (string $key, $value) use (&$written, $config) {
      $written[$key] = $value;
      return $config;
    });
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with(EnvironmentIndicatorPlatformAdminSetting::CONFIG_NAME)->willReturn($config);
    $factory->method('getEditable')->with(EnvironmentIndicatorPlatformAdminSetting::CONFIG_NAME)->willReturn($config);

    $plugin = new EnvironmentIndicatorPlatformAdminSetting(
      [],
      'environment_indicator',
      [],
      $factory,
      $this->createMock(AccountInterface::class),
    );
    $plugin->setStringTranslation($this->getStringTranslationStub());
    return $plugin;
  }

  /**
   * The setting is a checkbox defaulting to the stored value.
   *
   * @covers ::buildSettings
   */
  public function testBuildsCheckboxFromStoredValue(): void {
    $written = [];
    $form = $this->plugin(['environment_indicator.show' => FALSE], $written)
      ->buildSettings([], new FormState());

    $this->assertSame('checkbox', $form['environment_indicator_show']['#type']);
    $this->assertFalse($form['environment_indicator_show']['#default_value']);
  }

  /**
   * With nothing stored the indicator defaults to shown.
   *
   * The fallback carried over from SiteSettingsForm unchanged: an unset value
   * means the banner shows, so a site that never saved the form still gets it.
   *
   * @covers ::buildSettings
   */
  public function testUnsetValueDefaultsToShown(): void {
    $written = [];
    $form = $this->plugin([], $written)->buildSettings([], new FormState());

    $this->assertTrue($form['environment_indicator_show']['#default_value']);
  }

  /**
   * Submitting writes the original nested config key as a boolean.
   *
   * @covers ::submitSettings
   */
  public function testSubmitWritesTheOriginalConfigKey(): void {
    $written = [];
    $plugin = $this->plugin(['environment_indicator.show' => TRUE], $written);

    $form_state = new FormState();
    $form_state->setValue(['environment_indicator', 'environment_indicator_show'], 0);
    $form = [];
    $plugin->submitSettings($form, $form_state);

    $this->assertSame(['environment_indicator.show' => FALSE], $written);
  }

  /**
   * An unchanged resubmission writes nothing.
   *
   * The Platform Admin Settings page has one Save button for every section, so
   * this plugin's submit runs even when a platform admin only touched Site
   * Branding. Rewriting the identical value would still cost a config write and
   * a config:ys_core.site cache tag invalidation for nothing.
   *
   * @covers ::submitSettings
   */
  public function testSubmitSkipsWriteWhenNothingChanged(): void {
    $written = [];
    $plugin = $this->plugin(['environment_indicator.show' => TRUE], $written);

    $form_state = new FormState();
    $form_state->setValue(['environment_indicator', 'environment_indicator_show'], 1);
    $form = [];
    $plugin->submitSettings($form, $form_state);

    $this->assertSame([], $written);
  }

  /**
   * An unset value resubmitted as shown is not a change, so nothing is written.
   *
   * The ys_core.site config carries no environment_indicator key on a site that
   * never saved this, so the form shows the checkbox ticked from its own
   * `?? TRUE` fallback. Comparing the raw NULL against the submitted TRUE
   * read that untouched resubmission as a change, so saving any other section
   * on the page rewrote ys_core.site and invalidated its cache tag for nothing.
   *
   * @covers ::submitSettings
   */
  public function testSubmitSkipsWriteWhenUnsetValueIsResubmittedAsShown(): void {
    $written = [];
    $plugin = $this->plugin([], $written);

    $form_state = new FormState();
    $form_state->setValue(['environment_indicator', 'environment_indicator_show'], 1);
    $form = [];
    $plugin->submitSettings($form, $form_state);

    $this->assertSame([], $written);
  }

  /**
   * A legacy integer in config matches the equivalent submitted checkbox.
   *
   * SiteSettingsForm stored the raw checkbox value, so sites that saved this
   * before the move hold integer 1/0 rather than a boolean. A strict
   * comparison against the cast submission never matched those.
   *
   * @covers ::submitSettings
   */
  public function testSubmitSkipsWriteWhenStoredIntegerMatchesSubmission(): void {
    $written = [];
    $plugin = $this->plugin(['environment_indicator.show' => 1], $written);

    $form_state = new FormState();
    $form_state->setValue(['environment_indicator', 'environment_indicator_show'], 1);
    $form = [];
    $plugin->submitSettings($form, $form_state);

    $this->assertSame([], $written);
  }

}
