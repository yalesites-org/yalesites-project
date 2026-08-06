<?php

namespace Drupal\Tests\ys_beacon\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\metatag\MetatagManager;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\ys_beacon\Service\BeaconIndexability;

/**
 * Proves protected content is never eligible for the shared Beacon index.
 *
 * The shared/cross-tenant vector index is queried on behalf of visitors whose
 * access another site cannot re-check, so the only guarantee against leaking
 * private content is that it is never written to the index. isIndexable() is
 * the single gate every write path consults (the ExcludeAiDisabled processor,
 * the immediate-removal hook, and the content feed), so this test exercises it
 * against the REAL YaleSites access chain - ys_node_access's node grants for
 * CAS-protected (field_login_required) and unpublished content - rather than a
 * mock. The metatag manager is mocked because the ai_disable_indexing rule has
 * its own unit coverage (BeaconIndexabilityTest); here it only supplies the
 * "not disabled" / "disabled" resolved value so the access and publish gates
 * are what drive the result.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\BeaconIndexability
 */
class BeaconIndexabilityAccessTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'node', 'field', 'ys_node_access'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    // The node_access table backs the grant checks ys_node_access writes.
    $this->installSchema('node', ['node_access']);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    // Mirror the real content type: the CAS "Login Required" boolean field is
    // what ys_node_access reads to write a PRIVATE (anonymous-denied) grant.
    FieldStorageConfig::create([
      'field_name' => 'field_login_required',
      'entity_type' => 'node',
      'type' => 'boolean',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_login_required',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'CAS Login Required',
    ])->save();

    // isIndexable() asks a fresh AnonymousUserSession whether it may view the
    // node; anonymous needs 'access content' for a public node to be viewable
    // at all, on top of the ys_node_access grant.
    Role::create([
      'id' => RoleInterface::ANONYMOUS_ID,
      'label' => 'Anonymous',
    ])->grantPermission('access content')->save();
  }

  /**
   * A published, non-CAS page is anonymously viewable and indexable.
   *
   * @covers ::isIndexable
   */
  public function testPublishedPublicNodeIsIndexable(): void {
    $node = $this->createPage(published: TRUE, loginRequired: FALSE);
    $this->assertTrue($this->indexability()->isIndexable($node));
  }

  /**
   * An unpublished node is never indexable.
   *
   * @covers ::isIndexable
   */
  public function testUnpublishedNodeIsNotIndexable(): void {
    $node = $this->createPage(published: FALSE, loginRequired: FALSE);
    $this->assertFalse($this->indexability()->isIndexable($node));
  }

  /**
   * A CAS-protected (field_login_required) node is never indexable.
   *
   * @covers ::isIndexable
   */
  public function testCasProtectedNodeIsNotIndexable(): void {
    $node = $this->createPage(published: TRUE, loginRequired: TRUE);
    $this->assertFalse(
      $this->indexability()->isIndexable($node),
      'A published page marked CAS Login Required must be excluded: anonymous visitors cannot view it, so it must never reach the shared index.',
    );
  }

  /**
   * Content opted out via the ai_disable_indexing metatag is not indexable.
   *
   * @covers ::isIndexable
   */
  public function testAiDisabledNodeIsNotIndexable(): void {
    $node = $this->createPage(published: TRUE, loginRequired: FALSE);
    $indexability = $this->indexability(['ai_disable_indexing' => 'disabled']);
    $this->assertFalse($indexability->isIndexable($node));
  }

  /**
   * Creates and reloads a page node, refreshing node-access grants.
   */
  private function createPage(bool $published, bool $loginRequired): NodeInterface {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Test page',
      'status' => (int) $published,
      'field_login_required' => (int) $loginRequired,
    ]);
    $node->save();
    // Populate the node_access table from ys_node_access's records so the
    // anonymous view check reflects the CAS/publish grant.
    node_access_rebuild(FALSE);
    return Node::load($node->id());
  }

  /**
   * Builds the service with a metatag manager stubbed to the given tag values.
   */
  private function indexability(array $tags = []): BeaconIndexability {
    $manager = $this->createMock(MetatagManager::class);
    $manager->method('tagsFromEntityWithDefaults')->willReturn($tags);
    $manager->method('generateTokenValues')->willReturnArgument(0);
    return new BeaconIndexability($manager);
  }

}
