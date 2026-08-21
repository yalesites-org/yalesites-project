<?php

namespace Drupal\Tests\ys_beacon\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\Utility\Utility;
use Drupal\ys_beacon\BeaconAuthorization;
use Drupal\ys_beacon\Service\BeaconIndexability;

/**
 * Proves content is removed from the index the moment it stops being indexable.
 *
 * When an already-indexed node transitions from indexable to non-indexable
 * (published -> unpublished, public -> CAS-protected, AI indexing enabled ->
 * disabled), ys_beacon_entity_update() deletes its chunks from the search
 * server immediately rather than waiting for the next cron run, so protected
 * content cannot linger in the shared index. This drives that hook directly
 * against a real Search API index backed by the contrib search_api_test backend
 * (which records deletes). The full ys_beacon module is not enabled because its
 * dependency graph is heavy, so the hook file is included and invoked directly,
 * with the indexability and authorization services doubled to script each
 * transition. The per-state indexability decisions are proven separately in
 * BeaconIndexabilityAccessTest; here the concern is the removal dispatch.
 *
 * @group ys_beacon
 */
class BeaconImmediateRemovalTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'search_api',
    'search_api_test',
  ];

  /**
   * The Search API state key holding the test backend's indexed item ids.
   */
  private const INDEXED_STATE = 'search_api_test.backend.indexed.ys_beacon';

  /**
   * An unrelated indexed item that must survive any scoped removal.
   */
  private const UNRELATED_ITEM = 'entity:node/999999:en';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    // Saving a node writes its default node-access grant row.
    $this->installSchema('node', ['node_access']);
    $this->installSchema('search_api', ['search_api_item']);
    $this->installEntitySchema('search_api_task');
    $this->installConfig('search_api');

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    // The hook reads ys_beacon.settings directly. That config's schema ships
    // with the (here-disabled) ys_beacon module, so write it straight to the
    // config storage rather than through the schema-validating save path.
    \Drupal::service('config.storage')->write('ys_beacon.settings', [
      'azure_index_name' => 'test-index',
      'search_index_id' => 'ys_beacon',
    ]);
    \Drupal::configFactory()->reset('ys_beacon.settings');

    Server::create([
      'id' => 'test',
      'name' => 'Test server',
      'status' => TRUE,
      'backend' => 'search_api_test',
    ])->save();

    // The hook is a function on the (unenabled) module file; load it so it can
    // be invoked directly. The file has no include-time side effects.
    require_once dirname(__DIR__, 3) . '/ys_beacon.module';
  }

  /**
   * A node that becomes non-indexable is deleted from the index immediately.
   */
  public function testTransitionToNonIndexableRemovesItem(): void {
    $this->assertTrue(
      $this->runRemovalHook(newIndexable: FALSE, originalIndexable: TRUE),
      'A node that transitions indexable -> non-indexable is removed from the shared index.',
    );
  }

  /**
   * A node that is still indexable is left in the index.
   */
  public function testStillIndexableItemIsKept(): void {
    $this->assertFalse(
      $this->runRemovalHook(newIndexable: TRUE, originalIndexable: TRUE),
      'Content that remains indexable must not be removed.',
    );
  }

  /**
   * A node that was already non-indexable triggers no delete.
   */
  public function testAlreadyNonIndexableItemIsUntouched(): void {
    $this->assertFalse(
      $this->runRemovalHook(newIndexable: FALSE, originalIndexable: FALSE),
      'Only the indexable -> non-indexable transition removes an item.',
    );
  }

  /**
   * A read-only (borrowed) index is never written to, even on a transition.
   */
  public function testReadOnlyIndexIsNeverWritten(): void {
    $this->assertFalse(
      $this->runRemovalHook(newIndexable: FALSE, originalIndexable: TRUE, readOnly: TRUE),
      'A read-only index borrows another site collection and must never be written to.',
    );
  }

  /**
   * An unauthorized site performs no index writes.
   */
  public function testUnauthorizedSiteDoesNothing(): void {
    $this->assertFalse(
      $this->runRemovalHook(newIndexable: FALSE, originalIndexable: TRUE, authorized: FALSE),
      'A site where Beacon is not authorized must not touch the index.',
    );
  }

  /**
   * Runs the removal hook for a scripted transition and reports the delete.
   *
   * Seeds the test backend as if the node were already indexed, doubles the
   * indexability/authorization services to script the transition, invokes the
   * real hook, and reports whether the node's items were removed.
   *
   * @param bool $newIndexable
   *   Whether isIndexable() returns TRUE for the saved (current) node.
   * @param bool $originalIndexable
   *   Whether isIndexable() returns TRUE for the pre-save ($node->original).
   * @param bool $authorized
   *   Whether Beacon is authorized on the site.
   * @param bool $readOnly
   *   Whether the Beacon index is read-only.
   *
   * @return bool
   *   TRUE when every one of the node's item ids was deleted from the index.
   */
  private function runRemovalHook(bool $newIndexable, bool $originalIndexable, bool $authorized = TRUE, bool $readOnly = FALSE): bool {
    $this->createBeaconIndex($readOnly);

    $node = Node::create(['type' => 'page', 'title' => 'Removal test', 'status' => 1]);
    $node->save();
    $node->original = clone $node;

    $item_ids = [];
    foreach (array_keys($node->getTranslationLanguages()) as $langcode) {
      $item_ids[] = Utility::createCombinedId('entity:node', $node->id() . ':' . $langcode);
    }
    // Pretend the node is already embedded in the index, alongside an unrelated
    // item that must never be touched: removal must be scoped to this node.
    $indexed = array_fill_keys($item_ids, []);
    $indexed[self::UNRELATED_ITEM] = [];
    \Drupal::state()->set(self::INDEXED_STATE, $indexed);

    $indexability = $this->createMock(BeaconIndexability::class);
    $indexability->method('isIndexable')->willReturnCallback(
      fn ($entity) => $entity === $node ? $newIndexable : $originalIndexable,
    );
    $this->container->set('ys_beacon.indexability', $indexability);

    $authorization = $this->createMock(BeaconAuthorization::class);
    $authorization->method('isAuthorized')->willReturn($authorized);
    $this->container->set('ys_beacon.authorization', $authorization);

    ys_beacon_entity_update($node);

    $remaining = array_keys(\Drupal::state()->get(self::INDEXED_STATE, []));
    $this->assertContains(
      self::UNRELATED_ITEM,
      $remaining,
      'Removal must be scoped; an unrelated indexed item must survive.',
    );
    return array_intersect($item_ids, $remaining) === [];
  }

  /**
   * Creates the Beacon index on the test server.
   */
  private function createBeaconIndex(bool $readOnly): void {
    Index::create([
      'id' => 'ys_beacon',
      'name' => 'Beacon',
      'status' => TRUE,
      'read_only' => $readOnly,
      'server' => 'test',
      'datasource_settings' => ['entity:node' => []],
      'tracker_settings' => ['default' => []],
    ])->save();
  }

}
