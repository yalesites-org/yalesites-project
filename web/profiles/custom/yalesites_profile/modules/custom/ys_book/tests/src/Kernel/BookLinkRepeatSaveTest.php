<?php

namespace Drupal\Tests\ys_book\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests that saving a node twice in one request keeps one collection link.
 *
 * Quick Node Clone saves a cloned node twice: once to obtain a node ID, then
 * again to attach the Layout Builder blocks that need that ID
 * (QuickNodeCloneNodeForm::save()). Both saves carry the book values the clone
 * form posted.
 *
 * Those posted values contain `original_bid => 0`, because
 * ys_book_entity_prepare_form() clears $node->book on the clone route (the
 * #1068 fix), which sends book_node_prepare_form() down its
 * BookManager::getLinkDefaults() branch and past its own original_bid
 * correction. BookManager::updateOutline() reads an empty original_bid as
 * "this outline link is new" on *every* pass, so the second save re-INSERTs
 * the {book} row the first save already wrote. {book} is keyed on nid, so that
 * is a duplicate-key error: the whole second save rolls back, the editor gets
 * a site-wide error, and the clone is left behind without the Layout Builder
 * layout the second save existed to attach.
 *
 * @see yalesites-org/YaleSites-Internal#1490
 *
 * @group ys_book
 * @group yalesites
 */
class BookLinkRepeatSaveTest extends YsKernelTestBase {

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
    'quick_node_clone',
    'ys_book',
  ];

  /**
   * The route the page clone form is served from.
   *
   * Ys_book_entity_prepare_form() matches on this name, so a rename in contrib
   * would silently stop the clearing from happening.
   */
  private const CLONE_ROUTE = 'quick_node_clone.node.quick_clone';

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

    // Needed so the clone route can be looked up by name.
    $this->container->get('router.builder')->rebuild();

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    // YaleSites puts Content Collections on the page type; contrib book
    // defaults to its own 'book' type. BookNodeOutlineAccessCheck branches on
    // this, and book_node_prepare_form() bails out early when it denies, so
    // the wrong value here would quietly stop exercising the real path.
    $this->config('book.settings')
      ->set('allowed_types', ['page'])
      ->set('child_type', 'page')
      ->save();

    // Consume uid 1 so the account under test is an ordinary user. Uid 1
    // bypasses every permission check, which would let these tests pass
    // through a route no real editor takes.
    $this->createUser();

    $this->container->get('current_user')
      ->setAccount($this->createUser(['administer book outlines', 'access content']));
  }

  /**
   * Runs the real prepare-form hooks for a node, on a given route.
   *
   * Replicates EntityForm::prepareInvokeAll() (EntityForm.php:129-130), which
   * invokes entity_prepare_form and then node_prepare_form, and puts a request
   * for the route on the stack so ys_book_entity_prepare_form()'s route check
   * sees what it would see in a real request. That makes the resulting
   * $node->book the genuine article rather than a hand-built approximation.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node the form is being built for.
   * @param string $route_name
   *   The route to pretend we are on.
   * @param string $operation
   *   The form operation, e.g. 'quick_clone' or 'default'.
   */
  private function runPrepareFormHooks(Node $node, string $route_name, string $operation): void {
    $request = Request::create('/test-form-route');
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/test-form-route'));
    $this->container->get('request_stack')->push($request);

    $form_state = new FormState();
    $module_handler = $this->container->get('module_handler');
    foreach (['entity_prepare_form', 'node_prepare_form'] as $hook) {
      $module_handler->invokeAllWith(
        $hook,
        function (callable $implementation) use ($node, $operation, $form_state) {
          $implementation($node, $operation, $form_state);
        }
      );
    }

    $this->container->get('request_stack')->pop();
  }

  /**
   * Builds a collection root with one child, the way an editor would.
   *
   * Mirrors steps 1 and 2 of the reported reproduction: a page made into a
   * collection, then a page nested under it.
   *
   * @return \Drupal\node\Entity\Node[]
   *   The root and the child, both reloaded so book_node_load() has populated
   *   their book property exactly as it would be on a real edit form.
   */
  private function createRootAndChild(): array {
    $root = $this->createCollectionRoot('Book Parent');
    $bid = (int) $root->id();

    $child = Node::create([
      'type' => 'page',
      'title' => 'Child Original',
      'status' => 1,
      'book' => $this->postedBookValues(['bid' => $bid, 'pid' => $bid]),
    ]);
    $child->save();

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();

    return [$storage->load($root->id()), $storage->load($child->id())];
  }

  /**
   * Builds the book values a node add/clone form posts for an unlinked node.
   *
   * Derived from BookManager::getLinkDefaults() rather than hardcoded, so this
   * keeps matching the real form if contrib book changes its defaults.
   * book_node_prepare_form() unions the defaults onto any bid/pid already set
   * (`$node->book += $book_manager->getLinkDefaults($node_ref)`), and
   * BookManager::addFormElements() posts nid, has_children, original_bid and
   * parent_depth_limit straight back as '#type' => 'value' elements.
   *
   * @param array $selected
   *   The values the editor picked in the sidebar, e.g. bid and pid.
   *
   * @return array
   *   The full book array as the form submits it.
   */
  private function postedBookValues(array $selected): array {
    return $selected + $this->container->get('book.manager')->getLinkDefaults('new');
  }

  /**
   * Creates a collection root and returns it.
   */
  private function createCollectionRoot(string $title = 'Collection Root'): Node {
    $root = Node::create([
      'type' => 'page',
      'title' => $title,
      'status' => 1,
      'book' => ['bid' => 'new'],
    ]);
    $root->save();

    return $root;
  }

  /**
   * Returns the {book} row for a node, or NULL when it has none.
   */
  private function bookRow(int $nid): ?array {
    $row = $this->container->get('database')
      ->select('book', 'b')
      ->fields('b')
      ->condition('nid', $nid)
      ->execute()
      ->fetchAssoc();

    return $row ?: NULL;
  }

  /**
   * Counts every row in the outline table.
   */
  private function bookRowCount(): int {
    return (int) $this->container->get('database')
      ->select('book', 'b')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Saves a node a second time the way Quick Node Clone does.
   *
   * QuickNodeCloneNodeForm::save() re-saves the same entity object, in sync
   * mode and without forcing a new revision, to attach Layout Builder blocks
   * now that a node ID exists.
   */
  private function saveAgainAsQuickNodeClone(Node $node): void {
    $node->setNewRevision(FALSE);
    $node->setSyncing(TRUE)->save();
  }

  /**
   * The reported bug: a second save must not re-INSERT the outline row.
   *
   * Before the fix the second save throws EntityStorageException with
   * "SQLSTATE[23000] ... Duplicate entry '<nid>' for key 'PRIMARY'".
   */
  public function testRepeatSaveDoesNotDuplicateBookLink(): void {
    $root = $this->createCollectionRoot();
    $bid = (int) $root->id();

    $clone = Node::create([
      'type' => 'page',
      'title' => 'Clone of Child Original',
      'status' => 1,
      'book' => $this->postedBookValues(['bid' => $bid, 'pid' => $bid]),
    ]);
    $clone->save();

    $this->saveAgainAsQuickNodeClone($clone);

    $row = $this->bookRow((int) $clone->id());
    $this->assertNotNull($row, 'The clone is still linked into the collection.');
    $this->assertSame($bid, (int) $row['bid'], 'The clone belongs to the chosen collection.');
    $this->assertSame($bid, (int) $row['pid'], 'The clone is parented to the collection root.');
    $this->assertSame(2, (int) $row['depth'], 'The clone sits one level below the root.');
    $this->assertSame(
      2,
      $this->bookRowCount(),
      'Only the root and the clone are in the outline.'
    );
  }

  /**
   * The second save must commit, not roll back.
   *
   * This is the data-loss half of #1490. Quick Node Clone deliberately strips
   * layout_builder__layout before the first save and restores it in the
   * second, so when the second save rolls back the clone is left published
   * with no layout at all. Layout Builder is out of scope for a kernel test,
   * so a plain field change stands in for the restored layout: if the second
   * save is rolled back, the change is lost.
   */
  public function testRepeatSavePersistsChangesMadeBeforeTheSecondSave(): void {
    $root = $this->createCollectionRoot();
    $bid = (int) $root->id();

    $clone = Node::create([
      'type' => 'page',
      'title' => 'Before second save',
      'status' => 1,
      'book' => $this->postedBookValues(['bid' => $bid, 'pid' => $bid]),
    ]);
    $clone->save();

    $clone->setTitle('Restored by second save');
    $this->saveAgainAsQuickNodeClone($clone);

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $this->assertSame(
      'Restored by second save',
      $storage->load($clone->id())->label(),
      'The second save committed instead of rolling back.'
    );
  }

  /**
   * Choosing "- Create a new collection -" on a clone survives both saves.
   *
   * UpdateOutline() takes its `bid === 'new'` branch, which skips the
   * original_bid backfill entirely, so this path hits the same duplicate
   * INSERT as joining an existing collection.
   */
  public function testRepeatSaveOnNewCollectionRootDoesNotDuplicate(): void {
    $clone = Node::create([
      'type' => 'page',
      'title' => 'Clone that starts its own collection',
      'status' => 1,
      'book' => $this->postedBookValues(['bid' => 'new']),
    ]);
    $clone->save();

    $this->saveAgainAsQuickNodeClone($clone);

    $nid = (int) $clone->id();
    $row = $this->bookRow($nid);
    $this->assertNotNull($row, 'The clone became its own collection root.');
    $this->assertSame($nid, (int) $row['bid'], 'The new collection is keyed on the clone.');
    $this->assertSame(0, (int) $row['pid'], 'A collection root has no parent.');
    $this->assertSame(1, (int) $row['depth'], 'A collection root sits at depth 1.');
    $this->assertSame(1, $this->bookRowCount(), 'Exactly one outline row exists.');
  }

  /**
   * Cloning a page that is in no collection still saves twice cleanly.
   *
   * UpdateOutline() returns early on an empty bid, so no outline row is
   * written and the fix must not invent one.
   */
  public function testRepeatSaveWithoutCollectionCreatesNoBookLink(): void {
    $clone = Node::create([
      'type' => 'page',
      'title' => 'Clone with no collection',
      'status' => 1,
      'book' => $this->postedBookValues(['bid' => 0]),
    ]);
    $clone->save();

    $this->saveAgainAsQuickNodeClone($clone);

    $this->assertNull($this->bookRow((int) $clone->id()), 'The clone joined no collection.');
    $this->assertSame(0, $this->bookRowCount(), 'The outline table is untouched.');
  }

  /**
   * A single save still creates the outline row.
   *
   * The fix must not turn a genuine first INSERT into a no-op UPDATE.
   */
  public function testSingleSaveStillCreatesBookLink(): void {
    $root = $this->createCollectionRoot();
    $bid = (int) $root->id();

    $child = Node::create([
      'type' => 'page',
      'title' => 'Ordinary new child page',
      'status' => 1,
      'book' => $this->postedBookValues(['bid' => $bid, 'pid' => $bid]),
    ]);
    $child->save();

    $row = $this->bookRow((int) $child->id());
    $this->assertNotNull($row, 'A normally created page still joins the collection.');
    $this->assertSame($bid, (int) $row['bid']);
    $this->assertSame(2, (int) $row['depth']);
  }

  /**
   * The documented workaround still works: save first, assign afterwards.
   *
   * This is the case the fix must stay inert for. The node is no longer new
   * and posts original_bid = 0, but it has no outline row yet, so the save
   * must still INSERT one.
   */
  public function testCollectionAssignedAfterFirstSaveStillCreatesLink(): void {
    $root = $this->createCollectionRoot();
    $bid = (int) $root->id();

    $page = Node::create([
      'type' => 'page',
      'title' => 'Saved before joining a collection',
      'status' => 1,
    ]);
    $page->save();
    $this->assertNull($this->bookRow((int) $page->id()), 'It starts outside any collection.');

    // Manage Settings > Content Collection posts the same shape for a saved
    // node that has no link yet, keyed on the real nid rather than 'new'.
    $page->book = ['bid' => $bid, 'pid' => $bid]
      + $this->container->get('book.manager')->getLinkDefaults($page->id());
    $page->save();

    $row = $this->bookRow((int) $page->id());
    $this->assertNotNull($row, 'The workaround still adds the page to the collection.');
    $this->assertSame($bid, (int) $row['bid']);
    $this->assertSame(2, (int) $row['depth']);
  }

  /**
   * Moving a page between collections still updates its existing row.
   *
   * Guards against the fix pinning a page to the collection it first joined.
   */
  public function testMovingBetweenCollectionsUpdatesExistingLink(): void {
    $first = $this->createCollectionRoot('First collection');
    $second = $this->createCollectionRoot('Second collection');

    $page = Node::create([
      'type' => 'page',
      'title' => 'Page that moves',
      'status' => 1,
      'book' => $this->postedBookValues(['bid' => (int) $first->id(), 'pid' => (int) $first->id()]),
    ]);
    $page->save();
    $this->assertSame((int) $first->id(), (int) $this->bookRow((int) $page->id())['bid']);

    $page->book = [
      'bid' => (int) $second->id(),
      'pid' => (int) $second->id(),
    ] + $this->container->get('book.manager')->getLinkDefaults($page->id());
    $page->save();

    $row = $this->bookRow((int) $page->id());
    $this->assertSame(
      (int) $second->id(),
      (int) $row['bid'],
      'The page moved to the second collection.'
    );
    $this->assertSame(3, $this->bookRowCount(), 'Both roots and the moved page, no duplicates.');
  }

  /**
   * An already-linked page re-saved twice keeps its single row.
   *
   * Any module that re-saves a node in the same request hits the same path as
   * Quick Node Clone; editing an existing collection member must survive it.
   */
  public function testRepeatSaveOfExistingCollectionMemberIsStable(): void {
    $root = $this->createCollectionRoot();
    $bid = (int) $root->id();

    $child = Node::create([
      'type' => 'page',
      'title' => 'Existing member',
      'status' => 1,
      'book' => $this->postedBookValues(['bid' => $bid, 'pid' => $bid]),
    ]);
    $child->save();

    $child->setTitle('Existing member, edited');
    $child->save();
    $this->saveAgainAsQuickNodeClone($child);

    $row = $this->bookRow((int) $child->id());
    $this->assertNotNull($row, 'The page is still in the collection.');
    $this->assertSame($bid, (int) $row['bid']);
    $this->assertSame(2, $this->bookRowCount(), 'Still just the root and the child.');
  }

  /**
   * A clone nested under a child keeps the right materialised path.
   *
   * The duplicate INSERT in #1490 carried p1/p2 for a depth-2 page; deeper
   * placements go through the same code, so pin the depth-3 path too.
   */
  public function testRepeatSaveKeepsDeepPlacementPath(): void {
    $root = $this->createCollectionRoot();
    $bid = (int) $root->id();

    $child = Node::create([
      'type' => 'page',
      'title' => 'Child',
      'status' => 1,
      'book' => $this->postedBookValues(['bid' => $bid, 'pid' => $bid]),
    ]);
    $child->save();

    $grandchild = Node::create([
      'type' => 'page',
      'title' => 'Clone placed under the child',
      'status' => 1,
      'book' => $this->postedBookValues(['bid' => $bid, 'pid' => (int) $child->id()]),
    ]);
    $grandchild->save();

    $this->saveAgainAsQuickNodeClone($grandchild);

    $row = $this->bookRow((int) $grandchild->id());
    $this->assertNotNull($row, 'The deep clone kept its link.');
    $this->assertSame(3, (int) $row['depth'], 'It sits two levels below the root.');
    $this->assertSame($bid, (int) $row['p1'], 'The path starts at the collection root.');
    $this->assertSame((int) $child->id(), (int) $row['p2'], 'Then its parent.');
    $this->assertSame((int) $grandchild->id(), (int) $row['p3'], 'Then itself.');
  }

  /**
   * The clone route still exists under the name ys_book matches on.
   *
   * Ys_book_entity_prepare_form() only clears the stale collection data when
   * the route name matches exactly. If contrib renamed the route, the clearing
   * would silently stop happening and #1068 would regress with no other signal.
   */
  public function testCloneRouteStillExists(): void {
    $route = $this->container->get('router.route_provider')
      ->getRouteByName(self::CLONE_ROUTE);

    $this->assertSame(
      '/clone/{node}/quick_clone',
      $route->getPath(),
      'The page clone route is still where ys_book expects it.'
    );
  }

  /**
   * The real clone form leaves original_bid empty. This is the precondition.
   *
   * Every other test here builds the posted book array from
   * BookManager::getLinkDefaults(). This one proves that is what the actual
   * hook chain produces on the clone route: ys_book_entity_prepare_form()
   * clears $node->book, so book_node_prepare_form() takes its getLinkDefaults()
   * branch (original_bid => 0) instead of its else branch, which would have set
   * original_bid to the real bid and made the duplicate INSERT impossible.
   *
   * If this ever fails, the simulation in the other tests has drifted from
   * reality and they are no longer guarding anything.
   */
  public function testCloneFormPrepareHooksLeaveOriginalBidEmpty(): void {
    [, $child] = $this->createRootAndChild();

    $clone = $child->createDuplicate();
    $this->runPrepareFormHooks($clone, self::CLONE_ROUTE, 'quick_clone');

    $this->assertTrue($clone->isNew(), 'The clone is a genuinely new entity.');
    $this->assertEmpty(
      $clone->book['original_bid'],
      'The clone form posts an empty original_bid -- the condition behind #1490.'
    );
    $this->assertSame(
      'new',
      $clone->book['nid'],
      'The clone form posts nid "new", not the original node ID. The ticket '
      . 'claimed the clone shares the original ID; it does not.'
    );
  }

  /**
   * An ordinary edit form is left alone: original_bid keeps the real value.
   *
   * Guards the other side of ys_book_entity_prepare_form()'s route check. If
   * the clearing ever leaked onto the normal node edit form, every save of an
   * existing collection member would start looking like a fresh insert.
   */
  public function testOrdinaryEditFormKeepsOriginalBid(): void {
    [$root, $child] = $this->createRootAndChild();

    $this->runPrepareFormHooks($child, 'entity.node.edit_form', 'default');

    $this->assertSame(
      (int) $root->id(),
      (int) $child->book['original_bid'],
      'Editing an existing collection member keeps its real original_bid.'
    );
  }

  /**
   * End to end over the reported reproduction steps.
   *
   * 1. Create a page and make it a collection root.
   * 2. Create a page and nest it under that root.
   * 3. Clone the child.
   * 4. During the clone, nest it under the same root.
   * 5. Save.
   *
   * Unlike the other tests this feeds on the book array the real prepare-form
   * hooks produce, so the whole chain is exercised: our clearing, contrib's
   * defaults, and the repeat save. The clone form itself cannot be driven here
   * because BrowserTestBase does not run in this environment, so the double
   * save is still stood in for.
   */
  public function testReportedReproductionStepsSaveCleanly(): void {
    [$root, $child] = $this->createRootAndChild();
    $bid = (int) $root->id();

    // Step 3: clone the child.
    $clone = $child->createDuplicate();
    $this->runPrepareFormHooks($clone, self::CLONE_ROUTE, 'quick_clone');

    // Step 4: the editor picks the same collection as the original. Only bid
    // and pid come from the editor; the rest of the book array is posted back
    // as the hidden values the form was built with.
    $clone->book['bid'] = $bid;
    $clone->book['pid'] = $bid;

    // Step 5: save, then save again the way Quick Node Clone does.
    $clone->setTitle('Clone of Child Original');
    $clone->save();
    $this->saveAgainAsQuickNodeClone($clone);

    $row = $this->bookRow((int) $clone->id());
    $this->assertNotNull($row, 'The clone joined the collection.');
    $this->assertSame($bid, (int) $row['bid'], 'It is in the same collection as the original.');
    $this->assertSame($bid, (int) $row['pid'], 'It is nested under the same parent.');
    $this->assertSame(2, (int) $row['depth']);
    $this->assertSame(
      3,
      $this->bookRowCount(),
      'Root, original child and clone -- no duplicated or orphaned rows.'
    );

    // The original is untouched.
    $original = $this->bookRow((int) $child->id());
    $this->assertSame($bid, (int) $original['bid'], 'The original page kept its place.');
    $this->assertSame(2, (int) $original['depth']);
  }

}
