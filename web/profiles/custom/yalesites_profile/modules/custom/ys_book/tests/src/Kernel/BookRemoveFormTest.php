<?php

namespace Drupal\Tests\ys_book\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormState;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\book\Form\BookRemoveForm;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;

/**
 * Tests removing a single page from a content collection.
 *
 * A content collection is a Drupal book, and the "Remove" action on one of its
 * child pages is contrib book's BookRemoveForm. YaleSites alters that form to
 * reword it and to invalidate the collection navigation caches on submit.
 *
 * @group ys_book
 * @group yalesites
 */
class BookRemoveFormTest extends YsKernelTestBase {

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

    // ys_book_update_10001() adds this column to contrib's book table for
    // custom menu link titles, and update hooks do not run in kernel tests.
    // Without it every node save here dies on the raw UPDATE in
    // _ys_book_save_menu_link_title().
    $this->container->get('database')->schema()->addField('book', 'title', [
      'type' => 'varchar',
      'length' => 255,
      'not null' => FALSE,
      'description' => 'Custom menu link title for the book page.',
    ]);

    // The remove form's cancel link resolves the node's canonical route.
    $this->container->get('router.builder')->rebuild();

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    // Mirror the platform's own book settings instead of contrib's default of
    // 'book'. book_node_load() only populates $node->book for an allowed type,
    // and BookRemoveForm reads that array to build its confirm question.
    $this->config('book.settings')->set('allowed_types', ['page'])->save();

    $this->container->get('current_user')->setAccount(
      $this->createUser(['administer book outlines', 'access content'])
    );
  }

  /**
   * Creates a Root > Child > Grandchild collection.
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
   * Reloads a node so book_node_load() repopulates its book array.
   */
  private function reload(NodeInterface $node): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$node->id()]);
    return $storage->load($node->id());
  }

  /**
   * Returns a node's book outline row, or NULL when it has none.
   */
  private function outlineRow(int $nid): ?array {
    $row = $this->container->get('database')
      ->select('book', 'b')
      ->fields('b')
      ->condition('b.nid', $nid)
      ->execute()
      ->fetchAssoc();

    return $row ?: NULL;
  }

  /**
   * Confirms the form the way clicking "Remove" in a browser does.
   *
   * A real POST marks the clicked button as the triggering element, and that is
   * what lets a button-level #submit array replace the form's own handlers. A
   * plain programmatic submitForm() leaves the triggering element unset (see
   * FormBuilder::doBuildForm()), so on its own it never exercises that path.
   */
  private function clickRemove(NodeInterface $node): void {
    $form_builder = $this->container->get('form_builder');
    $built = $form_builder->getForm(BookRemoveForm::class, $node);

    $form_state = new FormState();
    $form_state->setTriggeringElement($built['actions']['submit']);
    $form_builder->submitForm(BookRemoveForm::class, $form_state, $node);
  }

  /**
   * Confirming the form actually removes the page from the collection.
   *
   * Regression test for the silent no-op: the cache-invalidation handler was
   * attached to the confirm button, so it displaced the form's own handlers and
   * BookRemoveForm::submitForm() never ran. The editor saw a normal redirect
   * while the page stayed in the collection.
   */
  public function testConfirmingRemovalDeletesOutlineRow(): void {
    [$root, $child] = $this->createCollection();
    $child_id = (int) $child->id();

    $this->assertNotNull(
      $this->outlineRow($child_id),
      'The page starts out in the collection.'
    );

    $this->clickRemove($this->reload($child));

    $this->assertNull(
      $this->outlineRow($child_id),
      'Confirming "Remove" deleted the page from the collection.'
    );
    $this->assertContains(
      (string) $root->id(),
      array_map('strval', _ys_book_get_all_book_nids((int) $root->id())),
      'The rest of the collection is left intact.'
    );
  }

  /**
   * The confirm button must not carry its own submit handlers.
   *
   * FormBuilder hands a clicked button's #submit array to setSubmitHandlers(),
   * which replaces the form-level handlers rather than adding to them. Since
   * ConfirmFormBase builds this button without a #submit, anything attached to
   * it silently displaces the form's real submit logic.
   */
  public function testConfirmButtonDoesNotOverrideFormSubmitHandlers(): void {
    [, $child] = $this->createCollection();

    $form = $this->container->get('form_builder')
      ->getForm(BookRemoveForm::class, $this->reload($child));

    $this->assertArrayNotHasKey(
      '#submit',
      $form['actions']['submit'],
      'Nothing is attached to the confirm button.'
    );

    $handlers = $form['#submit'];
    $this->assertContains('_ys_book_remove_form_submit', $handlers);
    $this->assertLessThan(
      array_search('_ys_book_remove_form_submit', $handlers, TRUE),
      array_search('::submitForm', $handlers, TRUE),
      'The removal runs before the cache invalidation.'
    );
  }

  /**
   * Removal still clears the collection navigation caches.
   *
   * The handler now runs after the outline row is gone, so the removed page is
   * no longer a member of the collection and has to be invalidated explicitly.
   * Otherwise the page the editor is redirected to keeps serving a cached
   * render that still shows the collection navigation.
   */
  public function testRemovalInvalidatesCollectionNavigationCaches(): void {
    [$root, $child] = $this->createCollection();
    $bid = (int) $root->id();
    $child_id = (int) $child->id();

    \Drupal::cache()->set('collection:nav_position:' . $bid, 'stale');
    \Drupal::cache()->set(
      'ys_book_test:removed_page',
      'stale',
      Cache::PERMANENT,
      ['node:' . $child_id]
    );

    $this->clickRemove($this->reload($child));

    $this->assertFalse(
      \Drupal::cache()->get('collection:nav_position:' . $bid),
      'The stored navigation position for the collection was cleared.'
    );
    $this->assertFalse(
      \Drupal::cache()->get('ys_book_test:removed_page'),
      'The removed page was invalidated so its navigation re-renders.'
    );
  }

  /**
   * Removing a page relocates its children to keep the collection connected.
   */
  public function testRemovingPageWithChildrenRelocatesChildren(): void {
    [$root, $child, $grandchild] = $this->createCollection();
    $bid = (int) $root->id();

    $this->clickRemove($this->reload($child));

    $row = $this->outlineRow((int) $grandchild->id());
    $this->assertNotNull($row, 'The grandchild stays in the collection.');
    $this->assertEquals($bid, $row['bid'], 'It is in the same collection.');
    $this->assertEquals(
      $bid,
      $row['pid'],
      'It was reparented to the removed page\'s own parent.'
    );
  }

}
