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
use Drupal\ys_beacon\Service\EntityCitationResolver;
use Drupal\ys_beacon\Service\RagRetriever;
use Psr\Log\LoggerInterface;

/**
 * Tests Beacon RAG retrieval: failure logging, guards, and citation building.
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

    $retriever = new RagRetriever($entityTypeManager, $configFactory, $this->createMock(LoggerInterface::class), $this->createMock(EntityCitationResolver::class));
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

    $retriever = new RagRetriever($entityTypeManager, $configFactory, $this->createMock(LoggerInterface::class), $this->createMock(EntityCitationResolver::class));
    $this->assertSame([], $retriever->retrieve('hello'));
  }

  /**
   * A read-only (shared) index cites from stored fields, bypassing access.
   *
   * The cited document belongs to another site and has no local entity, so no
   * entity is loaded and the query runs with access bypassed.
   *
   * @covers ::retrieve
   * @covers ::buildStoredCitations
   */
  public function testReadOnlyIndexBuildsCitationsFromStoredFields(): void {
    $item = $this->createMock(ItemInterface::class);
    $item->method('getScore')->willReturn(0.9);
    $item->method('getId')->willReturn('entity:node/501:0');
    $item->method('getExtraData')->willReturnCallback(fn (string $key) => match ($key) {
      'content' => 'Housing applications open in March.',
      'citation_title' => 'Housing Guide',
      'citation_url' => 'https://owner.example.edu/housing',
      default => NULL,
    });

    $results = $this->createMock(ResultSetInterface::class);
    $results->method('getResultItems')->willReturn([$item]);

    $options = [];
    $query = $this->createMock(QueryInterface::class);
    $query->method('setOption')->willReturnCallback(function (string $key, $value) use ($query, &$options) {
      $options[$key] = $value;
      return $query;
    });
    $query->method('keys')->willReturnSelf();
    $query->method('execute')->willReturn($results);

    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->method('id')->willReturn('ys_beacon');
    $index->method('isReadOnly')->willReturn(TRUE);
    $index->method('query')->willReturn($query);
    // A borrowed index must never load local entities for its citations.
    $index->expects($this->never())->method('loadItemsMultiple');

    // The resolver serves the entity path only; unused for shared content.
    $resolver = $this->createMock(EntityCitationResolver::class);
    $resolver->expects($this->never())->method('title');
    $resolver->expects($this->never())->method('url');

    $citations = $this->makeRetriever($index, $this->createMock(LoggerInterface::class), $resolver)
      ->retrieve('How do I apply for housing?');

    $this->assertCount(1, $citations);
    $this->assertSame('Housing Guide', $citations[0]['title']);
    $this->assertSame('https://owner.example.edu/housing', $citations[0]['url']);
    $this->assertSame('Housing applications open in March.', $citations[0]['content']);
    // Access is bypassed because shared documents have no local access grants.
    $this->assertArrayHasKey('search_api_bypass_access', $options);
    $this->assertTrue($options['search_api_bypass_access']);
  }

  /**
   * A read-only citation keeps the chunk even when stored fields are missing.
   *
   * Documents indexed before the citation fields existed have no stored title;
   * they must still surface (with a fallback title) rather than be dropped.
   *
   * @covers ::buildStoredCitations
   */
  public function testReadOnlyStoredCitationFallsBackWhenFieldsMissing(): void {
    $item = $this->createMock(ItemInterface::class);
    $item->method('getScore')->willReturn(0.7);
    $item->method('getId')->willReturn('entity:node/777:0');
    $item->method('getExtraData')->willReturnCallback(fn (string $key) => $key === 'content' ? 'Orphan chunk text.' : NULL);

    $results = $this->createMock(ResultSetInterface::class);
    $results->method('getResultItems')->willReturn([$item]);

    $query = $this->createMock(QueryInterface::class);
    $query->method('setOption')->willReturnSelf();
    $query->method('keys')->willReturnSelf();
    $query->method('execute')->willReturn($results);

    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->method('id')->willReturn('ys_beacon');
    $index->method('isReadOnly')->willReturn(TRUE);
    $index->method('query')->willReturn($query);

    $citations = $this->makeRetriever($index, $this->createMock(LoggerInterface::class))
      ->retrieve('anything');

    $this->assertCount(1, $citations);
    $this->assertSame('Source', $citations[0]['title']);
    $this->assertNull($citations[0]['url']);
    $this->assertSame('Orphan chunk text.', $citations[0]['content']);
  }

  /**
   * Stored title/URL are un-escaped from the ai_search markdown conversion.
   *
   * The ai_search indexer runs "attributes" fields through an HTML-to-Markdown
   * converter, backslash-escaping punctuation and HTML-encoding entities. The
   * read path must undo that so the citation URL is not corrupted (a stored
   * "annual\_report" would otherwise 404).
   *
   * @covers ::buildStoredCitations
   * @covers ::decodeStoredValue
   */
  public function testReadOnlyStoredCitationDecodesEscapedFields(): void {
    $item = $this->createMock(ItemInterface::class);
    $item->method('getScore')->willReturn(0.9);
    $item->method('getId')->willReturn('entity:node/12:0');
    $item->method('getExtraData')->willReturnCallback(fn (string $key) => match ($key) {
      'content' => 'Chunk text.',
      'citation_title' => 'R&amp;D Report\_final',
      'citation_url' => 'https://owner.edu/files/annual\_report\_2024.pdf?a=1&amp;b=2',
      default => NULL,
    });

    $results = $this->createMock(ResultSetInterface::class);
    $results->method('getResultItems')->willReturn([$item]);

    $query = $this->createMock(QueryInterface::class);
    $query->method('setOption')->willReturnSelf();
    $query->method('keys')->willReturnSelf();
    $query->method('execute')->willReturn($results);

    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->method('id')->willReturn('ys_beacon');
    $index->method('isReadOnly')->willReturn(TRUE);
    $index->method('query')->willReturn($query);

    $citations = $this->makeRetriever($index, $this->createMock(LoggerInterface::class))
      ->retrieve('report');

    $this->assertCount(1, $citations);
    $this->assertSame('R&D Report_final', $citations[0]['title']);
    $this->assertSame('https://owner.edu/files/annual_report_2024.pdf?a=1&b=2', $citations[0]['url']);
  }

  /**
   * A writable index cites from the loaded local entity via the resolver.
   *
   * @covers ::retrieve
   * @covers ::buildEntityCitations
   */
  public function testWritableIndexBuildsCitationsFromEntity(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('access')->with('view')->willReturn(TRUE);

    $typed = $this->createMock(ComplexDataInterface::class);
    $typed->method('getValue')->willReturn($entity);

    $item = $this->createMock(ItemInterface::class);
    $item->method('getScore')->willReturn(0.8);
    $item->method('getId')->willReturn('entity:node/9:0');
    $item->method('getExtraData')->willReturnCallback(fn (string $key) => match ($key) {
      'content' => 'Local chunk text.',
      'drupal_entity_id' => 'entity:node/9',
      default => NULL,
    });

    $results = $this->createMock(ResultSetInterface::class);
    $results->method('getResultItems')->willReturn([$item]);

    $options = [];
    $query = $this->createMock(QueryInterface::class);
    $query->method('setOption')->willReturnCallback(function (string $key, $value) use ($query, &$options) {
      $options[$key] = $value;
      return $query;
    });
    $query->method('keys')->willReturnSelf();
    $query->method('execute')->willReturn($results);

    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->method('id')->willReturn('ys_beacon');
    $index->method('isReadOnly')->willReturn(FALSE);
    $index->method('query')->willReturn($query);
    $index->method('loadItemsMultiple')->with(['entity:node/9'])->willReturn(['entity:node/9' => $typed]);

    $resolver = $this->createMock(EntityCitationResolver::class);
    $resolver->method('title')->with($entity)->willReturn('My Page');
    $resolver->method('url')->with($entity)->willReturn('https://site.example/my-page');

    $citations = $this->makeRetriever($index, $this->createMock(LoggerInterface::class), $resolver)
      ->retrieve('question');

    $this->assertCount(1, $citations);
    $this->assertSame('My Page', $citations[0]['title']);
    $this->assertSame('https://site.example/my-page', $citations[0]['url']);
    $this->assertSame('Local chunk text.', $citations[0]['content']);
    // A writable index must NOT bypass access; the per-visitor check stays.
    $this->assertArrayNotHasKey('search_api_bypass_access', $options);
  }

  /**
   * A writable index never quotes content the current visitor cannot view.
   *
   * @covers ::buildEntityCitations
   */
  public function testWritableIndexDropsInaccessibleEntity(): void {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('access')->with('view')->willReturn(FALSE);

    $typed = $this->createMock(ComplexDataInterface::class);
    $typed->method('getValue')->willReturn($entity);

    $item = $this->createMock(ItemInterface::class);
    $item->method('getScore')->willReturn(0.8);
    $item->method('getId')->willReturn('entity:node/9:0');
    $item->method('getExtraData')->willReturnCallback(fn (string $key) => match ($key) {
      'content' => 'Restricted chunk text.',
      'drupal_entity_id' => 'entity:node/9',
      default => NULL,
    });

    $results = $this->createMock(ResultSetInterface::class);
    $results->method('getResultItems')->willReturn([$item]);

    $options = [];
    $query = $this->createMock(QueryInterface::class);
    $query->method('setOption')->willReturnCallback(function (string $key, $value) use ($query, &$options) {
      $options[$key] = $value;
      return $query;
    });
    $query->method('keys')->willReturnSelf();
    $query->method('execute')->willReturn($results);

    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->method('id')->willReturn('ys_beacon');
    $index->method('isReadOnly')->willReturn(FALSE);
    $index->method('query')->willReturn($query);
    $index->method('loadItemsMultiple')->with(['entity:node/9'])->willReturn(['entity:node/9' => $typed]);

    $citations = $this->makeRetriever($index, $this->createMock(LoggerInterface::class))->retrieve('question');
    $this->assertSame([], $citations);
    // Even while dropping content, the writable path never bypasses access.
    $this->assertArrayNotHasKey('search_api_bypass_access', $options);
  }

  /**
   * A writable site querying the whole collection mixes own and foreign chunks.
   *
   * With query_entire_index on, the backend access check is bypassed and each
   * result is cited by kind: this site's own chunk (id "entity:...") via its
   * local entity/resolver, and another site's chunk (id "<site>:...", no local
   * entity) from the title/URL stored on the document. Relevance order holds.
   *
   * @covers ::retrieve
   * @covers ::buildMixedCitations
   */
  public function testWholeIndexModeMixesOwnAndForeignCitations(): void {
    $own = $this->makeItem('entity:node/1:en', 'entity:node/1:en:entity_node_1_en_0', 'Local answer', 0.9);
    $foreign = $this->makeItem('siteb:entity:node/9:en', 'siteb:entity:node/9:en:siteb_entity_node_9_en_0', 'Alan is a professor.', 0.8, 'About Alan', 'https://siteb.example/about/alan');

    $results = $this->createMock(ResultSetInterface::class);
    $results->method('getResultItems')->willReturn([$own, $foreign]);

    $options = [];
    $query = $this->createMock(QueryInterface::class);
    $query->method('setOption')->willReturnCallback(function (string $key, $value) use ($query, &$options) {
      $options[$key] = $value;
      return $query;
    });
    $query->method('keys')->willReturnSelf();
    $query->method('execute')->willReturn($results);

    // This site's node resolves and is viewable; the foreign id has none.
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('access')->with('view')->willReturn(TRUE);
    $typed = $this->createMock(ComplexDataInterface::class);
    $typed->method('getValue')->willReturn($entity);

    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->method('id')->willReturn('ys_beacon');
    $index->method('isReadOnly')->willReturn(FALSE);
    $index->method('query')->willReturn($query);
    // Only this site's own chunk is entity-loaded; the foreign id is not.
    $index->method('loadItemsMultiple')->willReturn(['entity:node/1:en' => $typed]);

    $resolver = $this->createMock(EntityCitationResolver::class);
    $resolver->method('title')->with($entity)->willReturn('Local Node');
    $resolver->method('url')->with($entity)->willReturn('https://site.example/local');

    $citations = $this->makeRetriever($index, $this->createMock(LoggerInterface::class), $resolver, TRUE)
      ->retrieve('Who is Alan?');

    // Whole-index mode bypasses the backend check so foreign chunks survive.
    $this->assertArrayHasKey('search_api_bypass_access', $options);
    $this->assertTrue($options['search_api_bypass_access']);
    $this->assertCount(2, $citations);
    // Own chunk cited via the local entity, in relevance order.
    $this->assertSame('Local Node', $citations[0]['title']);
    $this->assertSame('https://site.example/local', $citations[0]['url']);
    $this->assertSame('Local answer', $citations[0]['content']);
    // Foreign chunk cited from the stored citation fields.
    $this->assertSame('About Alan', $citations[1]['title']);
    $this->assertSame('https://siteb.example/about/alan', $citations[1]['url']);
    $this->assertSame('Alan is a professor.', $citations[1]['content']);
  }

  /**
   * A writable site with whole-index off stays site-scoped and drops foreign.
   *
   * Isolation is the default: access is not bypassed, and a foreign chunk that
   * has no local entity is dropped rather than surfaced.
   *
   * @covers ::retrieve
   */
  public function testWritableWithoutWholeIndexDropsForeignChunk(): void {
    $foreign = $this->makeItem('siteb:entity:node/9:en', 'siteb:entity:node/9:en:siteb_entity_node_9_en_0', 'Alan is a professor.', 0.8, 'About Alan', 'https://siteb.example/about/alan');

    $results = $this->createMock(ResultSetInterface::class);
    $results->method('getResultItems')->willReturn([$foreign]);

    $options = [];
    $query = $this->createMock(QueryInterface::class);
    $query->method('setOption')->willReturnCallback(function (string $key, $value) use ($query, &$options) {
      $options[$key] = $value;
      return $query;
    });
    $query->method('keys')->willReturnSelf();
    $query->method('execute')->willReturn($results);

    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn(TRUE);
    $index->method('id')->willReturn('ys_beacon');
    $index->method('isReadOnly')->willReturn(FALSE);
    $index->method('query')->willReturn($query);
    $index->method('loadItemsMultiple')->willReturn([]);

    $citations = $this->makeRetriever($index, $this->createMock(LoggerInterface::class))->retrieve('Who is Alan?');

    $this->assertArrayNotHasKey('search_api_bypass_access', $options);
    $this->assertSame([], $citations);
  }

  /**
   * Builds a search_api result item stub with optional stored citation fields.
   */
  private function makeItem(string $drupal_entity_id, string $id, string $content, float $score, string $citation_title = '', string $citation_url = ''): ItemInterface {
    $item = $this->createMock(ItemInterface::class);
    $item->method('getId')->willReturn($id);
    $item->method('getScore')->willReturn($score);
    $item->method('getExtraData')->willReturnCallback(function (string $key) use ($drupal_entity_id, $content, $citation_title, $citation_url) {
      return match ($key) {
        'drupal_entity_id' => $drupal_entity_id,
        'content' => $content,
        'citation_title' => $citation_title,
        'citation_url' => $citation_url,
        default => NULL,
      };
    });
    return $item;
  }

  /**
   * Builds a RagRetriever wired to the given index, logger, and resolver.
   */
  private function makeRetriever(IndexInterface $index, LoggerInterface $logger, ?EntityCitationResolver $resolver = NULL, bool $query_entire_index = FALSE): RagRetriever {
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

    return new RagRetriever($entityTypeManager, $configFactory, $logger, $resolver ?? $this->createMock(EntityCitationResolver::class));
  }

}
