<?php

namespace Drupal\Tests\ys_themes\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The Wrapped Image block offers an Image Size dial (YaleSites-Internal#1954).
 *
 * Large is today's 50% and the default; Small is 25% of the block width. The
 * dial reuses the field_style_width storage, as Content Spotlight's Image Size
 * does. Asserted against the exported YAML because config/sync is what a
 * deploy imports.
 *
 * @group ys_themes
 * @group yalesites
 */
class WrappedImageSizeDialTest extends UnitTestCase {

  /**
   * The field carrying the size.
   */
  const FIELD = 'field_style_width';

  /**
   * Absolute path to the profile's exported config/sync directory.
   */
  protected static function configSyncDir(): string {
    return dirname(__DIR__, 6) . '/config/sync';
  }

  /**
   * Large is listed first and is the default; Small is the only other choice.
   *
   * @dataProvider \Drupal\Tests\ys_themes\Unit\EditorOptionsWave1DialsTest::overridesFiles
   */
  public function testDialOptionsAndDefault(string $file): void {
    $dial = Yaml::parseFile($file)['wrapped_image'][self::FIELD] ?? NULL;
    $this->assertNotNull($dial, 'wrapped_image.field_style_width missing');
    $this->assertSame(['large' => 'Large', 'small' => 'Small'], $dial['values']);
    $this->assertSame('large', $dial['default']);
  }

  /**
   * The field instance is required, defaulted by ys_themes, and explained.
   */
  public function testFieldInstance(): void {
    $file = self::configSyncDir() . '/field.field.block_content.wrapped_image.' . self::FIELD . '.yml';
    $this->assertFileExists($file);
    $field = Yaml::parseFile($file);

    $this->assertSame('Image Size', $field['label']);
    $this->assertTrue($field['required']);
    $this->assertSame('ys_themes_default_value_function', $field['default_value_callback']);
    $this->assertContains('field.storage.block_content.' . self::FIELD, $field['dependencies']['config']);
    $this->assertSame(
      'Large is the size Wrapped Image has always used. Use Small for headshots, book covers and other narrow photos. On phones, both sizes fill the screen width.',
      $field['description'],
    );
  }

  /**
   * The dial sits after Image Style and Image Position on the form.
   */
  public function testFormDisplayPlacement(): void {
    $display = Yaml::parseFile(self::configSyncDir() . '/core.entity_form_display.block_content.wrapped_image.default.yml');
    $content = $display['content'];

    $this->assertArrayHasKey(self::FIELD, $content);
    $this->assertSame('options_select', $content[self::FIELD]['type']);
    $this->assertGreaterThan($content['field_style_position']['weight'], $content[self::FIELD]['weight']);
    $this->assertGreaterThan($content['field_style_variation']['weight'], $content[self::FIELD]['weight']);
    $this->assertContains('field.field.block_content.wrapped_image.' . self::FIELD, $display['dependencies']['config']);
  }

  /**
   * The view display renders the key, which atomic hands to the component.
   */
  public function testViewDisplayRendersKey(): void {
    $display = Yaml::parseFile(self::configSyncDir() . '/core.entity_view_display.block_content.wrapped_image.default.yml');

    $this->assertArrayHasKey(self::FIELD, $display['content']);
    $this->assertSame('list_key', $display['content'][self::FIELD]['type']);
    $this->assertSame('hidden', $display['content'][self::FIELD]['label']);
    $this->assertContains('field.field.block_content.wrapped_image.' . self::FIELD, $display['dependencies']['config']);
  }

}
