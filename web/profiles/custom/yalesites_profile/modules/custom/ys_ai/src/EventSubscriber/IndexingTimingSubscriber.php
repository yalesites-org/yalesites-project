<?php

namespace Drupal\ys_ai\EventSubscriber;

use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\search_api\Event\IndexingItemsEvent;
use Drupal\search_api\Event\ItemsIndexedEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Logs timing for Search API indexing batches and embedding API calls.
 */
class IndexingTimingSubscriber implements EventSubscriberInterface {

  /**
   * Number of embedding calls made in the current batch.
   */
  private int $embeddingCount = 0;

  /**
   * Total seconds spent in embedding API calls for the current batch.
   */
  private float $embeddingTotalTime = 0.0;

  /**
   * Per-embedding start timestamps, keyed by embedding count.
   */
  private array $embeddingTimers = [];

  /**
   * Timestamp when the current indexing batch started.
   */
  private float $batchStartTime = 0.0;

  /**
   * Number of items in the current indexing batch.
   */
  private int $batchItemCount = 0;

  public function __construct(private readonly LoggerChannelFactoryInterface $loggerFactory) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreGenerateResponseEvent::EVENT_NAME => 'onPreGenerate',
      PostGenerateResponseEvent::EVENT_NAME => 'onPostGenerate',
      SearchApiEvents::INDEXING_ITEMS => ['onIndexingItems', 100],
      SearchApiEvents::ITEMS_INDEXED => ['onItemsIndexed', 100],
    ];
  }

  /**
   * Records the start time for an embeddings operation.
   */
  public function onPreGenerate(PreGenerateResponseEvent $event): void {
    if ($event->getOperationType() !== 'embeddings') {
      return;
    }
    $this->embeddingCount++;
    $this->embeddingTimers[$this->embeddingCount] = microtime(TRUE);
  }

  /**
   * Calculates and logs duration for a completed embeddings operation.
   */
  public function onPostGenerate(PostGenerateResponseEvent $event): void {
    if ($event->getOperationType() !== 'embeddings') {
      return;
    }
    if (!isset($this->embeddingTimers[$this->embeddingCount])) {
      return;
    }
    $duration = microtime(TRUE) - $this->embeddingTimers[$this->embeddingCount];
    $this->embeddingTotalTime += $duration;
    unset($this->embeddingTimers[$this->embeddingCount]);
    $this->loggerFactory->get('ys_ai_timing')->info(
      'Embedding #@count: @duration ms (model: @model)',
      [
        '@count' => $this->embeddingCount,
        '@duration' => round($duration * 1000, 1),
        '@model' => $event->getModelId(),
      ]
    );
  }

  /**
   * Records batch start time and item count when indexing begins.
   */
  public function onIndexingItems(IndexingItemsEvent $event): void {
    $this->batchStartTime = microtime(TRUE);
    $this->batchItemCount = count($event->getItems());
    $this->embeddingTotalTime = 0.0;
    $this->loggerFactory->get('ys_ai_timing')->info(
      'Batch starting: @count items on index "@index"',
      [
        '@count' => $this->batchItemCount,
        '@index' => $event->getIndex()->id(),
      ]
    );
  }

  /**
   * Logs batch completion with a timing breakdown between API and storage.
   */
  public function onItemsIndexed(ItemsIndexedEvent $event): void {
    if (!$this->batchStartTime) {
      return;
    }
    $batchDuration = microtime(TRUE) - $this->batchStartTime;
    $this->loggerFactory->get('ys_ai_timing')->info(
      'Batch done: @indexed/@total items | Total: @batch ms | Embedding API: @embedding ms | Postgres+overhead: @postgres ms',
      [
        '@indexed' => count($event->getProcessedIds()),
        '@total' => $this->batchItemCount,
        '@batch' => round($batchDuration * 1000, 1),
        '@embedding' => round($this->embeddingTotalTime * 1000, 1),
        '@postgres' => round(($batchDuration - $this->embeddingTotalTime) * 1000, 1),
      ]
    );
    $this->batchStartTime = 0.0;
  }

}
