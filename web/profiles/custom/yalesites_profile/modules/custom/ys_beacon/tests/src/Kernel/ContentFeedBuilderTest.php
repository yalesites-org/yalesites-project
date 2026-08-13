<?php

namespace Drupal\Tests\ys_beacon\Kernel;

use Drupal\Core\Language\LanguageInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\filter\Entity\FilterFormat;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\ys_beacon\Service\AiMetadataManager;
use Drupal\ys_beacon\Service\BeaconIndexability;
use Drupal\ys_beacon\Service\ContentFeedBuilder;
use Drupal\ys_beacon\Service\EntityCitationResolver;

/**
 * Tests the AI content feed builder's querying, filtering, and item shape.
 *
 * BeaconIndexability and AiMetadataManager are stubbed so this exercises the
 * builder's own logic against real node storage and rendering.
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
    // System supplies the core.date_format.* config the node template's
    // submitted line needs; without it rendering throws and the builder's
    // catch turns every body into an empty string, so nothing about the
    // content format can be asserted.
    $this->installConfig(['system', 'node', 'filter']);
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
   * Node content keeps its structure and drops executable markup.
   *
   * The feed's contract is the same one the legacy ai_engine_feed endpoint
   * published: the rendered default view, as HTML. A regression to the
   * flattened plain text this replaced fails every structural assertion here.
   *
   * @covers ::buildItem
   */
  public function testNodeContentIsStructuredSafeHtml(): void {
    // A format with no filters enabled passes the markup through verbatim, so
    // the assertions below exercise the builder rather than the filter system.
    FilterFormat::create(['format' => 'ys_beacon_raw', 'name' => 'Raw'])->save();
    node_add_body_field(NodeType::load('page'));

    $node = Node::create([
      'type' => 'page',
      'title' => 'Structured page',
      'status' => 1,
      'body' => [
        'value' => '<h2>A heading</h2>'
        . '<ul><li>First item</li><li>Second item</li></ul>'
        . '<p>A <strong>bold</strong> word and a <a href="https://example.com">link</a>.</p>'
        . '<script>alert("xss")</script>'
        . '<style>.leaked { color: red; }</style>'
        . '<p onclick="alert(1)">Handler</p>'
        . '<iframe src="https://evil.example"></iframe>',
        'format' => 'ys_beacon_raw',
      ],
    ]);
    $node->save();

    $content = $this->builder()->buildItem($node)['content'];

    // Structure a consumer can render or parse survives.
    $this->assertStringContainsString('<h2>A heading</h2>', $content);
    $this->assertStringContainsString('<li>First item</li>', $content);
    $this->assertStringContainsString('<strong>bold</strong>', $content);
    $this->assertStringContainsString('href="https://example.com"', $content);

    // Executable markup does not — tags AND their contents are gone.
    $this->assertStringNotContainsString('<script', $content);
    $this->assertStringNotContainsString('alert("xss")', $content);
    $this->assertStringNotContainsString('<style', $content);
    $this->assertStringNotContainsString('color: red', $content);
    $this->assertStringNotContainsString('<iframe', $content);
    $this->assertStringNotContainsString('onclick', $content);
    // The text of the element carrying the stripped handler is kept.
    $this->assertStringContainsString('Handler', $content);
  }

  /**
   * Media items still return an empty content value.
   *
   * @covers ::buildItem
   */
  public function testMediaContentStaysEmpty(): void {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $media = $this->createMock(MediaInterface::class);
    $media->method('getEntityTypeId')->willReturn('media');
    $media->method('id')->willReturn('7');
    $media->method('bundle')->willReturn('image');
    $media->method('uuid')->willReturn('a-uuid');
    $media->method('label')->willReturn('A file');
    $media->method('language')->willReturn($language);
    $media->method('hasField')->willReturn(FALSE);

    $item = $this->builder()->buildItem($media);

    $this->assertSame('', $item['content']);
    $this->assertSame('media/7', $item['id']);
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
      new EntityCitationResolver($this->container->get('entity_type.manager')),
    );
  }

}
