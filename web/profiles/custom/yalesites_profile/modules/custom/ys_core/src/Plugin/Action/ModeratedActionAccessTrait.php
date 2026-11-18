<?php

namespace Drupal\ys_core\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * Shared access check for the moderated publish and unpublish actions.
 */
trait ModeratedActionAccessTrait {

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $key = $object->getEntityType()->getKey('published');

    /** @var \Drupal\Core\Entity\EntityInterface $object */
    $update = $object->access('update', $account, TRUE);
    $published = $object->$key->access('edit', $account, TRUE);
    $result = $update->andIf($published);

    // Content moderation forbids editing the published field directly. These
    // actions change it through the moderation state instead, so an editor who
    // can update a moderated entity is allowed. Checking update access and
    // moderation separately avoids matching core's error message text.
    if (!$result->isAllowed()
      && $update->isAllowed()
      && \Drupal::service('content_moderation.moderation_information')->isModeratedEntity($object)) {
      $result = AccessResult::allowed()
        ->addCacheableDependency($update)
        ->addCacheableDependency($published);
    }

    return $return_as_object ? $result : $result->isAllowed();
  }

}
