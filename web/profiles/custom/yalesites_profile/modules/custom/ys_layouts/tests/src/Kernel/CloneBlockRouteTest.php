<?php

namespace Drupal\Tests\ys_layouts\Kernel;

use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;

/**
 * Pins the access requirements on the clone block and clone section routes.
 *
 * @group ys_layouts
 * @group yalesites
 */
class CloneBlockRouteTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'node',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'quick_node_clone',
    'ys_layouts',
  ];

  /**
   * The clone routes, both of which write the layout tempstore on GET.
   *
   * @return array
   *   Data sets of route names.
   */
  public static function cloneRouteProvider(): array {
    return [
      'clone block' => ['ys_layouts.clone_block'],
      'clone section' => ['ys_layouts.clone_section'],
    ];
  }

  /**
   * The route requires a CSRF token, the lock check and a numeric delta.
   *
   * @dataProvider cloneRouteProvider
   */
  public function testCloneRouteRequirements(string $route_name): void {
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);

    $this->assertSame('TRUE', $route->getRequirement('_csrf_token'));
    $this->assertSame('TRUE', $route->getRequirement('_ys_layouts_clone_section'));
    $this->assertSame('\d+', $route->getRequirement('delta'));
  }

}
