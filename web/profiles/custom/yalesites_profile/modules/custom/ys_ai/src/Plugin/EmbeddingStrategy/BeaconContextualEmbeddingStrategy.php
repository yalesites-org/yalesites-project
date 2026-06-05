<?php

namespace Drupal\ys_ai\Plugin\EmbeddingStrategy;

use Drupal\ai_search\Attribute\EmbeddingStrategy;
use Drupal\ai_search\Plugin\EmbeddingStrategy\ContextualEmbeddingStrategy;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\IndexInterface;

/**
 * Beacon embedding strategy that emits date attributes as ISO 8601 strings.
 *
 * The Beacon Azure AI Search index stores the node "created" and "changed"
 * fields as Edm.DateTimeOffset so they are human-readable and sort/filter as
 * real dates. Azure's DateTimeOffset only accepts ISO 8601 strings (for example
 * 2025-06-05T12:00:00Z), but the value reaching the index for a Search API
 * "date" field is an integer Unix timestamp, which Azure rejects.
 *
 * The conversion cannot be done with a Search API data type plugin: the
 * ai_search backend reports it supports no data types
 * (SearchApiAiSearchBackend::supportsDataType() only returns TRUE for
 * "embeddings"), so Search API downgrades every field to its fallback type
 * before a data type's getValue() ever runs. The item field handed to the
 * embedding strategy therefore reports its fallback type (string), not "date".
 *
 * So this strategy post-processes the metadata built by the stock
 * contextual-chunks strategy, detecting date attributes from the index's
 * *configured* field type (which is not downgraded) and reformatting their
 * still-numeric values to ISO 8601.
 */
#[EmbeddingStrategy(
  id: 'ys_beacon_contextual_chunks',
  label: new TranslatableMarkup('Beacon Enriched Embedding Strategy'),
  description: new TranslatableMarkup('The enriched contextual-chunks strategy, with date attributes formatted as ISO 8601 (Edm.DateTimeOffset) for the Beacon Azure AI Search index.'),
)]
class BeaconContextualEmbeddingStrategy extends ContextualEmbeddingStrategy {

  /**
   * {@inheritdoc}
   */
  public function buildBaseMetadata(array $fields, IndexInterface $index): array {
    $metadata = parent::buildBaseMetadata($fields, $index);

    // Collect the identifiers of fields configured as dates on the index. The
    // index entity keeps the configured type; only the per-item field handed in
    // via $fields is downgraded to its fallback type by the backend.
    foreach ($index->getFields() as $identifier => $field) {
      if ($field->getType() !== 'date') {
        continue;
      }
      // The parent emits date attributes as integer Unix timestamps; convert to
      // ISO 8601 (UTC) so Azure's Edm.DateTimeOffset fields accept them.
      if (isset($metadata[$identifier]) && is_numeric($metadata[$identifier])) {
        $metadata[$identifier] = gmdate('Y-m-d\TH:i:s\Z', (int) $metadata[$identifier]);
      }
    }

    return $metadata;
  }

}
