<?php

namespace Drupal\ys_core\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Switches Klaro off entirely on sites that have not opted into consent.
 *
 * Klaro ships platform-wide, but whether a site asks its visitors for consent
 * is a per-site decision made on the site settings form. Klaro has no such
 * toggle of its own; what it does have is 'disable_urls', which every one of
 * its entry points consults through KlaroHelper::onDisabledUri() -
 * klaro_page_attachments(), klaro_js_alter(), klaro_page_attachments_alter()
 * and klaro_preprocess_field(). Overriding that to match every path is
 * therefore the one lever that stands the whole module down consistently.
 *
 * It has to be a runtime override rather than a saved value: klaro.settings is
 * platform-managed config that ships in config/sync, so a per-site value
 * written into it would be reverted by the next config import.
 *
 * Turning Klaro off matters as much as turning it on. If the module kept
 * rewriting scripts to Klaro's deferred form while its own JavaScript was not
 * attached to undefer them, share buttons and embeds would simply never load.
 */
class ConsentConfigOverrides implements ConfigFactoryOverrideInterface {

  /**
   * The Klaro settings object this class overrides.
   */
  protected const KLARO_SETTINGS = 'klaro.settings';

  /**
   * The per-site consent settings that drive the override.
   */
  protected const CONSENT_SETTINGS = 'ys_core.consent_settings';

  /**
   * A disable_urls pattern matching every request path.
   *
   * KlaroHelper::onDisabledUri() wraps each entry in delimiters and
   * preg_match()es it against the request URI, so this matches everything.
   */
  protected const MATCH_ALL = '.*';

  public function __construct(
    protected StorageInterface $configStorage,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names) {
    if (!in_array(self::KLARO_SETTINGS, $names, TRUE) || $this->consentEnabled()) {
      return [];
    }

    return [
      self::KLARO_SETTINGS => ['disable_urls' => [self::MATCH_ALL]],
    ];
  }

  /**
   * Whether this site asks visitors for cookie consent.
   *
   * Read from raw storage rather than the config factory, which would re-enter
   * the factory while it is still resolving overrides.
   *
   * @return bool
   *   TRUE when the site has opted into consent management.
   */
  protected function consentEnabled(): bool {
    $settings = $this->configStorage->read(self::CONSENT_SETTINGS) ?: [];
    return !empty($settings['banner_enabled']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix() {
    return 'ys_core_consent';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name) {
    $metadata = new CacheableMetadata();
    if ($name === self::KLARO_SETTINGS) {
      $metadata->addCacheTags(['config:' . self::CONSENT_SETTINGS]);
    }
    return $metadata;
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

}
