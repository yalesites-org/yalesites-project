<?php

namespace Drupal\ys_markdown\EventSubscriber;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\ys_core\AiReadabilitySettings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Clears the cached robots.txt when its content or the AI toggle changes.
 *
 * The robotstxt controller tags its response only with "robotstxt", so a
 * plain save of robotstxt.settings (drush deploy hooks, cim, cset) would
 * otherwise leave the old file cached.
 */
class RobotsTxtInvalidator implements EventSubscriberInterface {

  /**
   * Invalidates the robotstxt cache tag when either input changed.
   */
  public function onSave(ConfigCrudEvent $event): void {
    $name = $event->getConfig()->getName();
    if (($name === 'ys_core.site' && $event->isChanged(AiReadabilitySettings::BLOCK_AI_CRAWLERS))
      || ($name === 'robotstxt.settings' && $event->isChanged('content'))) {
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
