<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Utility\Token;
use Drupal\metatag\MetatagManager;

/**
 * Reads the AI metadata tag values stored on an entity.
 */
class AiMetadataManager {

  /**
   * Constructs an AiMetadataManager object.
   *
   * @param \Drupal\metatag\MetatagManager $metatagManager
   *   The metatag manager.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   */
  public function __construct(
    protected MetatagManager $metatagManager,
    protected Token $token,
  ) {
  }

  /**
   * Gets all custom AI metadata on an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to retrieve metadata from.
   *
   * @return array
   *   Metadata for specified entity.
   */
  public function getAiMetadata(ContentEntityInterface $entity) {
    $tags = $this->metatagManager->tagsFromEntity($entity);
    // Both values feed the AI model / search index as plain text, so strip any
    // markup from each consistently (previously only ai_tags was stripped).
    $aiDesc = isset($tags['ai_description']) ? strip_tags($this->token->replace($tags['ai_description'], [$entity->getEntityTypeId() => $entity])) : "";
    $aiTags = isset($tags['ai_tags']) ? strip_tags($this->token->replace($tags['ai_tags'], [$entity->getEntityTypeId() => $entity])) : "";
    $aiDisableIndex = isset($tags['ai_disable_indexing']);

    // A migrated description is bookkeeping rather than meaning, so drop it and
    // let the item take the same path as one that never had a description.
    if ($this->isMigrationMetadata($aiDesc)) {
      $aiDesc = "";
    }

    return [
      'ai_description' => $aiDesc,
      'ai_tags' => $aiTags,
      'ai_disable_index' => $aiDisableIndex,
    ];
  }

  /**
   * Checks whether a description is leftover migration metadata.
   *
   * Sites imported from the legacy your.yale.edu platform stored the importer's
   * own key/value pairs in this metatag instead of a description, typically
   * "source_url: https://your.yale.edu/media/3359/download?inline". The value
   * is mapped as contextual_content in ai_search.index.ys_beacon.yml, so it is
   * embedded into the vector for every chunk of the item, where a bare URL adds
   * noise and can pull the chunk toward unrelated URL-shaped queries.
   *
   * Bookkeeping is recognised as a "<word>_url:" key at the start of any line,
   * which covers every value counted by the audit published in
   * YaleSites-Internal#1581 (15 of the 16 described media on the sampled site)
   * as well as a key sitting on a later line of a multi-line blob
   * ("title: ...\nfile_url: ...").
   *
   * The line anchor is the load-bearing part, in both directions. Without it a
   * bare substring test would also discard genuine prose that happens to quote
   * the key ("Set the source_url: parameter ..."), and because the value is
   * dropped with no log and no admin signal that loss would be invisible. The
   * great majority of the platform is not migrated, so keeping real
   * descriptions intact matters more than catching an exotic bookkeeping shape.
   *
   * @param string $value
   *   The resolved ai_description value.
   *
   * @return bool
   *   TRUE when the value is importer bookkeeping rather than a description.
   */
  protected function isMigrationMetadata(string $value): bool {
    return preg_match('/^\s*\w+_url\s*:/mi', $value) === 1;
  }

}
