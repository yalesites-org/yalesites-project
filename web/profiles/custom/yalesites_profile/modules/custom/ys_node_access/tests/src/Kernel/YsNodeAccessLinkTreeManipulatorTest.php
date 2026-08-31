<?php

namespace Drupal\Tests\ys_node_access\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Menu\MenuLinkTreeElement;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\Core\Menu\MenuLinkMock;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\ys_node_access\Menu\YsNodeAccessLinkTreeManipulator;

/**
 * Tests YsNodeAccessLinkTreeManipulator's menu-link access override.
 *
 * The module lets anonymous users SEE menu links to CAS-gated nodes in the
 * "main" and "utility-navigation" menus (@see _ys_node_access_cas_menus()),
 * so they can click through and be redirected to CAS login by
 * NodeAccessEventSubscriber, rather than having the default node-optimized
 * menu access check hide the link entirely. These tests exercise the real
 * decorated service -- 'menu.default_tree_manipulators' -- against real
 * nodes and menu links, so the override is characterized against actual
 * node grants rather than a stubbed access result.
 *
 * @group yalesites
 * @group ys_node_access
 */
class YsNodeAccessLinkTreeManipulatorTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'node',
    'field',
    'text',
    'user',
    'link',
    'menu_link_content',
    'ys_node_access',
  ];

  /**
   * The decorated tree manipulator service under test.
   *
   * @var \Drupal\ys_node_access\Menu\YsNodeAccessLinkTreeManipulator
   */
  protected $manipulator;

  /**
   * A published node with field_login_required set.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $casNode;

  /**
   * A published node without field_login_required set.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $publicNode;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('menu_link_content');
    $this->installSchema('node', ['node_access']);
    // 'user' config provides the anonymous/authenticated roles the grants
    // below load and grant permissions to.
    $this->installConfig(['system', 'node', 'user']);

    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('access content')->save();
    Role::load(RoleInterface::AUTHENTICATED_ID)->grantPermission('access content')->save();

    NodeType::create(['type' => 'protected_type', 'name' => 'Protected type'])->save();
    $field_storage = FieldStorageConfig::create([
      'field_name' => 'field_login_required',
      'entity_type' => 'node',
      'type' => 'boolean',
    ]);
    $field_storage->save();
    FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'protected_type',
      'label' => 'CAS Login Required',
    ])->save();

    $this->casNode = Node::create([
      'type' => 'protected_type',
      'title' => 'CAS gated page',
      'field_login_required' => TRUE,
      'status' => 1,
    ]);
    $this->casNode->save();

    $this->publicNode = Node::create([
      'type' => 'protected_type',
      'title' => 'Public page',
      'field_login_required' => FALSE,
      'status' => 1,
    ]);
    $this->publicNode->save();

    // The service decorates core's menu.default_tree_manipulators, so
    // fetching it by the original ID returns this module's decorator.
    $this->manipulator = $this->container->get('menu.default_tree_manipulators');
    $this->assertInstanceOf(YsNodeAccessLinkTreeManipulator::class, $this->manipulator);
  }

  /**
   * Builds a menu link tree element pointing at $node's canonical route.
   *
   * Backed by a real menu_link_content entity, in the given menu.
   *
   * @return array
   *   A [MenuLinkTreeElement, MenuLinkContentInterface] pair.
   */
  protected function buildElement(string $menu_name, Node $node, string $title): array {
    $menu_link_entity = MenuLinkContent::create([
      'title' => $title,
      'menu_name' => $menu_name,
      'link' => ['uri' => 'entity:node/' . $node->id()],
    ]);
    $menu_link_entity->save();

    $instance = MenuLinkMock::create([
      'id' => 'menu_link_content:' . $menu_link_entity->uuid(),
      'route_name' => 'entity.node.canonical',
      'route_parameters' => ['node' => $node->id()],
      'menu_name' => $menu_name,
      'title' => $title,
      'parent' => '',
      'metadata' => [
        'entity_id' => $menu_link_entity->id(),
        'cache_contexts' => [],
        'cache_tags' => [],
        'cache_max_age' => Cache::PERMANENT,
      ],
    ]);

    return [new MenuLinkTreeElement($instance, FALSE, 1, FALSE, []), $menu_link_entity];
  }

  /**
   * A CAS-gated node's link stays visible to anonymous in the main menu.
   *
   * The module shows the link in "main" (a CAS-checked menu) and marks the
   * underlying menu_link_content entity as data_restricted so
   * ys_node_access_preprocess_menu() can style it.
   */
  public function testCasGatedNodeLinkVisibleToAnonymousInMainMenu() {
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    [$element, $menu_link_entity] = $this->buildElement('main', $this->casNode, 'CAS link');

    $tree = $this->manipulator->checkAccess([$element]);

    $this->assertTrue($tree[0]->access->isAllowed());
    $this->assertNotInstanceOf('\Drupal\Core\Menu\InaccessibleMenuLink', $tree[0]->link);

    $reloaded = $this->container->get('entity_type.manager')
      ->getStorage('menu_link_content')
      ->load($menu_link_entity->id());
    $this->assertTrue(!empty($reloaded->data_restricted));
  }

  /**
   * A CAS-gated node's link is hidden from anonymous in an unchecked menu.
   *
   * When the link lives in a menu the module does not check (e.g. "footer"),
   * the override in menuLinkCheckAccess() is scoped to
   * _ys_node_access_cas_menus() only.
   */
  public function testCasGatedNodeLinkHiddenFromAnonymousInUncheckedMenu() {
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    [$element] = $this->buildElement('footer', $this->casNode, 'CAS link');

    $tree = $this->manipulator->checkAccess([$element]);

    $this->assertFalse($tree[0]->access->isAllowed());
    $this->assertInstanceOf('\Drupal\Core\Menu\InaccessibleMenuLink', $tree[0]->link);
  }

  /**
   * A non-gated node's link in "main" is visible to anonymous users.
   *
   * Same as ordinary node access -- no override was needed here.
   */
  public function testPublicNodeLinkVisibleToAnonymousInMainMenu() {
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    [$element] = $this->buildElement('main', $this->publicNode, 'Public link');

    $tree = $this->manipulator->checkAccess([$element]);

    $this->assertTrue($tree[0]->access->isAllowed());
  }

  /**
   * Tests the protected isCasRestricted() helper directly.
   *
   * @covers ::isCasRestricted
   */
  public function testIsCasRestricted() {
    $reflection = new \ReflectionClass($this->manipulator);
    $method = $reflection->getMethod('isCasRestricted');
    $method->setAccessible(TRUE);

    $this->assertTrue($method->invoke($this->manipulator, $this->casNode));
    $this->assertFalse($method->invoke($this->manipulator, $this->publicNode));

    NodeType::create(['type' => 'unprotected_type', 'name' => 'Unprotected type'])->save();
    $no_field_node = Node::create(['type' => 'unprotected_type', 'title' => 'No field', 'status' => 1]);
    $no_field_node->save();
    $this->assertFalse($method->invoke($this->manipulator, $no_field_node));
  }

}
