<?php

namespace Drupal\Tests\ys_beacon\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\ys_beacon\Service\AiMetadataManager;
use Drupal\ys_beacon\Service\BeaconIndexability;
use Drupal\ys_beacon\Service\ContentFeedBuilder;

/**
 * Tests the AI content feed builder's querying, filtering, and item shape.
 *
 * BeaconIndexability and AiMetadataManager are stubbed (they have their own
 * coverage); this exercises the builder's own logic against real node storage
 * and rendering.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\ContentFeedBuilder
 */
class ContentFeedBuilderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'node', 'field', 'filter', 'text'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['node', 'filter']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * Only indexable, published content appears, with the documented shape.
   *
   * @covers ::build
   * @covers ::buildItem
   */
  public function testFeedReturnsOnlyIndexableItems(): void {
    $published = Node::create(['type' => 'page', 'title' => 'Indexable page', 'status' => 1]);
    $published->save();
    $excluded = Node::create(['type' => 'page', 'title' => 'Opted out', 'status' => 1]);
    $excluded->save();
    Node::create(['type' => 'page', 'title' => 'Unpublished', 'status' => 0])->save();

    // Stub indexability: the second published node is treated as opted out.
    $indexability = $this->createMock(BeaconIndexability::class);
    $indexability->method('isIndexable')->willReturnCallback(
      fn ($entity) => $entity->id() === $published->id(),
    );
    $metadata = $this->createMock(AiMetadataManager::class);
    $metadata->method('getAiMetadata')->willReturn([
      'ai_description' => 'A description',
      'ai_tags' => 'tag1, tag2',
    ]);

    $payload = $this->builder($indexability, $metadata)->build('node', 1, 50);

    $this->assertCount(1, $payload['data'], 'Only the indexable published node is fed.');
    $item = $payload['data'][0];
    $this->assertSame('node/' . $published->id(), $item['id']);
    $this->assertSame('node', $item['type']);
    $this->assertSame('page', $item['bundle']);
    $this->assertSame('Indexable page', $item['title']);
    $this->assertSame('A description', $item['ai_description']);
    $this->assertSame('tag1, tag2', $item['ai_tags']);
    $this->assertIsString($item['content']);
    $this->assertNotNull($item['changed']);

    // The total counts published entities (both published nodes), and the
    // pagination echoes the request.
    $this->assertSame(2, $payload['pagination']['total_records']);
    $this->assertSame('node', $payload['pagination']['type']);
    $this->assertSame(1, $payload['pagination']['page']);
  }

  /**
   * An unsupported entity type is rejected.
   *
   * @covers ::build
   */
  public function testUnsupportedTypeThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->builder()->build('taxonomy_term');
  }

  /**
   * Page size is clamped to the maximum.
   *
   * @covers ::build
   */
  public function testPageSizeIsClamped(): void {
    $payload = $this->builder()->build('node', 1, 10000);
    $this->assertSame(ContentFeedBuilder::MAX_PAGE_SIZE, $payload['pagination']['page_size']);
  }

  /**
   * Builds a ContentFeedBuilder with real services and stubbed collaborators.
   */
  private function builder(?BeaconIndexability $indexability = NULL, ?AiMetadataManager $metadata = NULL): ContentFeedBuilder {
    return new ContentFeedBuilder(
      $this->container->get('entity_type.manager'),
      $indexability ?? $this->createMock(BeaconIndexability::class),
      $metadata ?? $this->createMock(AiMetadataManager::class),
      $this->container->get('renderer'),
      $this->container->get('account_switcher'),
    );
  }

}
