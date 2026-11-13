<?php

namespace Drupal\Tests\ys_themes\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Custom Card Collection offers a Card Size dial (YaleSites-Internal#1842).
 *
 * Small is today's 4-up grid and the default; Large uses the Large grid of the
 * card collection. The dial reuses the field_style_width storage. Asserted
 * against the exported YAML because config/sync is what a deploy imports.
 *
 * @group ys_themes
 * @group yalesites
 */
class CustomCardsSizeDialTest extends UnitTestCase {

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
   * Small is listed first and is the default, so existing blocks look the same.
   *
   * @dataProvider \Drupal\Tests\ys_themes\Unit\EditorOptionsWave1DialsTest::overridesFiles
   */
  public function testDialOptionsAndDefault(string $file): void {
    $dial = Yaml::parseFile($file)['custom_cards'][self::FIELD] ?? NULL;
    $this->assertNotNull($dial, 'custom_cards.field_style_width missing');
    $this->assertSame(['small' => 'Small', 'large' => 'Large'], $dial['values']);
    $this->assertSame('small', $dial['default']);
  }

  /**
   * The field instance is required, defaulted by ys_themes, and explained.
   */
  public function testFieldInstance(): void {
    $file = self::configSyncDir() . '/field.field.block_content.custom_cards.' . self::FIELD . '.yml';
    $this->assertFileExists($file);
    $field = Yaml::parseFile($file);

    $this->assertSame('Card Size', $field['label']);
    $this->assertTrue($field['required']);
    $this->assertSame('ys_themes_default_value_function', $field['default_value_callback']);
    $this->assertContains('field.storage.block_content.' . self::FIELD, $field['dependencies']['config']);
  }

  /**
   * The dial is on the form, after the cards.
   */
  public function testFormDisplayPlacement(): void {
    $display = Yaml::parseFile(self::configSyncDir() . '/core.entity_form_display.block_content.custom_cards.default.yml');
    $content = $display['content'];

    $this->assertArrayHasKey(self::FIELD, $content);
    $this->assertSame('options_select', $content[self::FIELD]['type']);
    $this->assertGreaterThan($content['field_cards']['weight'], $content[self::FIELD]['weight']);
    $this->assertContains('field.field.block_content.custom_cards.' . self::FIELD, $display['dependencies']['config']);
  }

  /**
   * The view display renders the key, which atomic hands to the component.
   */
  public function testViewDisplayRendersKey(): void {
    $display = Yaml::parseFile(self::configSyncDir() . '/core.entity_view_display.block_content.custom_cards.default.yml');

    $this->assertArrayHasKey(self::FIELD, $display['content']);
    $this->assertSame('list_key', $display['content'][self::FIELD]['type']);
    $this->assertSame('hidden', $display['content'][self::FIELD]['label']);
    $this->assertContains('field.field.block_content.custom_cards.' . self::FIELD, $display['dependencies']['config']);
  }

  /**
   * Atomic passes the stored size to the component, and the old prop is gone.
   */
  public function testAtomicTemplatePassesCardSize(): void {
    $template = dirname(__DIR__, 9) . '/themes/contrib/atomic/templates/block/layout-builder/block--inline-block--custom-cards.html.twig';
    if (!is_file($template)) {
      $this->markTestSkipped('atomic theme is not checked out beside the profile.');
    }
    $source = file_get_contents($template);
    $this->assertStringContainsString("custom_card_collection__card_size: content.field_style_width.0['#markup']", $source);
    $this->assertStringNotContainsString('featured', $source);
  }

}
