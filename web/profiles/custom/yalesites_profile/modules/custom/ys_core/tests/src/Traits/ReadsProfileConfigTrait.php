<?php

namespace Drupal\Tests\ys_core\Traits;

use Drupal\Core\Serialization\Yaml;

/**
 * Reads a config file straight out of the profile's own sync directory.
 *
 * A test that asserts against the config the platform actually ships is
 * stronger than one that rebuilds an approximation of it in setUp(), because
 * it fails when the exported config drifts from what the code expects.
 * installConfig() is not an alternative for most of these: it installs a
 * module's own install/optional config, not the profile's exported sync
 * directory.
 *
 * Extracted here because the same four-line helper had been copy-pasted into
 * two ys_core kernel tests independently; new tests should use this rather
 * than adding a third copy.
 */
trait ReadsProfileConfigTrait {

  /**
   * Reads and decodes a config file from the profile's sync directory.
   *
   * @param string $name
   *   The config name, without the .yml extension - e.g.
   *   'filter.format.basic_html'.
   *
   * @return array
   *   The decoded config.
   */
  protected function readConfig(string $name): array {
    $path = \Drupal::root() . '/profiles/custom/yalesites_profile/config/sync/' . $name . '.yml';
    return Yaml::decode(file_get_contents($path));
  }

}
