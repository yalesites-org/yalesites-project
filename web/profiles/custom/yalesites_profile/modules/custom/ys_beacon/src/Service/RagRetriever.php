<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
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
    protected EntityCitationResolver $citationResolver,
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

    /** @var \Drupal\search_api\IndexInterface|null $index */
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($index_id);
    if (!$index || !$index->status()) {
      return [];
    }

    // A read-only site borrows another site's (shared) collection: the cited
    // documents belong to other sites and have no local entity to load or
    // access-check, so citations are built from the title and URL stored on
    // each document instead. This is only safe because protected content is
    // never written to the index (the Beacon indexing security hardening is the
    // sole safeguard once per-visitor access is bypassed for shared retrieval).
    $read_only = $index->isReadOnly();

    try {
      $query = $index->query([
        'limit' => (int) ($settings->get('top_k') ?: 5),
      ]);
      $query->setOption('search_api_ai_get_chunks_result', TRUE);
      if ($read_only) {
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

    return $read_only
      ? $this->buildStoredCitations($items)
      : $this->buildEntityCitations($index, $items);
  }

  /**
   * Builds citations from the title/URL stored on each document.
   *
   * Used for a read-only (shared/borrowed) index whose documents belong to
   * other sites and cannot be loaded as local entities. Access is not
   * re-checked here: the write-side invariant that protected content is never
   * indexed is the only safeguard for shared retrieval.
   *
   * @param \Drupal\search_api\Item\ItemInterface[] $items
   *   The filtered result items.
   *
   * @return array[]
   *   Citation arrays.
   */
  protected function buildStoredCitations(array $items): array {
    $citations = [];
    foreach ($items as $item) {
      $title = $this->decodeStoredValue((string) $item->getExtraData('citation_title'));
      $url = $this->decodeStoredValue((string) $item->getExtraData('citation_url'));
      $citations[] = $this->buildCitation(
        $item,
        // Fall back to a generic label only for documents indexed before the
        // citation fields existed; a re-indexed corpus always stores a title.
        $title !== '' ? $title : 'Source',
        $url !== '' ? $url : NULL,
      );
    }
    return $citations;
  }

  /**
   * Builds citations by loading each chunk's local entity.
   *
   * Used for a site's own writable index, where the current visitor's view
   * access is enforced per result and chunks whose entity has been deleted,
   * unpublished, or access-restricted are dropped.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The Beacon index.
   * @param \Drupal\search_api\Item\ItemInterface[] $items
   *   The filtered result items.
   *
   * @return array[]
   *   Citation arrays.
   */
  protected function buildEntityCitations(IndexInterface $index, array $items): array {
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
      $citations[] = $this->buildCitation(
        $item,
        $this->citationResolver->title($entity),
        $this->citationResolver->url($entity),
      );
    }

    return $citations;
  }

  /**
   * Assembles a citation array from an item plus its title and URL.
   *
   * The shape is the contract consumed by the chat frontend and the AI tester
   * (see retrieve()); keeping it in one place guarantees the stored-field and
   * local-entity paths emit identically-shaped citations.
   *
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The result item supplying content and ids.
   * @param string $title
   *   The citation title.
   * @param string|null $url
   *   The citation URL, or NULL when none is available.
   *
   * @return array
   *   A citation array.
   */
  protected function buildCitation(ItemInterface $item, string $title, ?string $url): array {
    return [
      'content' => trim((string) $item->getExtraData('content')),
      'id' => $item->getId(),
      'title' => $title,
      'filepath' => NULL,
      'url' => $url,
      'metadata' => NULL,
      'chunk_id' => $item->getId(),
      'reindex_id' => NULL,
    ];
  }

  /**
   * Reverses the escaping the ai_search indexer applies to stored attributes.
   *
   * Retrievable "attributes" fields (citation_title/citation_url) pass through
   * the ai_search embedding strategy's HTML-to-Markdown converter at index time
   * (EmbeddingBase::getValue), which backslash-escapes Markdown punctuation
   * ("annual_report" -> "annual\_report") and HTML-encodes entities
   * ("&" -> "&amp;"). Undo both so a stored citation title/URL matches the
   * value the local-entity path derives live. (Removable once Beacon owns the
   * write seam and can store these fields raw.)
   *
   * @param string $value
   *   The stored (escaped) field value.
   *
   * @return string
   *   The decoded value.
   */
  protected function decodeStoredValue(string $value): string {
    if ($value === '') {
      return '';
    }
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
    // Strip a single backslash placed before any ASCII punctuation character.
    return preg_replace('/\\\\([\x21-\x2f\x3a-\x40\x5b-\x60\x7b-\x7e])/', '$1', $value);
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

}
