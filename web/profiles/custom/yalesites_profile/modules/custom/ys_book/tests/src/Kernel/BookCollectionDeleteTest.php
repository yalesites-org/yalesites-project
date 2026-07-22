<?php

namespace Drupal\Tests\ys_book\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\ys_book\Controller\YsBookController;
use Drupal\ys_book\Form\BookCollectionDeleteForm;
use Drupal\Core\Form\FormState;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests deleting an entire content collection.
 *
 * A content collection is a Drupal book. Deleting a collection dismantles the
 * book outline (removing the grouping and navigation) while keeping every page
 * as standalone content. No page node is ever deleted.
 *
 * @group ys_book
 * @group yalesites
 */
class BookCollectionDeleteTest extends KernelTestBase {

  use UserCreationTrait;

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', 'node_access');
    $this->installSchema('book', ['book']);
    $this->installConfig(['node', 'book', 'field']);

    // The delete route is gated on 'administer book outlines'; build the router
    // so the confirm form's cancel link can resolve the collections overview.
    $this->container->get('router.builder')->rebuild();

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    $this->container->get('current_user')
      ->setAccount($this->createUser(['administer book outlines', 'access content']));
  }

  /**
   * Creates a three-level collection: Root > Child > Grandchild.
   *
   * @return \Drupal\node\Entity\Node[]
   *   The root, child, and grandchild nodes.
   */
  private function createCollection(): array {
    $root = Node::create([
      'type' => 'page',
      'title' => 'Root',
      'status' => 1,
      'book' => ['bid' => 'new'],
    ]);
    $root->save();
    $bid = $root->id();

    $child = Node::create([
      'type' => 'page',
      'title' => 'Child',
      'status' => 1,
      'book' => ['bid' => $bid, 'pid' => $bid],
    ]);
    $child->save();

    $grandchild = Node::create([
      'type' => 'page',
      'title' => 'Grandchild',
      'status' => 1,
      'book' => ['bid' => $bid, 'pid' => $child->id()],
    ]);
    $grandchild->save();

    return [$root, $child, $grandchild];
  }

  /**
   * Counts the rows currently in the book outline table.
   */
  private function bookRowCount(): int {
    return (int) $this->container->get('database')
      ->select('book', 'b')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Dismantling a collection removes its outline but keeps all pages.
   */
  public function testDismantleRemovesCollectionAndKeepsPages(): void {
    [$root, $child, $grandchild] = $this->createCollection();
    $bid = (int) $root->id();

    // Sanity: all three pages belong to the collection.
    $this->assertCount(3, _ys_book_get_all_book_nids($bid));

    _ys_book_dismantle_collection($bid);

    // The collection no longer exists.
    $this->assertCount(0, _ys_book_get_all_book_nids($bid));

    // Every page still exists as standalone content with its title intact.
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    foreach ([$root, $child, $grandchild] as $node) {
      $reloaded = $storage->load($node->id());
      $this->assertNotNull($reloaded, 'Page node survives dismantling.');
      $this->assertSame(
        $node->label(),
        $reloaded->label(),
        'Page title is unchanged (guards the #880 empty-title regression).'
      );
    }
  }

  /**
   * Dismantling never promotes children into new standalone collections.
   *
   * The contrib book manager promotes children to their own book only when the
   * deleted node is the top-level page. Removing pages deepest-first must leave
   * zero book rows anywhere.
   */
  public function testDismantleDoesNotCreateOrphanCollections(): void {
    [$root] = $this->createCollection();

    _ys_book_dismantle_collection((int) $root->id());

    $this->assertSame(
      0,
      $this->bookRowCount(),
      'No book outline rows remain anywhere; no orphan collections were created.'
    );
    $this->assertEmpty(
      $this->container->get('book.manager')->getAllBooks(),
      'No collections remain after dismantling.'
    );
  }

  /**
   * Dismantling handles branching trees and leaves other collections intact.
   */
  public function testDismantleWithBranchesLeavesOtherCollectionsIntact(): void {
    // Collection A branches: root has two children; one has a grandchild.
    $root_a = Node::create([
      'type' => 'page',
      'title' => 'A root',
      'status' => 1,
      'book' => ['bid' => 'new'],
    ]);
    $root_a->save();
    $a = (int) $root_a->id();
    $child1 = Node::create([
      'type' => 'page',
      'title' => 'A child 1',
      'status' => 1,
      'book' => ['bid' => $a, 'pid' => $a],
    ]);
    $child1->save();
    $child2 = Node::create([
      'type' => 'page',
      'title' => 'A child 2',
      'status' => 1,
      'book' => ['bid' => $a, 'pid' => $a],
    ]);
    $child2->save();
    $grandchild = Node::create([
      'type' => 'page',
      'title' => 'A grandchild',
      'status' => 1,
      'book' => ['bid' => $a, 'pid' => $child1->id()],
    ]);
    $grandchild->save();

    // Collection B is a separate collection that must be untouched.
    [$root_b] = $this->createCollection();
    $b = (int) $root_b->id();

    _ys_book_dismantle_collection($a);

    // Collection A is fully removed, with no orphan books promoted from its
    // branches.
    $this->assertCount(0, _ys_book_get_all_book_nids($a));
    // Collection B is untouched.
    $this->assertCount(3, _ys_book_get_all_book_nids($b));

    // Every page of collection A survives as standalone content.
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    foreach ([$root_a, $child1, $child2, $grandchild] as $node) {
      $this->assertNotNull($storage->load($node->id()));
    }
  }

  /**
   * The confirm form lists every page that will be kept as standalone content.
   */
  public function testConfirmFormListsAffectedPages(): void {
    [$root] = $this->createCollection();

    $form = $this->container->get('form_builder')
      ->getForm(BookCollectionDeleteForm::class, $root);

    $this->assertEquals('Delete collection', (string) $form['actions']['submit']['#value']);
    $this->assertArrayHasKey('pages', $form);
    $items = $form['pages']['#items'];
    $this->assertContains('Root', $items);
    $this->assertContains('Child', $items);
    $this->assertContains('Grandchild', $items);
  }

  /**
   * The confirm form only accepts a top-level collection page.
   */
  public function testConfirmFormRejectsNonCollectionRoot(): void {
    [, $child] = $this->createCollection();

    // A child page is in a book but is not a collection root.
    $this->expectException(NotFoundHttpException::class);
    $this->container->get('form_builder')
      ->getForm(BookCollectionDeleteForm::class, $child);
  }

  /**
   * Submitting the confirm form dismantles the collection end to end.
   */
  public function testSubmitFormDismantlesCollection(): void {
    [$root] = $this->createCollection();

    $form_state = new FormState();
    $this->container->get('form_builder')
      ->submitForm(BookCollectionDeleteForm::class, $form_state, $root);

    $this->assertSame(0, $this->bookRowCount(), 'The collection was dismantled on submit.');
    $this->assertNotNull(
      $this->container->get('entity_type.manager')->getStorage('node')->load($root->id()),
      'The former collection root survives as a standalone page.'
    );
  }

  /**
   * The collections overview exposes a "Delete collection" operation per row.
   */
  public function testAdminOverviewIncludesDeleteOperation(): void {
    [$root] = $this->createCollection();

    $controller = $this->container->get('class_resolver')
      ->getInstanceFromDefinition(YsBookController::class);
    $build = $controller->adminOverview();

    $delete_link = NULL;
    foreach ($build['#rows'] as $row) {
      $last = array_key_last($row);
      $links = $row[$last]['data']['#links'] ?? [];
      if (isset($links['delete'])) {
        $delete_link = $links['delete'];
        break;
      }
    }

    $this->assertNotNull($delete_link, 'The overview exposes a delete operation.');
    $this->assertEquals('Delete collection', (string) $delete_link['title']);
    $this->assertEquals('ys_book.collection_delete', $delete_link['url']->getRouteName());
    $this->assertEquals(
      $root->id(),
      $delete_link['url']->getRouteParameters()['node'],
      'The delete operation targets the correct collection.'
    );
  }

}
