<?php

namespace Drupal\ys_core;

use Drupal\Core\Config\ConfigBase;

/**
 * Keys and read rule for the AI readability toggles in ys_core.site.
 *
 * A missing key means ON. Existing sites never receive these keys (ys_core
 * config is config-ignored and there is deliberately no update hook), so every
 * reader must go through isEnabled(): the settings form, the .md route, the
 * alternate link, /llms.txt and robots.txt (YaleSites-Internal#1713, #1714).
 * Reading the raw value instead lets the readers drift apart.
 */
final class AiReadabilitySettings {

  const MARKDOWN_ENABLED = 'ai_readability.markdown_enabled';

  const BLOCK_AI_CRAWLERS = 'ai_readability.block_ai_crawlers';

  /**
   * Whether a setting is on, treating a missing key as on.
   */
  public static function isEnabled(ConfigBase $config, string $key): bool {
    return (bool) ($config->get($key) ?? TRUE);
  }

}
