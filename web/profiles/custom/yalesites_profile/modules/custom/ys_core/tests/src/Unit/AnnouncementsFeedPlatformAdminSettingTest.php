<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\DashboardAnnouncements;
use Drupal\ys_core\Plugin\PlatformAdminSetting\AnnouncementsFeedPlatformAdminSetting;

/**
 * Tests the dashboard announcements feed platform admin setting plugin.
 *
 * @group ys_core
 * @coversDefaultClass \Drupal\ys_core\Plugin\PlatformAdminSetting\AnnouncementsFeedPlatformAdminSetting
 */
class AnnouncementsFeedPlatformAdminSettingTest extends UnitTestCase {

  /**
   * Builds the plugin with the given collaborators.
   */
  private function plugin(ConfigFactoryInterface $config_factory, ?DashboardAnnouncements $announcements = NULL): AnnouncementsFeedPlatformAdminSetting {
    $plugin = new AnnouncementsFeedPlatformAdminSetting(
      [],
      'announcements_feed',
      [],
      $config_factory,
      $this->createMock(AccountInterface::class),
      $announcements ?? $this->createMock(DashboardAnnouncements::class),
    );
    $plugin->setStringTranslation($this->getStringTranslationStub());
    return $plugin;
  }

  /**
   * A DashboardAnnouncements double reporting the given effective feed URL.
   */
  private function announcementsWithEffectiveUrl(string $url): DashboardAnnouncements {
    $announcements = $this->createMock(DashboardAnnouncements::class);
    $announcements->method('getEffectiveFeedUrl')->willReturn($url);
    return $announcements;
  }

  /**
   * Builds a config factory whose editable config records set() calls.
   *
   * @param array $tracked
   *   Populated with each set() key/value pair, by reference.
   * @param string $stored
   *   The value get('announcements_feed_url') should report as already
   *   stored, simulating the config state before this submit.
   */
  private function trackingConfigFactory(array &$tracked, string $stored = ''): ConfigFactoryInterface {
    $config = $this->createMock(Config::class);
    $config->method('get')->with('announcements_feed_url')->willReturn($stored);
    $config->method('set')->willReturnCallback(function (string $key, $value) use (&$tracked, $config) {
      $tracked[$key] = $value;
      return $config;
    });
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_core.dashboard_settings')->willReturn($config);
    return $factory;
  }

  /**
   * The field is a core #type => url element.
   *
   * Format validation and its inline error are handled by Drupal core, not
   * custom code.
   *
   * @covers ::buildSettings
   */
  public function testFieldIsUrlType(): void {
    $plugin = $this->plugin($this->createMock(ConfigFactoryInterface::class));

    $form = $plugin->buildSettings([], new FormState());

    $this->assertSame('url', $form['announcements_feed_url']['#type']);
  }

  /**
   * The field's default value is whatever DashboardAnnouncements reports.
   *
   * That shared accessor is also used to actually fetch the feed, so the
   * displayed default can never disagree with what the dashboard uses.
   *
   * @covers ::buildSettings
   */
  public function testDefaultValueShowsProductionUrlWhenUnset(): void {
    $plugin = $this->plugin(
      $this->createMock(ConfigFactoryInterface::class),
      $this->announcementsWithEffectiveUrl(DashboardAnnouncements::PLATFORM_FEED_URL),
    );

    $form = $plugin->buildSettings([], new FormState());

    $this->assertSame(DashboardAnnouncements::PLATFORM_FEED_URL, $form['announcements_feed_url']['#default_value']);
  }

  /**
   * A stored override is shown verbatim.
   *
   * @covers ::buildSettings
   */
  public function testDefaultValueShowsStoredOverride(): void {
    $plugin = $this->plugin(
      $this->createMock(ConfigFactoryInterface::class),
      $this->announcementsWithEffectiveUrl('https://rc-test.example.edu/api/dashboard-announcements'),
    );

    $form = $plugin->buildSettings([], new FormState());

    $this->assertSame('https://rc-test.example.edu/api/dashboard-announcements', $form['announcements_feed_url']['#default_value']);
  }

