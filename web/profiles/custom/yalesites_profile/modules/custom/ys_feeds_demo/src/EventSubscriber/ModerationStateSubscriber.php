<?php

namespace Drupal\ys_feeds_demo\EventSubscriber;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\feeds\Event\EntityEvent;
use Drupal\feeds\Event\FeedsEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Reconciles Feeds imports with content moderation.
 *
 * YaleSites puts page, post, profile and resource under the "editorial"
 * workflow, whose default state is "draft". Feeds knows nothing about
 * moderation: it sets the status base field, which content moderation then
 * overwrites from the moderation state on presave. Without this, an import
 * produces nodes in a confusing half-state.
 *
 * Only new entities need handling. On an update, Feeds loads the existing node
 * and maps onto it, so its moderation state is already the stored one and the
 * right thing to do is nothing at all: a sync updates the field values it was
 * told about and forms no opinion about whether an editor should have
 * unpublished something. Removing an item from the source is handled
 * separately, by an action plugin.
 *
 * @see \Drupal\ys_feeds_demo\Plugin\Action\ArchiveModeratedNode
 */
class ModerationStateSubscriber implements EventSubscriberInterface {

  /**
   * The state new moderated entities are created in.
   *
   * Demo feeds publish on import so that a demonstration does not end with
   * thirty unpublished nodes to click through. A production implementation
   * should make this a per-feed-type setting rather than a constant, so a feed
   * from a source nobody vets can land in draft for review instead.
   */
  const DEMO_CREATE_STATE = 'published';

  /**
   * Prefix identifying the demo feed types this subscriber applies to.
   */
  const DEMO_FEED_TYPE_PREFIX = 'demo_';

  /**
   * The moderation information service.
   *
   * @var \Drupal\content_moderation\ModerationInformationInterface
   */
  protected $moderationInformation;

  /**
   * Constructs a ModerationStateSubscriber.
   *
   * @param \Drupal\content_moderation\ModerationInformationInterface $moderation_information
   *   The moderation information service.
   */
  public function __construct(ModerationInformationInterface $moderation_information) {
    $this->moderationInformation = $moderation_information;
  }

  /**
   * {@inheritdoc}
   *
   * @return array
   *   The subscribed events.
   */
  public static function getSubscribedEvents() {
    return [
      FeedsEvents::PROCESS_ENTITY_PRESAVE => 'onPresave',
    ];
  }

  /**
   * Gives newly imported moderated entities an explicit moderation state.
   *
   * @param \Drupal\feeds\Event\EntityEvent $event
   *   The presave event.
   */
  public function onPresave(EntityEvent $event) {
    $entity = $event->getEntity();

    if (!$entity->isNew() || !$this->moderationInformation->isModeratedEntity($entity)) {
      return;
    }

    // Only act on the demo feed types, so that enabling this module cannot
    // change the behaviour of any other feed someone has configured.
    if (strpos($event->getFeed()->bundle(), self::DEMO_FEED_TYPE_PREFIX) !== 0) {
      return;
    }

    $entity->set('moderation_state', self::DEMO_CREATE_STATE);
  }

}
