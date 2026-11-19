<?php

namespace Drupal\ys_core\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Action\Plugin\Action\Derivative\EntityPublishedActionDeriver;
use Drupal\Core\Action\Plugin\Action\UnpublishAction;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Unpublishes an entity.
 */
#[Action(
  id: 'entity:unpublish_moderated_action',
  action_label: new TranslatableMarkup('Unpublish Moderated'),
  deriver: EntityPublishedActionDeriver::class,
)]
class ModeratedUnpublish extends UnpublishAction {

  use ModeratedActionAccessTrait;

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    $entity->set('moderation_state', 'archived');
    $entity->setUnpublished()->save();
  }

}
