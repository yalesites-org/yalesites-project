<?php

namespace Drupal\ys_core\Plugin\Action;

use Drupal\Core\Action\Plugin\Action\UnpublishAction;
use Drupal\Core\Session\AccountInterface;

/**
 * Unpublishes an entity.
 *
 * @Action(
 *   id = "entity:unpublish_moderated_action",
 *   action_label = @Translation("Unpublish Moderated"),
 *   deriver = "Drupal\Core\Action\Plugin\Action\Derivative\EntityPublishedActionDeriver",
 * )
 */
class ModeratedUnpublish extends UnpublishAction {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    $entity->set('moderation_state', 'archived');
    $entity->setUnpublished()->save();
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    $key = $object->getEntityType()->getKey('published');

    /** @var \Drupal\Core\Entity\EntityInterface $object */
    $result = $object->access('update', $account, TRUE);

    return $return_as_object ? $result : $result->isAllowed();
  }

}
