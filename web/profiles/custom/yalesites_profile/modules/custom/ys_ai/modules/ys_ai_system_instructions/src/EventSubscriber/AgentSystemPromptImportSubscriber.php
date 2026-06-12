<?php

namespace Drupal\ys_ai_system_instructions\EventSubscriber;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\StorageTransformEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps the config-ignored agent system_prompt importable on a first deploy.
 *
 * The chatbot's runtime prompt (`ai_agents.ai_agent.beacon:system_prompt`) is
 * config-ignored so an admin's value is not overwritten by a config import. The
 * trade-off is that config_ignore "simple" mode strips an ignored sub-key on a
 * CREATE (a first-time import has no active value to preserve). Unlike a plain
 * array key, `AiAgent::$system_prompt` is a non-nullable typed `string`, so a
 * missing value throws a TypeError while the entity is constructed during
 * import — aborting the whole `cim`/`drush deploy`.
 *
 * This restores an empty string for the stripped key after config_ignore has
 * run, so the agent can be created; `ys_ai_system_instructions_deploy_10001()`
 * then seeds the shipped default once the import has completed. On an UPDATE
 * config_ignore preserves the active value, so the key is present and this is a
 * no-op (it never overwrites a real value).
 */
class AgentSystemPromptImportSubscriber implements EventSubscriberInterface {

  /**
   * The config name of the chatbot agent whose system_prompt is ignored.
   */
  const AGENT_CONFIG_NAME = 'ai_agents.ai_agent.beacon';

  /**
   * Restores a stripped system_prompt so the agent entity can be constructed.
   *
   * @param \Drupal\Core\Config\StorageTransformEvent $event
   *   The import storage transform event.
   */
  public function onImportTransform(StorageTransformEvent $event): void {
    $storage = $event->getStorage();
    if (!$storage->exists(self::AGENT_CONFIG_NAME)) {
      return;
    }

    $data = $storage->read(self::AGENT_CONFIG_NAME);
    if (is_array($data) && !array_key_exists('system_prompt', $data)) {
      $data['system_prompt'] = '';
      $storage->write(self::AGENT_CONFIG_NAME, $data);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run after config_ignore's onImportTransform (default priority -100) so we
    // can restore the value it stripped.
    return [
      ConfigEvents::STORAGE_TRANSFORM_IMPORT => ['onImportTransform', -200],
    ];
  }

}
