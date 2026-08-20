<?php

namespace Drupal\Tests\ys_core\Traits;

/**
 * Helpers for tests about config written before ys_core had a schema.
 */
trait LegacyConfigFixtureTrait {

  /**
   * Loads the deploy hooks so a test can call one directly.
   *
   * The ys_core.deploy.php file is loaded only by drush deploy:hook, not for a
   * module that is merely enabled, so a test includes it itself. The path is
   * resolved through the extension list rather than relative to this file, so
   * moving a test class cannot silently break the include.
   */
  protected function requireDeployHooks(): void {
    $path = \Drupal::service('extension.list.module')->getPath('ys_core');
    require_once \Drupal::root() . '/' . $path . '/ys_core.deploy.php';
  }

  /**
   * Writes a pre-schema shape straight to storage, as a live site holds it.
   *
   * Going through the config factory would put the value past the schema
   * checker and the type casting, which is exactly what these shapes predate.
   *
   * @param string $name
   *   The config object name.
   * @param array $data
   *   The raw data to store.
   */
  protected function storeLegacy(string $name, array $data): void {
    $this->container->get('config.storage')->write($name, $data);
    // Drop the factory's cached copy so the next read sees the raw write.
    $this->container->get('config.factory')->reset($name);
  }

}
