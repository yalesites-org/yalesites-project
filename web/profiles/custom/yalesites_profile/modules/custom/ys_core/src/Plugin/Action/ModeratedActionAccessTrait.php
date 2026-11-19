<?php

namespace Drupal\ys_core\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
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
    // can update a moderated entity is allowed, but only when they may also
    // use the transition to the target state, as the edit form requires.
    // Checking update access and moderation separately avoids matching core's
    // error message text.
    $moderation_info = \Drupal::service('content_moderation.moderation_information');
    if ($update->isAllowed() && $moderation_info->isModeratedEntity($object)) {
      $transition = $this->transitionAccess($object, $account);
      $result = $transition->isAllowed()
        ? AccessResult::allowed()
          ->addCacheableDependency($update)
          ->addCacheableDependency($published)
          ->addCacheableDependency($transition)
        : $transition->addCacheableDependency($update);
    }

    return $return_as_object ? $result : $result->isAllowed();
  }

  /**
   * The moderation state this action moves the entity to.
   */
  abstract protected function targetModerationState(): string;

  /**
   * Checks the user may move the entity to this action's target state.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   A moderated entity.
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account, or NULL for the current user.
   */
  protected function transitionAccess($entity, ?AccountInterface $account): AccessResultInterface {
    $account = $account ?: \Drupal::currentUser();
    $workflow = \Drupal::service('content_moderation.moderation_information')->getWorkflowForEntity($entity);
    $type_plugin = $workflow->getTypePlugin();
    $from = $type_plugin->getState($entity->moderation_state->value ?: $type_plugin->getInitialState($entity)->id());
    $to = $type_plugin->getState($this->targetModerationState());
    $valid = \Drupal::service('content_moderation.state_transition_validation')
      ->isTransitionValid($workflow, $from, $to, $account, $entity);

    return AccessResult::allowedIf($valid)
      ->cachePerPermissions()
      ->addCacheableDependency($entity);
  }

}
