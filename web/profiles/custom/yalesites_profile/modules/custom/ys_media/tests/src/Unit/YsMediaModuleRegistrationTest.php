<?php

namespace Drupal\Tests\ys_media\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the ys_media module's registration in the profile's synced config.
 *
 * Hard constraint 8 on yalesites-org/YaleSites-Internal#579: new modules are
 * enabled through config/sync, not the profile info file. This one line is
 * load-bearing in a way that fails silently. Drop it and ys_media never gets
 * enabled on existing sites; ys_core's optional container reference to
 * ys_media.media_manager then resolves to NULL permanently, and the favicons
 * and the site-name-image SVG disappear with no error raised anywhere.
 *
 * @group ys_media
 * @group yalesites
 */
class YsMediaModuleRegistrationTest extends UnitTestCase {

  /**
   * Absolute path to the profile's exported config/sync directory.
   */
  protected function configSyncDir(): string {
    return dirname(__DIR__, 6) . '/config/sync';
  }

  /**
   * The module is listed in the exported core.extension.
   */
  public function testModuleIsRegisteredInSyncedCoreExtension(): void {
    $file = $this->configSyncDir() . '/core.extension.yml';
    $this->assertFileExists($file);

    $modules = Yaml::parseFile($file)['module'] ?? [];
    $this->assertArrayHasKey(
      'ys_media',
      $modules,
      'ys_media must be listed in the profile config/sync core.extension.yml so drush deploy enables it.'
    );
  }

}
