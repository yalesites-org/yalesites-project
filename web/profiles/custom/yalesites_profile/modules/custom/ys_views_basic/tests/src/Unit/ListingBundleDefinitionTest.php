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
   * The capability row each bundle must map to.
   *
   * `[content type, view mode, supports_thumbnail, supports_card_size]`.
   * This pins the full 17-bundle grid (ADR DR-2/DR-4). Card and list_item
   * support the teaser image; condensed and directory do not. Only the card
   * grid takes a card-size dial (#1648) — the other design options lay
   * themselves out. Resources (#1723) keep exactly the options their old
   * resource_view widget offered: a teaser image on every mode but condensed,
   * and no card-size dial, which that widget never had.
   */
  const EXPECTED_BUNDLES = [
    'post_card' => ['post', 'card', TRUE, TRUE],
    'post_list_item' => ['post', 'list_item', TRUE, FALSE],
    'post_condensed' => ['post', 'condensed', FALSE, FALSE],
    'event_card' => ['event', 'card', TRUE, TRUE],
    'event_list_item' => ['event', 'list_item', TRUE, FALSE],
    'event_condensed' => ['event', 'condensed', FALSE, FALSE],
    'page_card' => ['page', 'card', TRUE, TRUE],
    'page_list_item' => ['page', 'list_item', TRUE, FALSE],
    'page_condensed' => ['page', 'condensed', FALSE, FALSE],
    'profile_card' => ['profile', 'card', TRUE, TRUE],
    'profile_list_item' => ['profile', 'list_item', TRUE, FALSE],
    'profile_condensed' => ['profile', 'condensed', FALSE, FALSE],
    'profile_directory' => ['profile', 'directory', FALSE, FALSE],
    'resource_card' => ['resource', 'card', TRUE, FALSE],
    'resource_portrait_grid' => ['resource', 'portrait_grid', TRUE, FALSE],
    'resource_list_item' => ['resource', 'list_item', TRUE, FALSE],
    'resource_condensed' => ['resource', 'condensed', FALSE, FALSE],
  ];

  /**
   * The definition covers exactly the 17 expected listing bundles.
   *
   * @covers ::getListingBundleDefinition
   */
  public function testDefinitionCoversAllBundles() {
    $this->assertSame(
      array_keys(self::EXPECTED_BUNDLES),
      array_keys(ViewsBasicManager::LISTING_BUNDLES),
      'The listing definition contains exactly the 17 expected bundles.'
    );
  }

  /**
   * Each bundle resolves to the correct content type, view mode, and flag.
   *
   * @covers ::getContentTypeForBundle
   * @covers ::getViewModeForBundle
   * @covers ::bundleSupportsThumbnail
   * @covers ::bundleSupportsCardSize
   */
  public function testBundleResolution() {
    foreach (self::EXPECTED_BUNDLES as $bundle => [$type, $view_mode, $thumbnail, $card_size]) {
      $this->assertSame($type, ViewsBasicManager::getContentTypeForBundle($bundle), "$bundle content type");
      $this->assertSame($view_mode, ViewsBasicManager::getViewModeForBundle($bundle), "$bundle view mode");
      $this->assertSame($thumbnail, ViewsBasicManager::bundleSupportsThumbnail($bundle), "$bundle thumbnail flag");
      $this->assertSame($card_size, ViewsBasicManager::bundleSupportsCardSize($bundle), "$bundle card-size flag");
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
   * The portrait grid view mode exists only for resources (#1723).
   *
   * @covers ::getListingBundleDefinition
   */
  public function testPortraitGridIsResourceOnly() {
    $portrait_bundles = array_filter(
      ViewsBasicManager::LISTING_BUNDLES,
      fn($definition) => $definition['view_mode'] === 'portrait_grid'
    );
    $this->assertSame(['resource_portrait_grid'], array_keys($portrait_bundles));
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
    $this->assertSame('resource_portrait_grid', ViewsBasicManager::migrationTargetBundle('resource', 'portrait_grid'));
    $this->assertSame('resource_condensed', ViewsBasicManager::migrationTargetBundle('resource', 'condensed'));
    // Portrait grid is resource-only.
    $this->assertNull(ViewsBasicManager::migrationTargetBundle('post', 'portrait_grid'));
    // Calendar is not a listing bundle (handled by deploy_10000).
    $this->assertNull(ViewsBasicManager::migrationTargetBundle('event', 'calendar'));
    // Directory is profile-only.
    $this->assertNull(ViewsBasicManager::migrationTargetBundle('page', 'directory'));
    // Unknown type and missing values do not map.
    $this->assertNull(ViewsBasicManager::migrationTargetBundle('widget', 'card'));
    $this->assertNull(ViewsBasicManager::migrationTargetBundle(NULL, 'card'));
    $this->assertNull(ViewsBasicManager::migrationTargetBundle('post', NULL));
  }

  /**
   * Predecessor presets map each legacy block to a target bundle + params.
   *
   * @covers ::predecessorPreset
   */
  public function testPredecessorPreset() {
    $post = ViewsBasicManager::predecessorPreset('post_list');
    $this->assertSame('post_list_item', $post['target']);
    $this->assertSame('list_item', $post['params']['view_mode']);
    $this->assertSame(['post'], $post['params']['filters']['types']);
    $this->assertSame('field_publish_date:DESC', $post['params']['sort_by']);
    $this->assertTrue($post['params']['pinned_to_top']);

    $event = ViewsBasicManager::predecessorPreset('event_list');
    $this->assertSame('event_list_item', $event['target']);
    $this->assertSame('future', $event['params']['filters']['event_time_period']);
    $this->assertSame('field_event_date:ASC', $event['params']['sort_by']);

    $directory = ViewsBasicManager::predecessorPreset('directory');
    $this->assertSame('profile_directory', $directory['target']);
    $this->assertSame('directory', $directory['params']['view_mode']);
    $this->assertSame('field_last_name:ASC', $directory['params']['sort_by']);

    // Every preset carries the common defaults so setupView never warns.
    $this->assertArrayHasKey('operator', $post['params']);
    $this->assertArrayHasKey('field_options', $post['params']);

    $this->assertNull(ViewsBasicManager::predecessorPreset('view'));
    $this->assertNull(ViewsBasicManager::predecessorPreset('unknown'));
  }

}
