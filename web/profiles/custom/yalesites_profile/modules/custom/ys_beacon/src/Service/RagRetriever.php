<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\media\MediaInterface;
use Drupal\search_api\IndexInterface;
use Psr\Log\LoggerInterface;

/**
 * Retrieves relevant content chunks from the Beacon vector index.
 *
 * Queries the ys_beacon Search API index (AI Search backend) in chunked
 * result mode and maps each chunk to a citation structure understood by the
 * Beacon chat frontend.
 */
class RagRetriever {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {
  }

  /**
   * Retrieves citations for a question.
   *
   * @param string $question
   *   The end-user question to search for.
   *
   * @return array[]
   *   Citation arrays with the keys content, id, title, filepath, url,
   *   metadata, chunk_id and reindex_id, ordered by relevance.
   */
  public function retrieve(string $question): array {
    $settings = $this->configFactory->get('ys_beacon.settings');

    /** @var \Drupal\search_api\IndexInterface|null $index */
    $index = $this->entityTypeManager->getStorage('search_api_index')->load('ys_beacon');
    if (!$index || !$index->status()) {
      return [];
    }

    try {
      $query = $index->query([
        'limit' => (int) ($settings->get('top_k') ?: 5),
      ]);
      $query->setOption('search_api_ai_get_chunks_result', TRUE);
      $query->keys($question);
      $results = $query->execute();
    }
    catch (\Throwable $e) {
      $this->logger->error('Beacon retrieval failed: @message', ['@message' => $e->getMessage()]);
      return [];
    }

    // The score threshold is applied here rather than via the ai_search
    // score-threshold processor because the value is tuned per site in
    // config-ignored Beacon settings, while index processor configuration is
    // platform-wide synced config.
    $threshold = (float) ($settings->get('score_threshold') ?: 0);
    $items = [];
    foreach ($results->getResultItems() as $item) {
      if ($threshold > 0 && $item->getScore() < $threshold) {
        continue;
      }
      if (trim((string) $item->getExtraData('content')) === '') {
        continue;
      }
      $items[] = $item;
    }

    $entities = $this->loadEntities($index, $items);
    $citations = [];
    foreach ($items as $item) {
      $combined_id = (string) ($item->getExtraData('drupal_entity_id') ?: $item->getId());
      $entity = $entities[$combined_id] ?? NULL;
      // Never quote content the current visitor cannot view. The AI Search
      // backend access-checks results too; this guards the window where a
      // chunk is still in the vector database after its entity was deleted,
      // unpublished, or access-restricted.
      if (!$entity || !$entity->access('view')) {
        continue;
      }
      $citations[] = [
        'content' => trim((string) $item->getExtraData('content')),
        'id' => $item->getId(),
        'title' => $entity->label(),
        'filepath' => NULL,
        'url' => $this->getEntityUrl($entity),
        'metadata' => NULL,
        'chunk_id' => $item->getId(),
        'reindex_id' => NULL,
      ];
    }

    return $citations;
  }

  /**
   * Batch-loads the content entities behind chunked result items.
   *
   * Chunks of the same entity share one load, and all datasource loads are
   * grouped through Search API's multi-load.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The Beacon index.
   * @param \Drupal\search_api\Item\ItemInterface[] $items
   *   The filtered result items.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   Entities keyed by combined item id.
   */
  protected function loadEntities(IndexInterface $index, array $items): array {
    $combined_ids = [];
    foreach ($items as $item) {
      $combined_ids[] = (string) ($item->getExtraData('drupal_entity_id') ?: $item->getId());
    }

    $entities = [];
    try {
      foreach ($index->loadItemsMultiple(array_unique($combined_ids)) as $combined_id => $object) {
        $entity = $object->getValue();
        if ($entity instanceof ContentEntityInterface) {
          $entities[$combined_id] = $entity;
        }
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Beacon could not load entities for results: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
    return $entities;
  }

  /**
   * Builds the citation URL for an entity.
   *
   * Media items link directly to their source file when possible, matching
   * the legacy ai_engine feed behavior; everything else uses the canonical
   * entity URL.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to link to.
   *
   * @return string|null
   *   An absolute URL, or NULL when none can be generated.
   */
  protected function getEntityUrl(ContentEntityInterface $entity): ?string {
    try {
      if ($entity instanceof MediaInterface) {
        $fid = $entity->getSource()->getSourceFieldValue($entity);
        if ($fid && is_numeric($fid)) {
          $file = $this->entityTypeManager->getStorage('file')->load($fid);
          if ($file) {
            return $file->createFileUrl(FALSE);
          }
        }
      }
      if ($entity->hasLinkTemplate('canonical')) {
        return $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
      }
    }
    catch (\Throwable $e) {
      // Fall through to NULL: a citation without a link is still usable.
    }
    return NULL;
  }

}
