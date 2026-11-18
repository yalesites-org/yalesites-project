<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\pathauto\Entity\PathautoPattern;

/**
 * Deleting a page's menu link rebuilds its alias right away.
 *
 * Unchecking "Provide a menu link" deletes the link after the node is saved,
 * so pathauto built the alias while the link still existed and the page kept
 * its nested address until the next save.
 *
 * @see https://github.com/yalesites-org/YaleSites-Internal/issues/1406
 *
 * @group ys_core
 */
class MenuLinkUnlinkAliasTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'link',
    'node',
    'path',
    'path_alias',
    'token',
    'pathauto',
    'menu_link_content',
    'menu_ui',
    'ys_core',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('menu_link_content');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter', 'node', 'pathauto']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    // The page pattern from the profile's config/sync.
    PathautoPattern::create([
      'id' => 'page',
      'type' => 'canonical_entities:node',
      'pattern' => '/[node:menu-link:parent:url:relative]/[node:title]',
    ])->save();
  }

  /**
   * Returns the node's current alias.
   */
  protected function alias(Node $node): string {
    return $this->container->get('path_alias.manager')->getAliasByPath('/node/' . $node->id());
  }

  /**
   * The child's alias drops its parent when its link is deleted.
   */
  public function testDeletingLinkUpdatesAlias(): void {
    $parent = Node::create(['type' => 'page', 'title' => 'Parent', 'status' => 1]);
    $parent->save();
    $parent_link = MenuLinkContent::create([
      'title' => 'Parent',
      'link' => ['uri' => 'entity:node/' . $parent->id()],
      'menu_name' => 'main',
    ]);
    $parent_link->save();

    $child = Node::create(['type' => 'page', 'title' => 'Child', 'status' => 1]);
    $child->save();
    $child_link = MenuLinkContent::create([
      'title' => 'Child',
      'link' => ['uri' => 'entity:node/' . $child->id()],
      'menu_name' => 'main',
      'parent' => $parent_link->getPluginId(),
    ]);
    $child_link->save();
    $child->save();
    $this->assertSame('/parent/child', $this->alias($child));

    // Unchecking "Provide a menu link" saves the node, then core deletes the
    // link. Menu admin deletes the link without saving the node at all.
    $child->save();
    $child_link->delete();

    $this->assertNull(MenuLinkContent::load($child_link->id()));
    $this->assertSame('/child', $this->alias($child));
  }

}
