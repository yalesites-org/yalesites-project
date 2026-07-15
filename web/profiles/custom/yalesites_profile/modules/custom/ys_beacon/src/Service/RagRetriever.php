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
    $index_id = $settings->get('search_index_id') ?: 'ys_beacon';
    $whole_index = (bool) $settings->get('query_entire_index');

    /** @var \Drupal\search_api\IndexInterface|null $index */
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($index_id);
    if (!$index || !$index->status()) {
      return [];
    }

    try {
      $query = $index->query([
        'limit' => (int) ($settings->get('top_k') ?: 5),
      ]);
      $query->setOption('search_api_ai_get_chunks_result', TRUE);
      if ($whole_index) {
        // Other sites' chunks in a shared collection have no local entity, so
        // the backend's own per-result access check would drop them before they
        // ever reach us. Bypass it for this query only; this site's own chunks
        // are still access-checked per result below, and other sites' content
        // is trusted public (only public content is indexed).
        $query->setOption('search_api_bypass_access', TRUE);
      }
      $query->keys($question);
      $results = $query->execute();
    }
    catch (\Throwable $e) {
      // Log the failure with enough context to diagnose an outage (which index,
      // how long the question was) without recording the question text itself,
      // which can contain personal data. The user-facing path still degrades
      // quietly: an empty citation list means the assistant answers without
      // sources rather than surfacing an error.
      $this->logger->error('Beacon retrieval failed for index @index (question length @length): @message', [
        '@index' => $index->id(),
        '@length' => mb_strlen($question),
        '@message' => $e->getMessage(),
      ]);
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

    // Resolve entities only for this site's own chunks. A foreign chunk keeps
    // its "<site>:" prefix, so its id names no local datasource; loading it
    // would only log a "could not load" warning on every query.
    $own_items = array_filter($items, static fn ($item): bool => str_starts_with(
      (string) ($item->getExtraData('drupal_entity_id') ?: $item->getId()),
      'entity:',
    ));
    $entities = $this->loadEntities($index, $own_items);
    $citations = [];
    foreach ($items as $item) {
      $combined_id = (string) ($item->getExtraData('drupal_entity_id') ?: $item->getId());
      $content = trim((string) $item->getExtraData('content'));

      // A chunk from another site in a shared collection keeps its "<site>:"
      // prefix (the provider only strips this site's own prefix), so it never
      // starts with "entity:" and has no local entity to resolve. Cite it from
      // index data alone - content plus the owning site's absolute URL, stored
      // on the document at index time - and only when this site is configured
      // to query the whole collection; its content is trusted public.
      if (!str_starts_with($combined_id, 'entity:')) {
        if (!$whole_index) {
          continue;
        }
        $title = '';
        $url = ((string) $item->getExtraData('url')) ?: NULL;
      }
      else {
        $entity = $entities[$combined_id] ?? NULL;
        // Never quote content the current visitor cannot view. The AI Search
        // backend access-checks results too (unless bypassed for whole-index
        // reads); this guards the window where a chunk is still in the vector
        // database after its entity was deleted, unpublished, or restricted.
        if (!$entity || !$entity->access('view')) {
          continue;
        }
        $title = (string) $entity->label();
        $url = $this->getEntityUrl($entity);
      }

      $citations[] = [
        'content' => $content,
        'id' => $item->getId(),
        'title' => $title,
        'filepath' => NULL,
        'url' => $url,
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
