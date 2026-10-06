<?php

namespace Drupal\ys_markdown\EventSubscriber;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\ys_core\AiReadabilitySettings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Clears the cached robots.txt when the "block AI crawlers" setting changes.
 */
class RobotsTxtInvalidator implements EventSubscriberInterface {

  /**
   * Invalidates the robotstxt cache tag when the setting changed.
   */
  public function onSave(ConfigCrudEvent $event): void {
    if ($event->getConfig()->getName() === 'ys_core.site' && $event->isChanged(AiReadabilitySettings::BLOCK_AI_CRAWLERS)) {
      Cache::invalidateTags(['robotstxt']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::SAVE => 'onSave'];
  }

}
