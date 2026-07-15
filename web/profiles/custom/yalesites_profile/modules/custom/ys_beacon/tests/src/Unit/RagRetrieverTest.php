<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\RagRetriever;
use Psr\Log\LoggerInterface;

/**
 * Tests Beacon RAG retrieval: failure logging, guards, and index resolution.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Service\RagRetriever
 */
class RagRetrieverTest extends UnitTestCase {

  /**
   * A failed search is logged with context and degrades to no citations.
   *
   * @covers ::retrieve
   */
  public function testFailedSearchIsLoggedWithContext(): void {
    $question = 'How do I apply for housing?';

    $query = $this->createMock(QueryInterface::class);
    $query->method('setOption')->willReturnSelf();
    $query->method('keys')->willReturnSelf();
    $query->method('execute')->willThrowException(new \RuntimeException('Azure 503'));

    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->method('id')->willReturn('ys_beacon');
    $index->method('query')->willReturn($query);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Beacon retrieval failed for index'),
        $this->callback(function (array $context): bool {
          return ($context['@index'] ?? NULL) === 'ys_beacon'
            && ($context['@length'] ?? NULL) === mb_strlen('How do I apply for housing?')
            && ($context['@message'] ?? NULL) === 'Azure 503';
        }),
      );

    $citations = $this->makeRetriever($index, $logger)->retrieve($question);
    $this->assertSame([], $citations);
  }

  /**
   * A missing or disabled index returns no citations without querying.
   *
   * @covers ::retrieve
   */
  public function testDisabledIndexReturnsEmptyWithoutLogging(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(FALSE);
    $index->expects($this->never())->method('query');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('error');

    $this->assertSame([], $this->makeRetriever($index, $logger)->retrieve('hi'));
  }

  /**
   * The retriever loads the configured Search API index, not a hardcoded one.
   *
   * @covers ::retrieve
   */
  public function testRetrieveLoadsConfiguredIndexId(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(FALSE);

    $storage = $this->createMock(EntityStorageInterface::class);
    // The configured id must be the one loaded.
    $storage->expects($this->once())
      ->method('load')
      ->with('internal_corpus')
      ->willReturn($index);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('search_api_index')->willReturn($storage);

    $configFactory = $this->getConfigFactoryStub([
      'ys_beacon.settings' => [
        'search_index_id' => 'internal_corpus',
        'top_k' => 5,
        'score_threshold' => 0.0,
      ],
    ]);

    $retriever = new RagRetriever($entityTypeManager, $configFactory, $this->createMock(LoggerInterface::class));
    $this->assertSame([], $retriever->retrieve('hello'));
  }

  /**
   * With no configured id, the retriever falls back to the default index.
   *
   * @covers ::retrieve
   */
  public function testRetrieveFallsBackToDefaultIndexId(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(FALSE);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('load')
      ->with('ys_beacon')
      ->willReturn($index);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('search_api_index')->willReturn($storage);

    $configFactory = $this->getConfigFactoryStub([
      'ys_beacon.settings' => ['top_k' => 5, 'score_threshold' => 0.0],
    ]);

    $retriever = new RagRetriever($entityTypeManager, $configFactory, $this->createMock(LoggerInterface::class));
    $this->assertSame([], $retriever->retrieve('hello'));
  }

  /**
   * Whole-index mode surfaces foreign chunks and access-checks own content.
   *
   * A chunk from another site in a shared collection keeps its "<site>:" prefix
   * and has no local entity; it is cited from index data alone. This site's own
   * chunk still resolves its entity and is access-checked. The backend's own
   * access check is bypassed for the query so foreign chunks are not dropped.
   *
   * @covers ::retrieve
   */
  public function testWholeIndexModeSurfacesForeignChunksAndAccessChecksOwn(): void {
    $local = $this->makeItem('entity:node/1:en', 'entity:node/1:en:entity_node_1_en_0', 'Local answer', 0.9);
    $foreign = $this->makeItem('siteb:entity:node/9:en', 'siteb:entity:node/9:en:siteb_entity_node_9_en_0', 'Alan is a professor.', 0.8, 'https://siteb.example/about/alan');

    $result_set = $this->createMock(ResultSetInterface::class);
    $result_set->method('getResultItems')->willReturn([$local, $foreign]);

    $bypassed = FALSE;
    $query = $this->createMock(QueryInterface::class);
    $query->method('keys')->willReturnSelf();
    $query->method('setOption')->willReturnCallback(function (string $name, $value) use (&$bypassed, $query) {
      if ($name === 'search_api_bypass_access' && $value === TRUE) {
        $bypassed = TRUE;
      }
      return $query;
    });
    $query->method('execute')->willReturn($result_set);

    // This site's node resolves, is viewable, and has no canonical link.
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('access')->with('view')->willReturn(TRUE);
    $entity->method('label')->willReturn('Local Node');
    $entity->method('hasLinkTemplate')->willReturn(FALSE);
    $wrapper = $this->createMock(ComplexDataInterface::class);
    $wrapper->method('getValue')->willReturn($entity);

    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->method('id')->willReturn('ys_beacon');
    $index->method('query')->willReturn($query);
    // Only this site's id resolves; the foreign id resolves to nothing.
    $index->method('loadItemsMultiple')->willReturn(['entity:node/1:en' => $wrapper]);

    $citations = $this->makeRetriever($index, $this->createMock(LoggerInterface::class), TRUE)->retrieve('Who is Alan?');

    $this->assertTrue($bypassed, 'Whole-index mode bypasses the backend access check.');
    $this->assertCount(2, $citations);
    $this->assertSame('Local Node', $citations[0]['title']);
    $this->assertSame('Local answer', $citations[0]['content']);
    // The foreign chunk is surfaced from index data: content and the owning
    // site's stored absolute URL, with no local title.
    $this->assertSame('', $citations[1]['title']);
    $this->assertSame('Alan is a professor.', $citations[1]['content']);
    $this->assertSame('https://siteb.example/about/alan', $citations[1]['url']);
  }

  /**
   * With whole-index querying off, a foreign chunk is never surfaced.
   *
   * Isolation is the default: even if a foreign chunk reaches the retriever, it
   * is dropped unless the site is explicitly configured to query the whole
   * collection. The backend access check is also left in place.
   *
   * @covers ::retrieve
   */
  public function testForeignChunkDroppedWhenWholeIndexOff(): void {
    $foreign = $this->makeItem('siteb:entity:node/9:en', 'siteb:entity:node/9:en:siteb_entity_node_9_en_0', 'Alan is a professor.', 0.8);

    $result_set = $this->createMock(ResultSetInterface::class);
    $result_set->method('getResultItems')->willReturn([$foreign]);

    $bypassed = FALSE;
    $query = $this->createMock(QueryInterface::class);
    $query->method('keys')->willReturnSelf();
    $query->method('setOption')->willReturnCallback(function (string $name, $value) use (&$bypassed, $query) {
      if ($name === 'search_api_bypass_access' && $value === TRUE) {
        $bypassed = TRUE;
      }
      return $query;
    });
    $query->method('execute')->willReturn($result_set);

    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->method('id')->willReturn('ys_beacon');
    $index->method('query')->willReturn($query);
    $index->method('loadItemsMultiple')->willReturn([]);

    $citations = $this->makeRetriever($index, $this->createMock(LoggerInterface::class), FALSE)->retrieve('Who is Alan?');

    $this->assertFalse($bypassed, 'Default mode keeps the backend access check.');
    $this->assertSame([], $citations);
  }

  /**
   * Builds a search_api result item stub.
   */
  private function makeItem(string $drupal_entity_id, string $id, string $content, float $score, string $url = ''): ItemInterface {
    $item = $this->createMock(ItemInterface::class);
    $item->method('getId')->willReturn($id);
    $item->method('getScore')->willReturn($score);
    $item->method('getExtraData')->willReturnCallback(function (string $key) use ($drupal_entity_id, $content, $url) {
      return match ($key) {
        'drupal_entity_id' => $drupal_entity_id,
        'content' => $content,
        'url' => $url,
        default => NULL,
      };
    });
    return $item;
  }

  /**
   * Builds a RagRetriever wired to the given index and logger.
   */
  private function makeRetriever(IndexInterface $index, LoggerInterface $logger, bool $query_entire_index = FALSE): RagRetriever {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('ys_beacon')->willReturn($index);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('search_api_index')->willReturn($storage);

    $configFactory = $this->getConfigFactoryStub([
      'ys_beacon.settings' => [
        'top_k' => 5,
        'score_threshold' => 0.0,
        'query_entire_index' => $query_entire_index,
      ],
    ]);

    return new RagRetriever($entityTypeManager, $configFactory, $logger);
  }

}
