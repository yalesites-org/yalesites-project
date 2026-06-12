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
    $aiDesc = isset($tags['ai_description']) ? $this->token->replace($tags['ai_description'], [$entity->getEntityTypeId() => $entity]) : "";
    $aiTags = isset($tags['ai_tags']) ? strip_tags($this->token->replace($tags['ai_tags'], [$entity->getEntityTypeId() => $entity])) : "";
    $aiDisableIndex = isset($tags['ai_disable_indexing']);

    return [
      'ai_description' => $aiDesc,
      'ai_tags' => $aiTags,
      'ai_disable_index' => $aiDisableIndex,
    ];
  }

}
