<?php

namespace Drupal\Tests\ys_book\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Core\Session\AccountInterface;

/**
 * Tests which permission gates the content collection admin screens.
 *
 * Managing collections must be reachable with the narrow 'reorder book pages'
 * permission alone.
 *
 * @see \Drupal\ys_book\Access\BookOutlineAccessCheck
 * @see yalesites-org/YaleSites-Internal#1573
 *
 * @group ys_book
 * @group yalesites
 */
class BookCollectionPermissionTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The one collection route whose access check belongs to contrib, not us.
   */
  private const CONTRIB_GATED_ROUTE = 'book.node_child_ordering';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'node',
    'book',
    'custom_book_block',
    'ys_book',
  ];

  /**
   * A user holding only the narrow 'reorder book pages' permission.
   */
  protected AccountInterface $reorderOnly;

  /**
   * A user holding the broad 'administer book outlines' permission.
   */
  protected AccountInterface $administer;

  /**
   * The top-level page of a collection with two sub-pages.
   */
  protected Node $collection;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', 'node_access');
    $this->installSchema('book', ['book']);
    // Kernel tests never run hook_install(), so add the title column this
    // module installs on a real site; see _ys_book_add_book_title_column().
    $this->container->get('module_handler')->loadInclude('ys_book', 'install');
    _ys_book_add_book_title_column();
    $this->installConfig(['node', 'book', 'field']);
    $this->container->get('router.builder')->rebuild();

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    // User 1 gets every permission implicitly, so burn uid 1 before creating
    // the accounts whose permissions are the subject of the test.
    $this->createUser();
    $this->reorderOnly = $this->createUser(['reorder book pages', 'access content']);
    $this->administer = $this->createUser(['administer book outlines', 'access content']);

    $this->collection = $this->createCollection();
  }

  /**
   * Creates a collection whose top-level page has two sub-pages.
   *
   * Two are needed rather than one: contrib's child-ordering route is only
   * available when a collection has more than one sibling to order.
   *
   * @return \Drupal\node\Entity\Node
   *   The reloaded top-level page, carrying its book outline data.
   *
   * @see \Drupal\book\Controller\RouteAccessController::access()
   */
  private function createCollection(): Node {
    $root = Node::create([
      'type' => 'page',
      'title' => 'Root',
      'status' => 1,
      'book' => ['bid' => 'new'],
    ]);
    $root->save();
    $bid = $root->id();

    foreach (['Child one', 'Child two'] as $title) {
      Node::create([
        'type' => 'page',
        'title' => $title,
        'status' => 1,
        'book' => ['bid' => $bid, 'pid' => $bid],
      ])->save();
    }

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$bid]);
    return $storage->load($bid);
  }

  /**
   * Checks route access for an account.
   */
  private function hasRouteAccess(string $route, AccountInterface $account, array $parameters = []): bool {
    return $this->container->get('access_manager')
      ->checkNamedRoute($route, $parameters, $account);
  }

  /**
   * Every collection screen, keyed by route name, with its route parameters.
   *
   * @return array<string, array<string, string>>
   *   Route parameters keyed by route name.
   */
  private function collectionRoutes(): array {
    $node = ['node' => $this->collection->id()];

    return [
      'book.admin' => [],
      'book.admin_edit' => $node,
      self::CONTRIB_GATED_ROUTE => $node,
      'ys_book.collection_delete' => $node,
    ];
  }

  /**
   * Every collection screen is reachable with 'reorder book pages' alone.
   */
  public function testReorderPermissionReachesCollectionScreens(): void {
    foreach ($this->collectionRoutes() as $route => $parameters) {
      $this->assertTrue(
        $this->hasRouteAccess($route, $this->reorderOnly, $parameters),
        sprintf('%s is reachable with only the narrow reorder permission.', $route)
      );
    }
  }

  /**
   * The broad permission still reaches the screens YaleSites gates.
   *
   * Sites can grant 'administer book outlines' to a role of their own, and
   * anyone who already holds it must not lose access when the platform roles
   * stop relying on it. Child ordering is excluded because contrib gates that
   * one itself; see testChildOrderingRequiresTheNarrowPermission().
   */
  public function testAdministerPermissionStillReachesCollectionScreens(): void {
    $routes = $this->collectionRoutes();
    unset($routes[self::CONTRIB_GATED_ROUTE]);
    $this->assertCount(3, $routes, 'Only child ordering is gated by contrib.');

    foreach ($routes as $route => $parameters) {
      $this->assertTrue(
        $this->hasRouteAccess($route, $this->administer, $parameters),
        sprintf('%s is still reachable with the broad administer permission.', $route)
      );
    }
  }

  /**
   * Child ordering needs the narrow permission even for administer holders.
   *
   * Contrib's own access check on that route tests 'reorder book pages' and
   * nothing else, so the broad permission has never been enough on its own —
   * before this ticket the route demanded both. Asserted so the asymmetry is
   * recorded rather than rediscovered.
   *
   * @see \Drupal\book\Controller\RouteAccessController::access()
   */
  public function testChildOrderingRequiresTheNarrowPermission(): void {
    $this->assertFalse(
      $this->hasRouteAccess(self::CONTRIB_GATED_ROUTE, $this->administer, ['node' => $this->collection->id()]),
      'Contrib gates child ordering on the narrow permission, which this account lacks.'
    );
  }

  /**
   * A user with neither book permission is denied every collection screen.
   */
  public function testNeitherPermissionDeniesCollectionScreens(): void {
    $neither = $this->createUser(['access content']);

    foreach ($this->collectionRoutes() as $route => $parameters) {
      $this->assertFalse(
        $this->hasRouteAccess($route, $neither, $parameters),
        sprintf('%s is denied without either book permission.', $route)
      );
    }
  }

}
