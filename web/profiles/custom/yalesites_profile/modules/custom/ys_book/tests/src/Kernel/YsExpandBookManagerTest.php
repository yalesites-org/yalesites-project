<?php

namespace Drupal\Tests\ys_book\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\ys_book\YsExpandBookManager;

/**
 * Kernel tests for ys_book's ExpandBookManager override.
 *
 * The module swaps the custom_book_block book.manager service for
 * YsExpandBookManager, which keeps CAS-protected (and otherwise
 * access-restricted) published pages in the book navigation, flagging them
 * with is_cas so the template can show a lock icon rather than hiding them.
 *
 * @coversDefaultClass \Drupal\ys_book\YsExpandBookManager
 * @group ys_book
 * @group yalesites
 */
class YsExpandBookManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'options',
    'filter',
    'book',
    'custom_book_block',
    'ys_book',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    // Loading a node fires the book module's hook_node_load, which queries the
    // book table, so its schema must be installed.
    $this->installSchema('book', ['book']);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    // A boolean "login required" field mirrors the field ys_book reads to flag
    // CAS-protected pages in the navigation.
    FieldStorageConfig::create([
      'field_name' => 'field_login_required',
      'entity_type' => 'node',
      'type' => 'boolean',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_login_required',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Login required',
    ])->save();
  }

  /**
   * Returns the (overridden) book.manager service.
   *
   * @return \Drupal\ys_book\YsExpandBookManager
   *   The book manager service.
   */
  protected function bookManager(): YsExpandBookManager {
    return $this->container->get('book.manager');
  }

  /**
   * Tests ys_book swaps book.manager for YsExpandBookManager.
   *
   * @covers ::__construct
   */
  public function testServiceOverride() {
    $this->assertInstanceOf(YsExpandBookManager::class, $this->bookManager());
  }

  /**
   * Tests bookLinkTranslate() keeps a published node accessible and not CAS.
   *
   * @covers ::bookLinkTranslate
   */
  public function testBookLinkTranslatePublishedNonCas() {
    $node = Node::create(['type' => 'page', 'title' => 'Public', 'status' => 1]);
    $node->save();

    $link = ['nid' => $node->id()];
    $this->bookManager()->bookLinkTranslate($link);

    $this->assertTrue($link['access']);
    $this->assertFalse($link['is_cas']);
    $this->assertSame('Public', $link['title']);
  }

  /**
   * Tests bookLinkTranslate() flags a login-required published node as CAS.
   *
   * @covers ::bookLinkTranslate
   */
  public function testBookLinkTranslateCasFlag() {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Protected',
      'status' => 1,
      'field_login_required' => 1,
    ]);
    $node->save();

    $link = ['nid' => $node->id()];
    $this->bookManager()->bookLinkTranslate($link);

    // A CAS-protected published page stays visible in the nav (lock icon),
    // rather than being filtered out by an access check.
    $this->assertTrue($link['access']);
    $this->assertTrue($link['is_cas']);
  }

  /**
   * Tests bookLinkTranslate() denies an unpublished node to anonymous users.
   *
   * @covers ::bookLinkTranslate
   */
  public function testBookLinkTranslateUnpublishedDenied() {
    $node = Node::create(['type' => 'page', 'title' => 'Draft', 'status' => 0]);
    $node->save();

    $link = ['nid' => $node->id()];
    $this->bookManager()->bookLinkTranslate($link);

    // The current user is anonymous without bypass permissions, so an
    // unpublished node resolves to no access.
    $this->assertFalse($link['access']);
  }

  /**
   * Tests bookLinkTranslate() denies access when the node does not exist.
   *
   * @covers ::bookLinkTranslate
   */
  public function testBookLinkTranslateMissingNode() {
    $link = ['nid' => 99999];
    $this->bookManager()->bookLinkTranslate($link);

    $this->assertFalse($link['access']);
  }

}
