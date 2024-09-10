<?php

namespace Drupal\ys_core\Plugin\Action;

use Drupal\Core\Action\Plugin\Action\PublishAction;
use Drupal\Core\Session\AccountInterface;

/**
 * Unpublishes an entity.
 *
 * @Action(
 *   id = "entity:publish_moderated_action",
 *   action_label = @Translation("Publish Moderated"),
 *   deriver = "Drupal\Core\Action\Plugin\Action\Derivative\EntityPublishedActionDeriver",
 * )
 */
class ModeratedPublish extends PublishAction {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    $entity->set('moderation_state', 'published');
    $entity->setPublished()->save();
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
