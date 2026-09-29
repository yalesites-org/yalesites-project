<?php

namespace Drupal\Tests\ys_views_basic\Kernel;

use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;

/**
 * Tests the deploy helper that discards pending Layout Builder edits (#1756).
 *
 * The pending edit is seeded through the real tempstore.shared service rather
 * than the key-value store the helper clears, so a helper pointed at the wrong
 * collection name fails here instead of silently clearing nothing on release.
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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The helper is plain procedural code, so the file is included directly
    // rather than installing ys_views_basic and everything its services need.
    require_once dirname(__DIR__, 3) . '/ys_views_basic.deploy.php';
  }

  /**
   * Clears pending override edits and leaves other tempstore collections.
   */
  public function testClearsOverridesAndNothingElse(): void {
    $factory = $this->container->get('tempstore.shared');
    $overrides = $factory->get('layout_builder.section_storage.overrides');
    $defaults = $factory->get('layout_builder.section_storage.defaults');

    $overrides->set('node.1', 'pending override edit');
    $overrides->set('node.2', 'another pending override edit');
    $defaults->set('node.1', 'pending default edit');

    _ys_views_basic_clear_layout_tempstore();

    $this->assertNull($overrides->get('node.1'));
    $this->assertNull($overrides->get('node.2'));
    $this->assertSame('pending default edit', $defaults->get('node.1'));
  }

}
