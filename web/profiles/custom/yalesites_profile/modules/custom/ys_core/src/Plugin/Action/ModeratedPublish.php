<?php

namespace Drupal\ys_core\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Action\Plugin\Action\Derivative\EntityPublishedActionDeriver;
use Drupal\Core\Action\Plugin\Action\PublishAction;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Publishes an entity.
 */
#[Action(
  id: 'entity:publish_moderated_action',
  action_label: new TranslatableMarkup('Publish Moderated'),
  deriver: EntityPublishedActionDeriver::class,
)]
class ModeratedPublish extends PublishAction {

  use ModeratedActionAccessTrait;

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    $entity->set('moderation_state', 'published');
    $entity->setPublished()->save();
  }

}
