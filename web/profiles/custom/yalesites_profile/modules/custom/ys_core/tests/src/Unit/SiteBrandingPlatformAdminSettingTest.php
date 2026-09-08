<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\Plugin\PlatformAdminSetting\SiteBrandingPlatformAdminSetting;
use Drupal\ys_media\YaleSitesMediaManager;

/**
 * Tests the site branding platform admin setting plugin.
 *
 * These three fields used to live in HeaderSettingsForm, where they appeared
 * only for platform admins - so a site admin and a platform admin opening
 * Header settings saw two different forms
 * (yalesites-org/YaleSites-Internal#1560). They now live on the Platform Admin
 * Settings page, whose route permission does the gating, and they keep their
 * original ys_core.header_settings config keys so no data migration is needed.
 *
 * @group ys_core
 * @coversDefaultClass \Drupal\ys_core\Plugin\PlatformAdminSetting\SiteBrandingPlatformAdminSetting
 */
class SiteBrandingPlatformAdminSettingTest extends UnitTestCase {

  /**
   * Builds the plugin over the given stored config, tracking config writes.
   *
   * @param array $stored
   *   The ys_core.header_settings values to report as already stored.
   * @param array $written
   *   Populated with each set() key/value pair, by reference.
   * @param \Drupal\ys_media\YaleSitesMediaManager|null $media_manager
   *   The media manager double, when the test asserts on it.
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $cache_render
   *   The render cache double, when the test asserts on it.
   */
  private function plugin(array $stored, array &$written, ?YaleSitesMediaManager $media_manager = NULL, ?CacheBackendInterface $cache_render = NULL): SiteBrandingPlatformAdminSetting {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(fn (string $key) => $stored[$key] ?? NULL);
    $config->method('set')->willReturnCallback(function (string $key, $value) use (&$written, $config) {
      $written[$key] = $value;
      return $config;
    });
    $config->method('save')->willReturnSelf();

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with(SiteBrandingPlatformAdminSetting::CONFIG_NAME)->willReturn($config);
    $factory->method('getEditable')->with(SiteBrandingPlatformAdminSetting::CONFIG_NAME)->willReturn($config);

    $plugin = new SiteBrandingPlatformAdminSetting(
      [],
      'site_branding',
      [],
      $factory,
      $this->createMock(AccountInterface::class),
      $media_manager ?? $this->createMock(YaleSitesMediaManager::class),
      $cache_render ?? $this->createMock(CacheBackendInterface::class),
    );
    $plugin->setStringTranslation($this->getStringTranslationStub());
    return $plugin;
  }

  /**
   * All three moved fields are built, with their original element types.
   *
   * @covers ::buildSettings
   */
  public function testBuildsAllThreeMovedFields(): void {
    $written = [];
    $form = $this->plugin([], $written)->buildSettings([], new FormState());

    $this->assertSame('managed_file', $form['site_name_image']['#type']);
    $this->assertSame('textfield', $form['site_wide_branding_name']['#type']);
    $this->assertSame('textfield', $form['site_wide_branding_link']['#type']);
  }

  /**
   * The site name image restricts uploads via the FileExtension constraint.
   *
   * The legacy 'file_validate_extensions' array form is deprecated in Drupal
   * 10.2 and removed in Drupal 11, so asserting the constraint plugin key here
   * is what stops the SVG restriction silently disappearing on the major
   * upgrade (yalesites-org/YaleSites-Internal#1610).
   *
   * @covers ::buildSettings
   */
  public function testSiteNameImageUsesTheFileExtensionConstraint(): void {
    $written = [];
    $form = $this->plugin([], $written)->buildSettings([], new FormState());

    $this->assertSame(
      ['FileExtension' => ['extensions' => 'svg']],
      $form['site_name_image']['#upload_validators']
    );
  }

  /**
   * Defaults come from the unchanged ys_core.header_settings keys.
   *
   * @covers ::buildSettings
   */
  public function testDefaultsComeFromStoredConfig(): void {
    $written = [];
    $form = $this->plugin([
      'site_name_image' => [7],
      'site_wide_branding_name' => 'Yale School of Example',
      'site_wide_branding_link' => 'https://example.yale.edu',
    ], $written)->buildSettings([], new FormState());

    $this->assertSame([7], $form['site_name_image']['#default_value']);
    $this->assertSame('Yale School of Example', $form['site_wide_branding_name']['#default_value']);
    $this->assertSame('https://example.yale.edu', $form['site_wide_branding_link']['#default_value']);
  }

  /**
   * With nothing stored, branding falls back to the Yale University defaults.
   *
   * These fallbacks carried over from HeaderSettingsForm unchanged.
   *
   * @covers ::buildSettings
   */
  public function testUnsetBrandingFallsBackToYaleDefaults(): void {
    $written = [];
    $form = $this->plugin([], $written)->buildSettings([], new FormState());

    $this->assertNull($form['site_name_image']['#default_value']);
    $this->assertSame('Yale University', $form['site_wide_branding_name']['#default_value']);
    $this->assertSame('https://www.yale.edu', $form['site_wide_branding_link']['#default_value']);
  }

  /**
   * Submitting writes the three original config keys.
   *
   * The keys are deliberately unchanged from where HeaderSettingsForm wrote
   * them, so existing values keep working with no update hook.
   *
   * @covers ::submitSettings
   */
  public function testSubmitWritesTheOriginalConfigKeys(): void {
    $written = [];
    $plugin = $this->plugin(['site_name_image' => [7]], $written);

    $form_state = new FormState();
    $form_state->setValue(['site_branding', 'site_name_image'], [9]);
    $form_state->setValue(['site_branding', 'site_wide_branding_name'], 'Yale School of Example');
    $form_state->setValue(['site_branding', 'site_wide_branding_link'], 'https://example.yale.edu');
    $form = [];
    $plugin->submitSettings($form, $form_state);

    $this->assertSame([
      'site_name_image' => [9],
      'site_wide_branding_name' => 'Yale School of Example',
      'site_wide_branding_link' => 'https://example.yale.edu',
    ], $written);
  }