  /**
   * Submitting a distinct URL stores it and clears the feed cache.
   *
   * @covers ::submitSettings
   */
  public function testSubmitStoresOverrideAndClearsCache(): void {
    $set = [];
    $factory = $this->trackingConfigFactory($set, '');

    $announcements = $this->createMock(DashboardAnnouncements::class);
    $announcements->expects($this->once())->method('clearCache');

    $plugin = $this->plugin($factory, $announcements);

    $form_state = new FormState();
    $form_state->setValue(['announcements_feed', 'announcements_feed_url'], 'https://rc-test.example.edu/api/dashboard-announcements');
    $form = [];
    $plugin->submitSettings($form, $form_state);

    $this->assertSame('https://rc-test.example.edu/api/dashboard-announcements', $set['announcements_feed_url']);
  }

  /**
   * Submitting the shown production default normalizes back to blank.
   *
   * Replacing a real prior override with the displayed production default
   * must store blank, not the literal production URL - that would pin an
   * explicit override into config/sync for a value that is supposed to mean
   * "no override" (yalesites-org/YaleSites-Internal#1487).
   *
   * @covers ::submitSettings
   */
  public function testSubmitNormalizesProductionUrlBackToBlank(): void {
    $set = [];
    $factory = $this->trackingConfigFactory($set, 'https://old-override.example.edu/api/dashboard-announcements');

    $announcements = $this->createMock(DashboardAnnouncements::class);
    $announcements->expects($this->once())->method('clearCache');

    $plugin = $this->plugin($factory, $announcements);

    $form_state = new FormState();
    $form_state->setValue(['announcements_feed', 'announcements_feed_url'], DashboardAnnouncements::PLATFORM_FEED_URL);
    $form = [];
    $plugin->submitSettings($form, $form_state);

    $this->assertSame('', $set['announcements_feed_url']);
  }

  /**
   * Clearing an existing override stores blank.
   *
   * @covers ::submitSettings
   */
  public function testSubmitClearingExistingOverrideStoresBlank(): void {
    $set = [];
    $factory = $this->trackingConfigFactory($set, 'https://old-override.example.edu/api/dashboard-announcements');

    $announcements = $this->createMock(DashboardAnnouncements::class);
    $announcements->expects($this->once())->method('clearCache');

    $plugin = $this->plugin($factory, $announcements);

    $form_state = new FormState();
    $form_state->setValue(['announcements_feed', 'announcements_feed_url'], '');
    $form = [];
    $plugin->submitSettings($form, $form_state);

    $this->assertSame('', $set['announcements_feed_url']);
  }

  /**
   * A resubmission with no effective change skips the write and cache clear.
   *
   * Resaving the page without touching this field submits the displayed
   * production default, which normalizes to the same blank value already
   * stored. That must not write config or clear the feed cache - doing so
   * would force the next dashboard toolbar render to pay a synchronous
   * refetch of the announcements feed for no reason.
   *
   * @covers ::submitSettings
   */
  public function testSubmitSkipsWriteAndCacheClearWhenUnchanged(): void {
    $config = $this->createMock(Config::class);
    $config->method('get')->with('announcements_feed_url')->willReturn('');
    $config->expects($this->never())->method('set');
    $config->expects($this->never())->method('save');

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->with('ys_core.dashboard_settings')->willReturn($config);

    $announcements = $this->createMock(DashboardAnnouncements::class);
    $announcements->expects($this->never())->method('clearCache');

    $plugin = $this->plugin($factory, $announcements);

    $form_state = new FormState();
    $form_state->setValue(['announcements_feed', 'announcements_feed_url'], DashboardAnnouncements::PLATFORM_FEED_URL);
    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

}
