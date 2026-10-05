<?php

namespace Drupal\Tests\ys_node_access\Kernel;

use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use Drupal\ys_node_access\NodeAccessManager;

/**
 * Tests the batched node access grant rebuild in ys_node_access.install.
 *
 * See _ys_node_access_rebuild_grants() for why the rebuild the two update
 * hooks share is batched rather than a call to core's node_access_rebuild().
 * These tests pin the properties that make it safe to run during a deploy:
 * it is chunked over several passes via $sandbox, it never empties
 * node_access, and it still clears the nid 0 wildcard grant that the rebuild
 * it replaces removed. Issue #1566.
 *
 * @group yalesites
 * @group ys_node_access
 */
class NodeAccessRebuildUpdateTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'node',
    'field',
    'text',
    'user',
    'ys_node_access',
  ];

  /**
   * Number of nodes the multi-pass tests build.
   *
   * Deliberately larger than the rebuild's batch size (50) so a complete
   * rebuild has to span more than one pass -- that is the behaviour under
   * test. Raising the batch size past this would make those tests fail.
   */
  const NODE_COUNT = 60;

  /**
   * Guard against a rebuild that never reports itself finished.
   */
  const MAX_PASSES = 20;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'user']);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    // The update hooks live in ys_node_access.install, which is not loaded
    // for a kernel test the way it is during updatedb.
    \Drupal::moduleHandler()->loadInclude('ys_node_access', 'install');
  }

  /**
   * Tests that a full rebuild is chunked over more than one pass.
   *
   * An unbatched rebuild does all of its work in a single call and never
   * reports progress, so a large site's rebuild is one unbounded unit of work
   * that a PHP limit can kill outright.
   */
  public function testRebuildRunsAcrossMultiplePasses() {
    $this->createNodes(self::NODE_COUNT);

    $passes = $this->runRebuild('ys_node_access_update_10001');

    $this->assertGreaterThan(
      1,
      $passes,
      'The rebuild is chunked over multiple passes rather than done in one unbounded call.'
    );
    $this->assertNodesHaveGrants();
  }

  /**
   * Tests that the second update hook runs the same batched rebuild.
   */
  public function testUpdate10002RunsTheSameRebuild() {
    $this->createNodes(3);

    $this->runRebuild('ys_node_access_update_10002');

    $this->assertNodesHaveGrants();
  }

  /**
   * Tests that the rebuild never empties the node_access table.
   *
   * This is the property that makes the rebuild safe to interrupt. A row that
   * belongs to no node is used as the sentinel: core's node_access_rebuild()
   * truncates the entire table, so the sentinel would not survive it, whereas
   * a per-node rewrite only ever touches rows for the node it is rewriting.
   */
  public function testRebuildDoesNotTruncateTheNodeAccessTable() {
    $this->createNodes(3);
    $this->insertGrantRow(999999, 'ys_test_sentinel');

    $this->runRebuild('ys_node_access_update_10001');

    $this->assertEquals(
      1,
      $this->grantRowCount(999999),
      'The rebuild replaces rows node by node instead of truncating node_access.'
    );
  }

  /**
   * Tests that the rebuild still clears the nid 0 wildcard grant.
   *
   * NodeGrantDatabaseStorage::access() treats a row with nid 0 as a grant over
   * every published node, so leaving one behind would hand every visitor view
   * access to CAS-protected pages. The rebuild this replaces removed it as a
   * side effect of truncating; the per-node rewrite has to do it on purpose.
   */
  public function testRebuildRemovesTheNidZeroWildcardGrant() {
    $this->createNodes(3);
    $this->insertGrantRow(0, 'all');

    $this->runRebuild('ys_node_access_update_10001');

    $this->assertEquals(0, $this->grantRowCount(0));
  }

  /**
   * Tests that nodes a pass has not reached yet keep their existing grants.
   *
   * Between passes the site is still serving traffic, so every node must be
   * viewable throughout -- under its old grants until the rebuild reaches it,
   * under its new ones afterwards.
   */
  public function testNodesKeepGrantsBetweenPasses() {
    $this->createNodes(self::NODE_COUNT);

    $sandbox = [];
    ys_node_access_update_10001($sandbox);
    $this->assertLessThan(1, $sandbox['#finished'], 'One pass is not the whole rebuild.');

    $this->assertNodesHaveGrants();
  }

  /**
   * Tests that a rebuild abandoned part way can simply be run again.
   *
   * Drush drops $sandbox if updatedb dies, so the re-run starts over rather
   * than resuming. That is fine as long as rewriting a node's grants twice is
   * harmless and the end state is the same as an uninterrupted rebuild.
   */
  public function testInterruptedRebuildCanBeRerunFromScratch() {
    $this->createNodes(self::NODE_COUNT);
    $unpublished = $this->makeStaleUnpublishedNode();

    // A rebuild that dies after its first pass.
    $abandoned = [];
    ys_node_access_update_10001($abandoned);

    // The re-run drush would perform on the next deploy attempt.
    $this->runRebuild('ys_node_access_update_10001');

    $this->assertNodesHaveGrants();
    $this->assertUnpublishedGrantsAreCorrect($unpublished);
  }

  /**
   * Tests that the rebuild replaces stale rows written by the old grant logic.
   *
   * This is what the update hooks exist for: an unpublished node saved before
   * the fix still carries a public grant row until its grants are rewritten.
   */
  public function testRebuildReplacesStaleGrantRows() {
    $node = $this->makeStaleUnpublishedNode();

    $this->runRebuild('ys_node_access_update_10001');

    $this->assertUnpublishedGrantsAreCorrect($node);
  }

  /**
   * Tests that a completed rebuild clears the "needs rebuild" flag.
   */
  public function testCompletedRebuildClearsTheNeedsRebuildFlag() {
    $this->createNodes(5);
    node_access_needs_rebuild(TRUE);

    $this->runRebuild('ys_node_access_update_10001');

    $this->assertFalse(\Drupal::state()->get('node.node_access_needs_rebuild', FALSE));
  }

  /**
   * Tests that the rebuild copes with a site that has no nodes at all.
   */
  public function testRebuildOnAnEmptySiteFinishesImmediately() {
    $sandbox = [];
    ys_node_access_update_10001($sandbox);

    $this->assertEquals(1, $sandbox['#finished']);
  }

  /**
   * Runs an update hook to completion and returns the number of passes taken.
   *
   * @param string $update_hook
   *   The update hook function name.
   *
   * @return int
   *   How many passes the rebuild took.
   */
  protected function runRebuild($update_hook) {
    $sandbox = [];
    $passes = 0;
    $finished = 0;

    do {
      $update_hook($sandbox);
      $passes++;
      // Both update.php and drush read #finished out of the sandbox and then
      // unset it before the next pass; mirror that so the hook cannot rely on
      // its own flag surviving.
      $finished = $sandbox['#finished'] ?? 0;
      unset($sandbox['#finished']);
    } while ($passes < self::MAX_PASSES && $finished < 1);

    $this->assertEquals(1, $finished, 'The rebuild reported itself finished.');

    return $passes;
  }

  /**
   * Creates published page nodes.
   *
   * @param int $count
   *   How many nodes to create.
   */
  protected function createNodes($count) {
    for ($i = 0; $i < $count; $i++) {
      Node::create([
        'type' => 'page',
        'title' => 'Node ' . $i,
        'status' => 1,
        'uid' => 1,
      ])->save();
    }
  }

  /**
   * Creates an unpublished node carrying pre-fix (public) grant rows.
   *
   * @return \Drupal\node\NodeInterface
   *   The unpublished node.
   */
  protected function makeStaleUnpublishedNode() {
    $owner = User::create(['name' => 'owner', 'uid' => 5]);
    $owner->save();

    $node = Node::create([
      'type' => 'page',
      'title' => 'Draft',
      'status' => 0,
      'uid' => $owner->id(),
    ]);
    $node->save();

    \Drupal::database()->delete('node_access')->condition('nid', $node->id())->execute();
    $this->insertGrantRow($node->id(), NodeAccessManager::YS_NODE_ACCESS_REALM);

    return $node;
  }

  /**
   * Inserts a view grant row directly, bypassing the grants system.
   *
   * @param int $nid
   *   The node ID the row is written for.
   * @param string $realm
   *   The grant realm.
   */
  protected function insertGrantRow($nid, $realm) {
    \Drupal::database()->insert('node_access')
      ->fields([
        'nid' => $nid,
        'langcode' => 'en',
        'fallback' => 1,
        'realm' => $realm,
        'gid' => NodeAccessManager::YS_NODE_ACCESS_GRANT_ID_PUBLIC,
        'grant_view' => 1,
        'grant_update' => 0,
        'grant_delete' => 0,
      ])
      ->execute();
  }

  /**
   * Asserts every node in the site has at least one grant row.
   */
  protected function assertNodesHaveGrants() {
    $nids = array_values(\Drupal::entityQuery('node')->accessCheck(FALSE)->execute());

    $granted = \Drupal::database()->select('node_access', 'na')
      ->fields('na', ['nid'])
      ->condition('na.nid', $nids, 'IN')
      ->distinct()
      ->execute()
      ->fetchCol();

    $this->assertEmpty(
      array_diff($nids, $granted),
      'Every node holds grant rows at this point in the rebuild.'
    );
  }

  /**
   * Asserts an unpublished node carries the unpublished grants, not public.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The unpublished node.
   */
  protected function assertUnpublishedGrantsAreCorrect($node) {
    $rows = \Drupal::database()->select('node_access', 'na')
      ->fields('na', ['realm', 'gid', 'grant_view'])
      ->condition('na.nid', $node->id())
      ->orderBy('na.realm')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $this->assertEquals([
      [
        'realm' => NodeAccessManager::YS_NODE_ACCESS_UNPUBLISHED_REALM,
        'gid' => (string) NodeAccessManager::YS_NODE_ACCESS_GRANT_ID_UNPUBLISHED_ANY,
        'grant_view' => '1',
      ],
      [
        'realm' => NodeAccessManager::YS_NODE_ACCESS_UNPUBLISHED_OWNER_REALM,
        'gid' => (string) $node->getOwnerId(),
        'grant_view' => '1',
      ],
    ], $rows);
  }

  /**
   * Counts the grant rows held by a node ID.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return int
   *   The number of node_access rows for that node ID.
   */
  protected function grantRowCount($nid) {
    return (int) \Drupal::database()->select('node_access', 'na')
      ->condition('na.nid', $nid)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
