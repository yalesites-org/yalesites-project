<?php

namespace Drupal\ys_feeds_demo\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Takes a node out of circulation without deleting it.
 *
 * Feeds' "update non-existent items" setting accepts any action plugin id, and
 * the obvious choice, node_unpublish_action, does not work here: it sets the
 * status base field directly, and content moderation then recomputes status
 * from the unchanged moderation state on save, quietly republishing the node.
 *
 * This action moves the node to the "archived" state instead (both the
 * editorial and events workflows have one, labelled "Unpublished"), and falls
 * back to setting status for anything not under moderation.
 *
 * Archiving rather than deleting is the deliberate choice: a row vanishing
 * from a spreadsheet is usually someone tidying up, not a decision to destroy
 * content and break every link pointing at it.
 */
#[Action(
  id: 'ys_feeds_demo_archive_moderated_node',
  label: new TranslatableMarkup('Archive content that is no longer in the source'),
  type: 'node'
)]
class ArchiveModeratedNode extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if ($entity === NULL) {
      return;
    }

    if ($entity->hasField('moderation_state')) {
      $entity->set('moderation_state', 'archived');
    }
    elseif (method_exists($entity, 'setUnpublished')) {
      $entity->setUnpublished();
    }

    $entity->save();
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    /** @var \Drupal\Core\Entity\EntityInterface $object */
    $access = $object->access('update', $account, TRUE);

    return $return_as_object ? $access : $access->isAllowed();
  }

}
