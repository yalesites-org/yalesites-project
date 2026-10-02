<?php

namespace Drupal\Tests\ys_themes\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Wave 1 of YaleSites-Internal#1720: Storybook-only options become dials.
 *
 * Asserts the option keys and defaults editors get for Divider thickness,
 * Callout link style and Action Banner button style, in both copies of the
 * overrides file (install and config/sync).
 *
 * @group ys_themes
 * @group yalesites
 */
class EditorOptionsWave1DialsTest extends UnitTestCase {

  /**
   * Expected dials: bundle => field => [option keys, default].
   */
  const DIALS = [
    'divider' => ['field_style_thickness', ['1', '2', '4', '8', '16'], '1'],
    'callout' => ['field_style_variation', ['cta', 'link'], 'cta'],
    'cta_banner' => [
      'field_button_style_consistency',
      ['both_filled', 'both_outline', 'filled_outline', 'outline_filled'],
      'filled_outline',
    ],
  ];

  /**
   * Both copies of the overrides file.
   */
  public static function overridesFiles(): array {
    $profile = dirname(__DIR__, 6);
    return [
      'install' => [__DIR__ . '/../../../config/install/ys_themes.component_overrides.yml'],
      'sync' => [$profile . '/config/sync/ys_themes.component_overrides.yml'],
    ];
  }

  /**
   * Each new dial offers exactly the expected keys and default.
   *
   * @dataProvider overridesFiles
   */
  public function testDialOptionsAndDefaults(string $file): void {
    $overrides = Yaml::parseFile($file);
    foreach (self::DIALS as $bundle => [$field, $keys, $default]) {
      $dial = $overrides[$bundle][$field] ?? NULL;
      $this->assertNotNull($dial, "$bundle.$field missing");
      $this->assertSame($keys, array_map('strval', array_keys($dial['values'])), "$bundle.$field options");
      $this->assertSame($default, (string) $dial['default'], "$bundle.$field default");
    }
  }

  /**
   * Each dial is on the form and view displays; overline instances exist.
   *
   * Dial field instances are covered by ComponentOverridesHaveFieldsTest.
   */
  public function testFieldsAreOnDisplays(): void {
    $sync = dirname(__DIR__, 6) . '/config/sync';
    foreach (self::DIALS as $bundle => [$field]) {
      foreach (['entity_form_display', 'entity_view_display'] as $type) {
        $display = Yaml::parseFile("$sync/core.$type.block_content.$bundle.default.yml");
        $this->assertArrayHasKey($field, $display['content'], "$type $bundle $field");
      }
    }
    foreach (['content_spotlight', 'content_spotlight_portrait'] as $bundle) {
      $this->assertFileExists("$sync/field.field.block_content.$bundle.field_overline.yml");
    }
  }

}
