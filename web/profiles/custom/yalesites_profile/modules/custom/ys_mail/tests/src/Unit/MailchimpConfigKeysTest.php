<?php

namespace Drupal\Tests\ys_mail\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the YaleSites Mailchimp Transactional config against key-name drift.
 *
 * The mailchimp_transactional module renamed its config keys from the legacy
 * "mailchimp_transactional_*" prefixed form to unprefixed schema keys
 * (api_key, track_opens, ...) and ships mailchimp_transactional_update_8001()
 * to migrate them. Because Drupal runs database updates before importing
 * config on deploy, a stale config/sync export silently re-introduces the old
 * keys, so the API key resolves empty and visitors see mail errors after a
 * webform submission. This test fails if the exported config or the Key
 * override regress to the legacy key names (issue #1340).
 *
 * @group yalesites
 */
class MailchimpConfigKeysTest extends UnitTestCase {

  /**
   * Valid top-level keys defined by mailchimp_transactional 1.2.0 schema.
   */
  const SCHEMA_KEYS = [
    'from_name',
    'from_email',
    'subaccount',
    'filter_format',
    'track_opens',
    'track_clicks',
    'url_strip_qs',
    'analytics_campaign',
    'analytics_domains',
    'batch_log_queued',
    'queue_worker_timeout',
    'log_defaulted_sends',
    'api_key',
    'api_timeout',
    'api_classname',
    'mail_key_denylist',
    'process_async',
  ];

  /**
   * Absolute path to the profile's exported config/sync directory.
   */
  protected function configSyncDir(): string {
    return dirname(__DIR__, 6) . '/config/sync';
  }

  /**
   * The exported settings must use the unprefixed 1.2.0 schema keys.
   */
  public function testSettingsUseUnprefixedSchemaKeys(): void {
    $file = $this->configSyncDir() . '/mailchimp_transactional.settings.yml';
    $this->assertFileExists($file);
    $settings = Yaml::parseFile($file);

    $this->assertArrayHasKey('api_key', $settings, 'The unprefixed api_key must be present so the Key override resolves.');

    foreach (array_keys($settings) as $key) {
      // The "_core" metadata key is added by Drupal's config system.
      if ($key === '_core') {
        continue;
      }
      $this->assertStringStartsNotWith('mailchimp_transactional_', $key, sprintf('Legacy prefixed key "%s" must be renamed to its 1.2.0 schema name.', $key));
      $this->assertContains($key, self::SCHEMA_KEYS, sprintf('Key "%s" is not a valid mailchimp_transactional.settings schema key.', $key));
    }
  }

  /**
   * The Key override must inject the secret into the unprefixed api_key.
   */
  public function testApiKeyOverrideTargetsUnprefixedKey(): void {
    $file = $this->configSyncDir() . '/key.config_override.mailchimp_transactional_api_key.yml';
    $this->assertFileExists($file);
    $override = Yaml::parseFile($file);

    $this->assertSame('mailchimp_transactional.settings', $override['config_name']);
    $this->assertSame('api_key', $override['config_item'], 'The Pantheon secret must be injected into the unprefixed api_key read by Api.php.');
  }

}
