<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ys_layouts\Plugin\Layout\YSLayoutOneColumn;

/**
 * Tests ys_layouts_layout_alter()'s effect on core's "One column" layout.
 *
 * `layout_onecol` is a core (layout_discovery) plugin, so its class can't be
 * pinned via ys_layouts.layouts.yml the way the layouts ys_layouts owns are
 * (see YsLayoutsDefinitionsTest) -- hook_layout_alter() only fires through
 * the real plugin manager, so this has to be a kernel test rather than a
 * pure YAML-pinning unit test.
 *
 * @group yalesites
 * @group ys_layouts
 */
class YsLayoutsLayoutAlterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'quick_node_clone',
    'formdazzle',
    'ys_themes',
    'ys_layouts',
  ];

  /**
   * The One column layout gets YSLayoutOneColumn's class and CSS library.
   */
  public function testLayoutOnecolIsAltered(): void {
    /** @var \Drupal\Core\Layout\LayoutPluginManagerInterface $layout_manager */
    $layout_manager = $this->container->get('plugin.manager.core.layout');
    $definition = $layout_manager->getDefinition('layout_onecol');

    $this->assertSame(YSLayoutOneColumn::class, $definition->getClass());
    $this->assertSame('ys_layouts/ys_layout_onecol', $definition->getLibrary());
  }

  /**
   * Other Section layouts already using YSLayoutOptions are unaffected.
   */
  public function testOtherLayoutsAreUnaffected(): void {
    /** @var \Drupal\Core\Layout\LayoutPluginManagerInterface $layout_manager */
    $layout_manager = $this->container->get('plugin.manager.core.layout');

    $definition = $layout_manager->getDefinition('ys_layout_two_column_50_50');
    $this->assertNotSame(YSLayoutOneColumn::class, $definition->getClass());
  }

}
