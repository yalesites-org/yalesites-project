<?php

namespace Drupal\ys_layouts\Traits;

use Drupal\node\Entity\Node;

/**
 * Provides methods to handle UUID and node retrieval.
 */
trait UuidTitleTrait {

  /**
   * Checks if the given title is a UUID.
   *
   * @param string $title
   *   The title to check.
   *
   * @return bool
   *   TRUE if the title is a UUID, FALSE otherwise.
   */
  protected function isUuid(string $title): bool {
    if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $title)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   *
   */
  protected function isId(string $title): bool {
    if (is_numeric($title)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Gets the node for the given UUID.
   *
   * @return \Drupal\node\NodeInterface|null
   *   The node entity if found, NULL otherwise.
   */
  protected function getNodeForUuid(string $uuid, $entityTypeManager) {
    if ($this->isId($uuid)) {
      $nodeStorage = $entityTypeManager->getStorage('node');
      /*$node = $nodeStorage->loadByProperties(['uuid' => $uuid]);*/
      $node = $nodeStorage->load($uuid);
      if ($node) {
        return $node;
        /*return reset($node);*/
      }
    }

    return NULL;
  }

  /**
   *
   */
  protected function getEntityNode($title, $entityTypeManager, $request) {
    /** @var \Drupal\node\Entity\Node $node */
    $node = $request->attributes->get('node');
    if (!($node instanceof Node)) {
      $node = $this->getNodeForUuid($title, $entityTypeManager);
    }

    return $node;
  }

}
