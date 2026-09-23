<?php

namespace Drupal\Tests\ys_themes\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The shipped component dial config matches what deploys actually import.
 *
 * The module's config/install copy of ys_themes.component_overrides drifted
 * from config/sync for long enough that reading it gave the wrong answer to
 * "what options does this dial offer" (#1667). config/sync is the source of
 * truth; config/install must stay a byte-for-byte copy of it.
 *
 * @group ys_themes
 * @group yalesites
 */
class ComponentOverridesConfigTest extends UnitTestCase {

  /**
   * Absolute path to the profile's exported config/sync directory.
   */
  protected function configSyncDir(): string {
    return dirname(__DIR__, 6) . '/config/sync';
  }

  /**
   * Absolute path to this module's config/install directory.
   */
  protected function configInstallDir(): string {
    return dirname(__DIR__, 3) . '/config/install';
  }

  /**
   * The install copy is identical to the deployed copy.
   */
  public function testInstallCopyMatchesConfigSync(): void {
    $this->assertFileEquals(
      $this->configSyncDir() . '/ys_themes.component_overrides.yml',
      $this->configInstallDir() . '/ys_themes.component_overrides.yml',
      'config/install/ys_themes.component_overrides.yml has drifted from '
      . 'config/sync. Copy the config/sync file over it.'
    );
  }

  /**
   * Every dial entry points at a field that exists on that bundle.
   *
   * An entry with no field behind it is never read, so it only misleads
   * anyone using this file to learn what options a component offers.
   */
  public function testEveryDialNamesAnExistingField(): void {
    $overrides = Yaml::parseFile($this->configSyncDir() . '/ys_themes.component_overrides.yml');
    $orphans = [];

    foreach ($overrides as $bundle => $fields) {
      foreach (array_keys($fields) as $field) {
        if (!glob($this->configSyncDir() . "/field.field.*.$bundle.$field.yml")) {
          $orphans[] = "$bundle.$field";
        }
      }
    }

    $this->assertSame([], $orphans, 'Dial entries with no matching field config.');
  }

}
