<?php

namespace Drupal\Tests\ys_contoso_chat\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Drupal\ys_contoso_chat\Plugin\AiFunctionCall\BeaconRagTool;
use Drupal\ys_contoso_chat\Service\CitationStore;

/**
 * @coversDefaultClass \Drupal\ys_contoso_chat\Plugin\AiFunctionCall\BeaconRagTool
 * @group yalesites
 */
class BeaconRagToolTest extends UnitTestCase {

  /**
   * Builds a mocked search result item exposing extra data via getExtraData().
   *
   * @param float $score
   *   The result score.
   * @param array $extra
   *   Keyed extra-data values (content, title_1, url_1, type, drupal_long_id).
   *
   * @return \Drupal\search_api\Item\ItemInterface
   *   The mocked result item.
   */
  protected function item(float $score, array $extra): ItemInterface {
    $item = $this->createMock(ItemInterface::class);
    $item->method('getScore')->willReturn($score);
    $item->method('getExtraData')->willReturnCallback(
      static fn(string $key) => $extra[$key] ?? NULL
    );
    return $item;
  }

  /**
   * Builds the tool under test wired to a query returning the given items.
   *
   * @param \Drupal\search_api\Item\ItemInterface[] $items
   *   The result items the query should return.
   * @param \Drupal\ys_contoso_chat\Service\CitationStore $store
   *   The citation store to inject.
   * @param float $min_score
   *   The forced minimum score context value.
   *
   * @return \Drupal\ys_contoso_chat\Plugin\AiFunctionCall\BeaconRagTool
   *   The configured tool.
   */
  protected function toolReturning(array $items, CitationStore $store, float $min_score = 0.5): BeaconRagTool {
    $result_set = $this->createMock(ResultSetInterface::class);
    $result_set->method('getResultItems')->willReturn($items);

    $query = $this->createMock(QueryInterface::class);
    $query->method('setOption')->willReturnSelf();
    $query->method('keys')->willReturnSelf();
    $query->method('execute')->willReturn($result_set);

    $index = $this->createMock(IndexInterface::class);
    $index->method('query')->willReturn($query);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('beacon_index')->willReturn($index);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('search_api_index')->willReturn($storage);

    return new class($etm, $store, $min_score) extends BeaconRagTool {

      /**
       * Constructs the test double without the plugin machinery.
       */
      public function __construct(EntityTypeManagerInterface $etm, CitationStore $store, protected float $minScore) {
        $this->entityTypeManager = $etm;
        $this->citationStore = $store;
      }

      /**
       * {@inheritdoc}
       */
      public function getContextValue($name) {
        return match ($name) {
          'index' => 'beacon_index',
          'search_string' => 'admissions deadline',
          'amount' => 10,
          'min_score' => $this->minScore,
          default => NULL,
        };
      }

    };
  }

  /**
   * @covers ::execute
   */
  public function testLabelsResultsAndCollectsCitationsInOrder(): void {
    $store = new CitationStore();
    $tool = $this->toolReturning([
      $this->item(0.9, [
        'content' => 'Apply by May 1.',
        'title_1' => 'Admissions',
        'url_1' => 'https://example.com/admissions',
        'type' => 'page',
        'drupal_long_id' => 'node/1:0',
      ]),
      $this->item(0.7, [
        'content' => 'Deadlines vary by program.',
        'title_1' => 'Programs',
        'url_1' => 'https://example.com/programs',
        'type' => 'post',
        'drupal_long_id' => 'node/2:0',
      ]),
    ], $store);

    $tool->execute();
    $output = $tool->getReadableOutput();

    // Markers appear in result order.
    $this->assertStringContainsString('[doc1]', $output);
    $this->assertStringContainsString('[doc2]', $output);
    $this->assertLessThan(strpos($output, '[doc2]'), strpos($output, '[doc1]'));

    // Citations align 1:1 with the markers, in order.
    $citations = $store->getCitations();
    $this->assertCount(2, $citations);
    $this->assertSame('1', $citations[0]['id']);
    $this->assertSame('Admissions', $citations[0]['title']);
    $this->assertSame('https://example.com/admissions', $citations[0]['url']);
    $this->assertSame('page', $citations[0]['metadata']);
    $this->assertSame('node/1:0', $citations[0]['chunk_id']);
    $this->assertSame('2', $citations[1]['id']);
    $this->assertSame('https://example.com/programs', $citations[1]['url']);
  }

  /**
   * @covers ::execute
   */
  public function testMinScoreFilteringKeepsSequentialNumbering(): void {
    $store = new CitationStore();
    $tool = $this->toolReturning([
      $this->item(0.2, ['content' => 'Too weak.', 'title_1' => 'Weak', 'url_1' => 'https://example.com/weak']),
      $this->item(0.8, ['content' => 'Strong match.', 'title_1' => 'Strong', 'url_1' => 'https://example.com/strong']),
    ], $store, 0.5);

    $tool->execute();
    $citations = $store->getCitations();

    // Only the above-threshold result is kept, and it is numbered doc1.
    $this->assertCount(1, $citations);
    $this->assertSame('1', $citations[0]['id']);
    $this->assertSame('Strong', $citations[0]['title']);
    $this->assertStringContainsString('[doc1]', $tool->getReadableOutput());
    $this->assertStringNotContainsString('[doc2]', $tool->getReadableOutput());
  }

  /**
   * @covers ::execute
   */
  public function testNoResultsLeavesStoreEmpty(): void {
    $store = new CitationStore();
    $tool = $this->toolReturning([], $store);

    $tool->execute();

    $this->assertFalse($store->hasCitations());
    $this->assertStringContainsString('No results were found', $tool->getReadableOutput());
  }

}
