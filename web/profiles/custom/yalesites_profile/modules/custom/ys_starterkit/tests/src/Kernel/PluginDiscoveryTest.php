<?php

namespace Drupal\Tests\ys_starterkit\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ys_starterkit\Plugin\Action\MediaBulkExport;
use Drupal\ys_starterkit\Plugin\Action\TaxonomyBulkExport;
use Drupal\ys_starterkit\Plugin\SingleContentSyncFieldProcessor\Embed;
use Drupal\ys_starterkit\Plugin\SingleContentSyncFieldProcessor\Markup;
use Drupal\ys_starterkit\Plugin\SingleContentSyncFieldProcessor\SmartDate;
use Drupal\ys_starterkit\Plugin\SingleContentSyncFieldProcessor\ViewsBasicParams;

/**
 * Verifies ys_starterkit's plugin annotations are discovered correctly.
 *
 * The field processor and action classes themselves add no logic beyond
 * their @SingleContentSyncFieldProcessor / @Action annotations, so the
 * annotation metadata (id, field_type, type) is the only thing ys_starterkit
 * actually contributes -- this is what these tests characterize.
 *
 * @group yalesites
 * @group ys_starterkit
 */
class PluginDiscoveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'file',
    'single_content_sync',
    'ys_starterkit',
  ];

  /**
   * Tests the field processor plugins are discovered with correct metadata.
   */
  public function testFieldProcessorPluginsAreDiscovered(): void {
    $definitions = \Drupal::service('plugin.manager.single_content_sync_field_processor')->getDefinitions();

    $expected = [
      'embed' => Embed::class,
      'markup' => Markup::class,
      'smartdate' => SmartDate::class,
      'views_basic_params' => ViewsBasicParams::class,
    ];

    foreach ($expected as $id => $class) {
      $this->assertArrayHasKey($id, $definitions);
      $this->assertSame($id, $definitions[$id]['field_type']);
      $this->assertSame($class, $definitions[$id]['class']);
    }
  }

  /**
   * Tests the bulk export action plugins are discovered with correct metadata.
   */
  public function testBulkExportActionPluginsAreDiscovered(): void {
    $definitions = \Drupal::service('plugin.manager.action')->getDefinitions();

    $this->assertArrayHasKey('media_bulk_export', $definitions);
    $this->assertSame('media', (string) $definitions['media_bulk_export']['type']);
    $this->assertSame(MediaBulkExport::class, $definitions['media_bulk_export']['class']);

    $this->assertArrayHasKey('taxonomy_bulk_export', $definitions);
    $this->assertSame('taxonomy_term', (string) $definitions['taxonomy_bulk_export']['type']);
    $this->assertSame(TaxonomyBulkExport::class, $definitions['taxonomy_bulk_export']['class']);
  }

  /**
   * Tests ys_starterkit's field types do not collide with other plugins.
   *
   * SingleContentSyncFieldProcessorPluginManager::getFieldTypesPlugins()
   * throws a LogicException if two plugins declare the same field_type, so
   * a passing call here confirms ys_starterkit's four field types are
   * unique across all enabled field processor plugins.
   */
  public function testFieldTypesDoNotCollide(): void {
    $manager = \Drupal::service('plugin.manager.single_content_sync_field_processor');
    $method = new \ReflectionMethod($manager, 'getFieldTypesPlugins');
    $method->setAccessible(TRUE);

    $fieldTypePlugins = $method->invoke($manager);

    foreach (['embed', 'markup', 'smartdate', 'views_basic_params'] as $fieldType) {
      $this->assertArrayHasKey($fieldType, $fieldTypePlugins);
    }
  }

  /**
   * Tests the plugin manager instantiates the ys_starterkit classes.
   */
  public function testCreateInstanceReturnsYsStarterkitClasses(): void {
    $manager = \Drupal::service('plugin.manager.single_content_sync_field_processor');

    $this->assertInstanceOf(Embed::class, $manager->createInstance('embed'));
    $this->assertInstanceOf(Markup::class, $manager->createInstance('markup'));
    $this->assertInstanceOf(SmartDate::class, $manager->createInstance('smartdate'));
    $this->assertInstanceOf(ViewsBasicParams::class, $manager->createInstance('views_basic_params'));
  }

}
