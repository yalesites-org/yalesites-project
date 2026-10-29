<?php

namespace Drupal\Tests\ys_views_basic\Kernel;

use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;

/**
 * Tests how the listing migrator discards pending Layout Builder edits.
 *
 * The pending edits are seeded through the real tempstore.shared service
 * rather than the key-value store the migrator clears, so a migrator pointed
 * at the wrong collection name or key format fails here instead of silently
 * clearing nothing on release (#1756).
 *
 * @group yalesites
 */
class LayoutTempstoreClearTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'block_content',
    'path_alias',
    'views',
    'node',
    'layout_discovery',
    'layout_builder',
    'ys_views_basic',
  ];

  /**
   * Clears only the named entities' override edits.
   */
  public function testClearsOnlyTheGivenEntities(): void {
    $factory = $this->container->get('tempstore.shared');
    $overrides = $factory->get('layout_builder.section_storage.overrides');
    $defaults = $factory->get('layout_builder.section_storage.defaults');

    $overrides->set('node.1.full.en', 'pending override edit');
    $overrides->set('node.1', 'untranslatable override edit');
    $overrides->set('node.10.full.en', 'similar id, other entity');
    $overrides->set('node.2.full.en', 'another entity');
    $defaults->set('node.1', 'pending default edit');

    $deleted = $this->container->get('ys_views_basic.listing_migrator')
      ->clearLayoutTempstore(['node.1']);

    $this->assertSame(2, $deleted);
    $this->assertNull($overrides->get('node.1.full.en'));
    $this->assertNull($overrides->get('node.1'));
    $this->assertSame('similar id, other entity', $overrides->get('node.10.full.en'));
    $this->assertSame('another entity', $overrides->get('node.2.full.en'));
    $this->assertSame('pending default edit', $defaults->get('node.1'));
  }

  /**
   * With no entities to clear, nothing is cleared.
   */
  public function testEmptyListClearsNothing(): void {
    $overrides = $this->container->get('tempstore.shared')->get('layout_builder.section_storage.overrides');
    $overrides->set('node.1.full.en', 'pending override edit');

    $deleted = $this->container->get('ys_views_basic.listing_migrator')->clearLayoutTempstore([]);

    $this->assertSame(0, $deleted);
    $this->assertSame('pending override edit', $overrides->get('node.1.full.en'));
  }

  /**
   * The clear-all variant clears overrides and leaves other collections.
   */
  public function testClearAllClearsOverridesAndNothingElse(): void {
    $factory = $this->container->get('tempstore.shared');
    $overrides = $factory->get('layout_builder.section_storage.overrides');
    $defaults = $factory->get('layout_builder.section_storage.defaults');

    $overrides->set('node.1', 'pending override edit');
    $overrides->set('node.2', 'another pending override edit');
    $defaults->set('node.1', 'pending default edit');

    $this->container->get('ys_views_basic.listing_migrator')->clearAllLayoutTempstore();

    $this->assertNull($overrides->get('node.1'));
    $this->assertNull($overrides->get('node.2'));
    $this->assertSame('pending default edit', $defaults->get('node.1'));
  }

}
