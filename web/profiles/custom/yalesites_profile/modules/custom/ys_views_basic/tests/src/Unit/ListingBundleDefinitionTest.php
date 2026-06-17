<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Tests the bundle-keyed listing definition (ADR DR-2/DR-4).
 *
 * The definition lives on ViewsBasicManager so the widget, formatter, and
 * migration can all reach it without depending on a form widget plugin.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\ViewsBasicManager
 *
 * @group yalesites
 */
class ListingBundleDefinitionTest extends UnitTestCase {

  /**
   * The (content type, view mode, supports_thumbnail) the definition must map.
   *
   * This pins the full 13-bundle grid (ADR DR-2/DR-4). Card and list_item
   * support the teaser image; condensed and directory do not.
   */
  const EXPECTED_BUNDLES = [
    'post_card' => ['post', 'card', TRUE],
    'post_list_item' => ['post', 'list_item', TRUE],
    'post_condensed' => ['post', 'condensed', FALSE],
    'event_card' => ['event', 'card', TRUE],
    'event_list_item' => ['event', 'list_item', TRUE],
    'event_condensed' => ['event', 'condensed', FALSE],
    'page_card' => ['page', 'card', TRUE],
    'page_list_item' => ['page', 'list_item', TRUE],
    'page_condensed' => ['page', 'condensed', FALSE],
    'profile_card' => ['profile', 'card', TRUE],
    'profile_list_item' => ['profile', 'list_item', TRUE],
    'profile_condensed' => ['profile', 'condensed', FALSE],
    'profile_directory' => ['profile', 'directory', FALSE],
  ];

  /**
   * The definition covers exactly the 13 expected listing bundles.
   *
   * @covers ::getListingBundleDefinition
   */
  public function testDefinitionCoversAllBundles() {
    $this->assertSame(
      array_keys(self::EXPECTED_BUNDLES),
      array_keys(ViewsBasicManager::LISTING_BUNDLES),
      'The listing definition contains exactly the 13 expected bundles.'
    );
  }

  /**
   * Each bundle resolves to the correct content type, view mode, and flag.
   *
   * @covers ::getContentTypeForBundle
   * @covers ::getViewModeForBundle
   * @covers ::bundleSupportsThumbnail
   */
  public function testBundleResolution() {
    foreach (self::EXPECTED_BUNDLES as $bundle => [$content_type, $view_mode, $supports_thumbnail]) {
      $this->assertSame($content_type, ViewsBasicManager::getContentTypeForBundle($bundle), "$bundle content type");
      $this->assertSame($view_mode, ViewsBasicManager::getViewModeForBundle($bundle), "$bundle view mode");
      $this->assertSame($supports_thumbnail, ViewsBasicManager::bundleSupportsThumbnail($bundle), "$bundle thumbnail flag");
    }
  }

  /**
   * The directory view mode exists only for profiles.
   *
   * @covers ::getListingBundleDefinition
   */
  public function testDirectoryIsProfileOnly() {
    $directory_bundles = array_filter(
      ViewsBasicManager::LISTING_BUNDLES,
      fn($definition) => $definition['view_mode'] === 'directory'
    );
    $this->assertSame(['profile_directory'], array_keys($directory_bundles));
  }

  /**
   * An unknown bundle throws rather than guessing a default (ADR DR-2).
   *
   * @covers ::getListingBundleDefinition
   */
  public function testUnknownBundleThrows() {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown Views Basic listing bundle "view".');
    ViewsBasicManager::getListingBundleDefinition('view');
  }

  /**
   * The migration maps (type, view_mode) to the target bundle (#1169).
   *
   * @covers ::migrationTargetBundle
   */
  public function testMigrationTargetBundle() {
    $this->assertSame('post_card', ViewsBasicManager::migrationTargetBundle('post', 'card'));
    $this->assertSame('event_condensed', ViewsBasicManager::migrationTargetBundle('event', 'condensed'));
    $this->assertSame('profile_directory', ViewsBasicManager::migrationTargetBundle('profile', 'directory'));
    // Calendar is not a listing bundle (handled by deploy_10000).
    $this->assertNull(ViewsBasicManager::migrationTargetBundle('event', 'calendar'));
    // Directory is profile-only.
    $this->assertNull(ViewsBasicManager::migrationTargetBundle('page', 'directory'));
    // Unknown type and missing values do not map.
    $this->assertNull(ViewsBasicManager::migrationTargetBundle('widget', 'card'));
    $this->assertNull(ViewsBasicManager::migrationTargetBundle(NULL, 'card'));
    $this->assertNull(ViewsBasicManager::migrationTargetBundle('post', NULL));
  }

}
