<?php

namespace Drupal\Tests\ys_views_wizard\Unit;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\ViewsBasicManager;
use Drupal\ys_views_wizard\ViewsWizardOptions;

/**
 * Tests the wizard's mapping between the two questions and a block plugin.
 *
 * These are the parts of ViewsWizardOptions that read nothing but
 * ViewsBasicManager::LISTING_BUNDLES, so they need no container. The
 * region-aware option lists are not covered here: they delegate to the block
 * manager's filtered definitions, which is contrib behaviour exercised by
 * placing a block, not by a unit test.
 *
 * @coversDefaultClass \Drupal\ys_views_wizard\ViewsWizardOptions
 *
 * @group yalesites
 */
class ViewsWizardOptionsTest extends UnitTestCase {

  /**
   * The class under test.
   *
   * @var \Drupal\ys_views_wizard\ViewsWizardOptions
   */
  protected $options;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->options = new ViewsWizardOptions(
      $this->createMock(BlockManagerInterface::class),
      $this->createMock(ContextRepositoryInterface::class),
      $this->createMock(ViewsBasicManager::class),
    );
  }

  /**
   * Every listing bundle is offered, and nothing else is.
   *
   * The hook uses this set to decide which picker tiles to collapse, so a
   * bundle missing here would leave a stray tile behind, and an extra entry
   * would remove a tile the wizard cannot then hand off to.
   *
   * @covers ::listingPluginIds
   */
  public function testListingPluginIdsCoversEveryListingBundle(): void {
    $expected = [];
    foreach (array_keys(ViewsBasicManager::LISTING_BUNDLES) as $bundle) {
      $expected['inline_block:' . $bundle] = $bundle;
    }

    $this->assertSame($expected, ViewsWizardOptions::listingPluginIds());
    $this->assertNotEmpty($expected, 'LISTING_BUNDLES must not be empty.');
  }

  /**
   * The Event Calendar tile is left alone.
   *
   * It is placed from the picker like a listing but is not one, so collapsing
   * it into the wizard would strand it behind two questions that cannot
   * produce it.
   *
   * @covers ::listingPluginIds
   */
  public function testEventCalendarTileIsLeftAlone(): void {
    $this->assertArrayNotHasKey(
      'inline_block:event_calendar',
      ViewsWizardOptions::listingPluginIds()
    );
  }

  /**
   * Every bundle round-trips from its own (content type, display mode) pair.
   *
   * This is the wizard's whole contract: the pair the editor answers has to
   * resolve back to exactly the bundle that encodes it.
   *
   * @covers ::resolveBundle
   * @covers ::resolvePluginId
   */
  public function testEveryBundleRoundTripsThroughItsOwnPair(): void {
    foreach (ViewsBasicManager::LISTING_BUNDLES as $bundle => $definition) {
      $this->assertSame(
        $bundle,
        $this->options->resolveBundle($definition['content_type'], $definition['view_mode']),
        sprintf('%s should resolve from (%s, %s).', $bundle, $definition['content_type'], $definition['view_mode'])
      );
      $this->assertSame(
        'inline_block:' . $bundle,
        $this->options->resolvePluginId($definition['content_type'], $definition['view_mode'])
      );
    }
  }

  /**
   * A pair with no listing bundle resolves to NULL rather than a bad plugin.
   *
   * ViewsWizardForm marks the display-mode radios #validated so the
   * AJAX-swapped options are accepted, which means core does not check the
   * submitted value against the option list. ::validateForm() relies on this
   * returning NULL to catch a pair that does not exist - without it the form
   * would hand Layout Builder a plugin ID for a bundle that is not there.
   *
   * @dataProvider providerImpossiblePairs
   *
   * @covers ::resolveBundle
   * @covers ::resolvePluginId
   */
  public function testImpossiblePairResolvesToNull(string $content_type, string $view_mode): void {
    $this->assertNull($this->options->resolveBundle($content_type, $view_mode));
    $this->assertNull($this->options->resolvePluginId($content_type, $view_mode));
  }

  /**
   * Pairs that must not resolve.
   *
   * @return array[]
   *   Test cases of [content type, view mode].
   */
  public static function providerImpossiblePairs(): array {
    return [
      'directory is profile-only' => ['post', 'directory'],
      'unknown content type' => ['nonsense', 'card'],
      'unknown display mode' => ['post', 'nonsense'],
      'both unknown' => ['nonsense', 'nonsense'],
      'empty strings' => ['', ''],
    ];
  }

}