  /**
   * Submitting hands the old and new image to the media manager.
   *
   * The media manager marks the newly uploaded file permanent and releases the
   * one it replaces; that call moved here with the field, so a saved image is
   * still tracked correctly.
   *
   * @covers ::submitSettings
   */
  public function testSubmitDelegatesFilesystemHandlingToMediaManager(): void {
    $media_manager = $this->createMock(YaleSitesMediaManager::class);
    $media_manager->expects($this->once())
      ->method('handleMediaFilesystem')
      ->with([9], [7]);

    $written = [];
    $plugin = $this->plugin(['site_name_image' => [7]], $written, $media_manager);

    $form_state = new FormState();
    $form_state->setValue(['site_branding', 'site_name_image'], [9]);
    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

  /**
   * A real change flushes the render cache.
   *
   * The header reads these values through CoreTwigExtension, which bubbles no
   * cacheability, so nothing tags the rendered header with
   * config:ys_core.header_settings. HeaderSettingsForm flushed render cache on
   * every save for exactly this reason and that has to come along with the
   * fields, or a new lockup does not appear until the next cache rebuild.
   *
   * @covers ::submitSettings
   */
  public function testSubmitFlushesRenderCacheWhenSomethingChanged(): void {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->expects($this->once())->method('invalidateAll');

    $written = [];
    $plugin = $this->plugin([], $written, NULL, $cache);

    $form_state = new FormState();
    $form_state->setValue(['site_branding', 'site_wide_branding_name'], 'Yale School of Example');
    $form = [];
    $plugin->submitSettings($form, $form_state);
  }

  /**
   * An unchanged resubmission writes nothing and does not flush caches.
   *
   * The Platform Admin Settings page has a single Save button for every
   * section, so this plugin's submit runs even when a platform admin only
   * touched Beacon. Flushing the whole render cache then would be a needless
   * sitewide penalty.
   *
   * @covers ::submitSettings
   */
  public function testSubmitSkipsWriteAndCacheFlushWhenNothingChanged(): void {
    $stored = [
      'site_name_image' => [7],
      'site_wide_branding_name' => 'Yale School of Example',
      'site_wide_branding_link' => 'https://example.yale.edu',
    ];

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->expects($this->never())->method('invalidateAll');
    $media_manager = $this->createMock(YaleSitesMediaManager::class);
    $media_manager->expects($this->never())->method('handleMediaFilesystem');

    $written = [];
    $plugin = $this->plugin($stored, $written, $media_manager, $cache);

    $form_state = new FormState();
    foreach ($stored as $key => $value) {
      $form_state->setValue(['site_branding', $key], $value);
    }
    $form = [];
    $plugin->submitSettings($form, $form_state);

    $this->assertSame([], $written);
  }

  /**
   * Unset branding resubmitted as its displayed defaults is not a change.
   *
   * The ys_core.header_settings config ships site_name_image as '' and carries
   * no branding name or link at all, while the form always submits an fids list
   * plus the fallbacks it displayed. Comparing those raw shapes read an
   * untouched save as a change, so a platform admin saving only the Beacon
   * section wrote all three branding keys and flushed the whole render cache.
   * The site-header component applies the same two fallbacks with Twig's
   * |default(), so leaving them unwritten renders identically.
   *
   * @covers ::submitSettings
   */
  public function testSubmitSkipsWriteAndCacheFlushWhenUnsetBrandingIsResubmitted(): void {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->expects($this->never())->method('invalidateAll');
    $media_manager = $this->createMock(YaleSitesMediaManager::class);
    $media_manager->expects($this->never())->method('handleMediaFilesystem');

    $written = [];
    $plugin = $this->plugin(['site_name_image' => ''], $written, $media_manager, $cache);

    $form_state = new FormState();
    $form_state->setValue(['site_branding', 'site_name_image'], []);
    $form_state->setValue(['site_branding', 'site_wide_branding_name'], 'Yale University');
    $form_state->setValue(['site_branding', 'site_wide_branding_link'], 'https://www.yale.edu');
    $form = [];
    $plugin->submitSettings($form, $form_state);

    $this->assertSame([], $written);
  }

  /**
   * A first image upload over unset config still writes and flushes.
   *
   * The guard above must not swallow the case it exists to allow: the header
   * reads these values through CoreTwigExtension, which bubbles no
   * cacheability, so without the flush a new lockup would not appear until the
   * next cache rebuild.
   *
   * @covers ::submitSettings
   */
  public function testSubmitFlushesRenderCacheWhenAnImageIsUploadedOverUnsetConfig(): void {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->expects($this->once())->method('invalidateAll');
    $media_manager = $this->createMock(YaleSitesMediaManager::class);
    $media_manager->expects($this->once())->method('handleMediaFilesystem')->with([12], '');

    $written = [];
    $plugin = $this->plugin(['site_name_image' => ''], $written, $media_manager, $cache);

    $form_state = new FormState();
    $form_state->setValue(['site_branding', 'site_name_image'], [12]);
    $form_state->setValue(['site_branding', 'site_wide_branding_name'], 'Yale University');
    $form_state->setValue(['site_branding', 'site_wide_branding_link'], 'https://www.yale.edu');
    $form = [];
    $plugin->submitSettings($form, $form_state);

    $this->assertSame([12], $written['site_name_image']);
  }

}
