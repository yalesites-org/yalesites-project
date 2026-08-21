<?php

namespace Drupal\Tests\ys_book\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
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
    // Kernel tests never run hook_install(), so add the title column this
    // module installs on a real site; see _ys_book_add_book_title_column().
    $this->container->get('module_handler')->loadInclude('ys_book', 'install');
    _ys_book_add_book_title_column();
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
   * Reloads a node so book_node_load() repopulates its $node->book data.
   *
   * Both the delete hooks and the delete confirmation read $node->book, which
   * only exists because contrib book_node_load() attaches it on load. Deleting
   * the in-memory node returned by createCollection() would skip that and stop
   * exercising the real Manage Content path.
   */
  private function reload(NodeInterface $node): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$node->id()]);
    return $storage->load($node->id());
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
   * Deleting a collection's top-level page creates no new collections.
   *
   * This is the Manage Content path: the page is deleted as a piece of
   * content, rather than the collection being taken apart from Manage Content
   * Collections. Contrib BookManager::deleteFromBook() promotes the children
   * of a book root into books of their own, which left the editor with one
   * junk collection per sub-page and no bulk way to undo it.
   *
   * @see yalesites-org/YaleSites-Internal#1511
   */
  public function testDeletingCollectionRootLeavesSubPagesStandalone(): void {
    [$root, $child, $grandchild] = $this->createCollection();

    $this->reload($root)->delete();

    $this->assertSame(
      0,
      $this->bookRowCount(),
      'No outline rows survive, so no sub-page was promoted into a collection.'
    );
    $this->assertEmpty(
      $this->container->get('book.manager')->getAllBooks(),
      'No new collections were created from the sub-pages.'
    );

    // Only the deleted page is gone; the sub-pages stay as standalone content.
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    foreach ([$child, $grandchild] as $page) {
      $this->assertNotNull($storage->load($page->id()), 'Sub-page survives the delete.');
    }
    $this->assertNull($storage->load($root->id()), 'The deleted top-level page is gone.');
  }

  /**
   * Deleting a page mid-collection still moves its children up a level.
   *
   * Only the top-level page needed new behaviour. Contrib's handling of a page
   * in the middle of a collection is already what we want and must not
   * regress.
   */
  public function testDeletingMidCollectionPageKeepsCollectionIntact(): void {
    [$root, $child, $grandchild] = $this->createCollection();
    $bid = (int) $root->id();

    $this->reload($child)->delete();

    $this->assertCount(
      2,
      _ys_book_get_all_book_nids($bid),
      'The collection keeps its remaining pages.'
    );
    $this->assertCount(
      1,
      $this->container->get('book.manager')->getAllBooks(),
      'No extra collection was created.'
    );

    $link = $this->container->get('book.manager')->loadBookLink($grandchild->id(), FALSE);
    $this->assertEquals($bid, $link['bid'], 'The orphaned page stays in the original collection.');
    $this->assertEquals($bid, $link['pid'], 'The orphaned page moved up under the root.');
  }

  /**
   * The Manage Content delete confirmation says the sub-pages survive.
   *
   * Left alone this is the generic content-delete confirmation, whose only
   * description is "This action cannot be undone". That is a big part of why
   * losing the collection here is a surprise; Manage Content Collections' own
   * confirmation already spells the consequence out.
   *
   * @see \Drupal\ys_book\Form\BookCollectionDeleteForm::getDescription()
   */
  public function testDeleteConfirmFormExplainsSubPagesSurvive(): void {
    [$root] = $this->createCollection();

    $form = $this->container->get('entity.form_builder')
      ->getForm($this->reload($root), 'delete');

    $this->assertArrayHasKey('ys_book_collection_notice', $form);
    $this->assertStringContainsString(
      "won't be deleted",
      (string) $form['ys_book_collection_notice']['#value'],
      'The confirmation tells the editor the other pages are kept.'
    );
  }

  /**
   * Pages with no sub-pages to lose keep the plain delete confirmation.
   */
  public function testDeleteConfirmFormLeavesOtherPagesAlone(): void {
    [, $child] = $this->createCollection();
    $builder = $this->container->get('entity.form_builder');

    $standalone = Node::create(['type' => 'page', 'title' => 'Standalone', 'status' => 1]);
    $standalone->save();
    $this->assertArrayNotHasKey(
      'ys_book_collection_notice',
      $builder->getForm($this->reload($standalone), 'delete'),
      'A page in no collection gets the plain confirmation.'
    );

    $this->assertArrayNotHasKey(
      'ys_book_collection_notice',
      $builder->getForm($this->reload($child), 'delete'),
      'A sub-page is not a collection root, so nothing extra is said.'
    );

    $solo = Node::create([
      'type' => 'page',
      'title' => 'Solo',
      'status' => 1,
      'book' => ['bid' => 'new'],
    ]);
    $solo->save();
    $this->assertCount(
      1,
      _ys_book_get_all_book_nids((int) $solo->id()),
      'Solo really is a one-page collection, so the page count is what suppresses the notice here.'
    );
    $this->assertArrayNotHasKey(
      'ys_book_collection_notice',
      $builder->getForm($this->reload($solo), 'delete'),
      'A collection that is only its top-level page has no sub-pages to explain.'
    );
  }

  /**
   * The collection top page drops contrib book's relocation warning.
   *
   * Contrib book_form_node_confirm_form_alter() adds "the child pages will be
   * relocated automatically" to every book page that has children. On a
   * collection's top page that is no longer what happens: ys_book takes the
   * collection apart and the sub-pages stay put as standalone content. Showing
   * both sentences tells the editor two contradictory things about the same
   * irreversible action.
   */
  public function testDeleteConfirmFormDropsContribWarningOnCollectionTop(): void {
    [$root] = $this->createCollection();

    $form = $this->container->get('entity.form_builder')
      ->getForm($this->reload($root), 'delete');

    $this->assertArrayNotHasKey(
      'book_warning',
      $form,
      "Contrib's relocation warning is wrong for a collection top page."
    );
    $this->assertArrayHasKey(
      'ys_book_collection_notice',
      $form,
      'The accurate YaleSites copy is still the one the editor sees.'
    );
  }

  /**
   * Mid-collection pages keep contrib book's relocation warning.
   *
   * Deleting a page from the middle of a collection really does move its
   * children up under its parent and leave the collection intact, so contrib's
   * wording is accurate there and must survive.
   */
  public function testDeleteConfirmFormKeepsContribWarningMidCollection(): void {
    [, $child] = $this->createCollection();

    $form = $this->container->get('entity.form_builder')
      ->getForm($this->reload($child), 'delete');

    $this->assertArrayHasKey(
      'book_warning',
      $form,
      'A mid-collection page really does have its children relocated.'
    );
  }

  /**
   * Pages taken out of the outline are hidden from contrib book's predelete.
   *
   * Contrib book_node_predelete() only checks the in-memory copy of the book
   * data, so once a page's outline row has gone that copy has to be cleared or
   * contrib goes looking for a row that no longer exists. It applies to the
   * top-level page itself, and to any sub-page swept up in the same bulk
   * delete, which runs predelete for every selected node before removing any.
   */
  public function testPredeleteHidesDismantledPagesFromContribBook(): void {
    [$root, $child] = $this->createCollection();
    $root = $this->reload($root);
    $child = $this->reload($child);

    ys_book_node_predelete($root);

    $this->assertSame(0, $this->bookRowCount(), 'The whole collection left the outline.');
    $this->assertEmpty($root->book['bid'] ?? NULL, 'Contrib book skips the top-level page.');

    // The sub-page still carries the collection data it was loaded with.
    $this->assertNotEmpty($child->book['bid'], 'The sub-page copy is stale, not yet cleared.');

    ys_book_node_predelete($child);

    $this->assertEmpty($child->book['bid'] ?? NULL, 'Contrib book skips the sub-page too.');
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
