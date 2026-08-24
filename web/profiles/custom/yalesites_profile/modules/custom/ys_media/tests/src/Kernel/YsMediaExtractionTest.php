<?php

namespace Drupal\Tests\ys_media\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ys_media\YaleSitesMediaManager;

/**
 * Pins the service-container contract of the ys_media extraction.
 *
 * Phase 1 of yalesites-org/YaleSites-Internal#579 moved YaleSitesMediaManager
 * out of ys_core. Its behaviour is pinned by the characterization tests that
 * moved with it (YaleSitesMediaManagerTest, in the Unit suite). What needs a
 * booted container -- and so lives here rather than there -- is the backwards
 * compatibility alias.
 *
 * @group ys_media
 * @group yalesites
 */
class YsMediaExtractionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'file', 'image', 'user', 'ys_media'];

  /**
   * The old ys_core service id still resolves, to the very same object.
   *
   * Hard constraint 3 on the issue: "Moved services keep their old IDs as
   * aliases." Any site-specific code, contrib integration, or older branch
   * calling \Drupal::service('ys_core.media_manager') keeps working after the
   * class changes namespace.
   *
   * assertSame is the load-bearing assertion: a duplicated service definition
   * rather than a real alias would satisfy assertInstanceOf while handing
   * ys_core and ys_media two separate objects.
   */
  public function testOldYsCoreServiceIdIsAnAliasOfTheMovedService(): void {
    $manager = $this->container->get('ys_media.media_manager');

    $this->assertInstanceOf(YaleSitesMediaManager::class, $manager);
    $this->assertSame(
      $manager,
      $this->container->get('ys_core.media_manager'),
      'ys_core.media_manager must be an alias of ys_media.media_manager, not a second definition.'
    );
  }

}
