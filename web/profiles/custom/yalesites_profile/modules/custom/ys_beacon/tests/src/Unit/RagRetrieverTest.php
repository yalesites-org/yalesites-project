<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Service\RagRetriever;
use Psr\Log\LoggerInterface;

/**
 * Tests Beacon RAG retrieval failure logging and empty-index guards.
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
   * Builds a RagRetriever wired to the given index and logger.
   */
  private function makeRetriever(IndexInterface $index, LoggerInterface $logger): RagRetriever {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with('ys_beacon')->willReturn($index);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('search_api_index')->willReturn($storage);

    $configFactory = $this->getConfigFactoryStub([
      'ys_beacon.settings' => ['top_k' => 5, 'score_threshold' => 0.0],
    ]);

    return new RagRetriever($entityTypeManager, $configFactory, $logger);
  }

}
