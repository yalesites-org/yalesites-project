<?php

namespace Drupal\Tests\ys_toolbar\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests ys_toolbar's procedural hook implementations and service wiring.
 *
 * @group ys_toolbar
 * @group yalesites
 */
class ToolbarHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'formdazzle',
    'ys_themes',
    'ys_toolbar',
  ];

  /**
   * The "ys_toolbar.items" service resolves from the real container.
   *
   * This confirms the services.yml class and argument wiring is correct --
   * the ToolbarItemsService unit tests bypass the container entirely with
   * hand-built mocks, so they can't catch a broken service definition.
   */
  public function testToolbarItemsServiceResolvesFromContainer(): void {
    $service = \Drupal::service('ys_toolbar.items');
    $this->assertInstanceOf('Drupal\ys_toolbar\ToolbarItemsService', $service);
  }

  /**
   * Hook_toolbar() delegates to the service, returning no items off a node.
   *
   * The default kernel test environment has no active node route, so
   * isCurrentRouteNode() is FALSE and addItems() short-circuits to [].
   */
  public function testHookToolbarReturnsEmptyOutsideNodeRoute(): void {
    $this->assertSame([], ys_toolbar_toolbar());
  }

  /**
   * Hook_toolbar_alter() always removes the contextual toggle.
   */
  public function testHookToolbarAlterRemovesContextualToggle(): void {
    $items = [
      'contextual' => ['#type' => 'toolbar_item'],
      'administration' => [],
    ];
    ys_toolbar_toolbar_alter($items);

    $this->assertArrayNotHasKey('contextual', $items);
  }

  /**
   * Hook_toolbar_alter() always attaches the ys_toolbar library.
   */
  public function testHookToolbarAlterAttachesLibrary(): void {
    $items = ['administration' => []];
    ys_toolbar_toolbar_alter($items);

    $this->assertContains('ys_toolbar/ys_toolbar', $items['administration']['#attached']['library']);
  }

  /**
   * Hook_toolbar_alter() leaves the "Local Tasks" title alone off a node route.
   *
   * The rename to "More Actions" is gated on
   * ToolbarItemsService::isCurrentRouteNode(), which is exercised directly
   * (with both branches) in the ToolbarItemsService unit tests. Simulating
   * an actual node canonical route here would require a full request
   * cycle, which is impractical in a kernel test -- see the module's test
   * log for details.
   */
  public function testHookToolbarAlterLeavesLocalTasksTitleOutsideNodeRoute(): void {
    $items = [
      'administration' => [],
      'admin_toolbar_local_tasks' => ['tab' => ['#title' => 'Local Tasks']],
    ];
    ys_toolbar_toolbar_alter($items);

    $this->assertSame('Local Tasks', $items['admin_toolbar_local_tasks']['tab']['#title']);
  }

}
