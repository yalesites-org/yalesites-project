<?php

namespace Drupal\Tests\ys_themes\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every component_overrides entry must name a field that actually exists.
 *
 * The ys_themes_allowed_values_function() callback - wired in from each field
 * storage's allowed_values_function - only emits options when the entity's
 * bundle and the field name both match an entry in this config. An entry for a
 * bundle/field pair that has no field instance is therefore never reached: no
 * form element is built and no value is ever stored. It reads like a control
 * an editor can set, and it is not one.
 *
 * Asserted against the exported field.field.*.yml rather than a loaded
 * FieldConfig for the reason BannerWidthFieldDescriptionTest gives: config/sync
 * is what a deploy imports, and it keeps this a Unit test rather than a Kernel
 * test that would have to install the whole profile to see the bundles. The
 * on-disk result was checked against the live container (a sweep of
 * entity_field.manager over block_content, paragraph, node and media) and
 * agrees exactly.
 *
 * Each copy of the config is validated independently, because config/sync is
 * what an existing site imports on deploy while config/install seeds a new one.
 * Guarding one would let the other rot. This does not compare the two against
 * each other - they have legitimately drifted apart already.
 *
 * @group ys_themes
 * @group yalesites
 */
class ComponentOverridesHaveFieldsTest extends UnitTestCase {

  /**
   * Absolute path to the profile root.
   */
  protected function profileDir(): string {
    return dirname(__DIR__, 6);
  }

  /**
   * No override entry points at a field that no bundle has.
   *
   * @dataProvider providerOverridesFiles
   */
  public function testEveryOverrideEntryHasMatchingField(
    string $label,
    string $path,
  ): void {
    $overrides = Yaml::parseFile($this->profileDir() . '/' . $path);
    $this->assertNotEmpty(
      $overrides,
      "$label component_overrides should not be empty."
    );

    $dead = [];
    foreach ($overrides as $bundle => $fields) {
      // A fresh install stamps _core.default_config_hash onto this object, and
      // the next confex writes it into the sync copy. It is config metadata,
      // not a bundle.
      if ($bundle === '_core' || !is_array($fields)) {
        continue;
      }

      foreach (array_keys($fields) as $fieldName) {
        // Any entity type: these dials sit on block types and paragraph types.
        $pattern = "/config/sync/field.field.*.$bundle.$fieldName.yml";
        $found = glob($this->profileDir() . $pattern);
        if (!$found) {
          $dead[] = "$bundle.$fieldName";
        }
      }
    }

    $this->assertSame(
      [],
      $dead,
      "These $label component_overrides entries name a bundle/field pair with "
      . 'no field instance, so the dial they describe can never be rendered or '
      . 'stored. Delete the entry, or add the field: '
      . implode(', ', $dead)
    );
  }

  /**
   * Both copies of the overrides config.
   *
   * @return array
   *   Test cases keyed by the role each file plays.
   */
  public static function providerOverridesFiles(): array {
    $file = 'ys_themes.component_overrides.yml';

    return [
      'config/sync' => ['config/sync', "config/sync/$file"],
      'config/install' => [
        'config/install',
        "modules/custom/ys_themes/config/install/$file",
      ],
    ];
  }

}
