<?php

namespace Drupal\Tests\ys_views_basic\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps em dashes out of the listing-block authoring copy.
 *
 * @group ys_views_basic
 * @group yalesites
 */
class AuthoringCopyNoEmDashTest extends UnitTestCase {

  /**
   * The em dash character.
   */
  const EM_DASH = "\u{2014}";

  /**
   * Instruction markup and descriptions on block types have no em dash.
   */
  public function testFieldInstructionsMarkup(): void {
    $files = glob(dirname(__DIR__, 6) . '/config/sync/field.field.block_content.*.field_instructions.yml');
    $this->assertNotEmpty($files);
    foreach ($files as $file) {
      $config = Yaml::parseFile($file);
      $text = ($config['settings']['markup']['value'] ?? '') . ($config['description'] ?? '');
      $this->assertStringNotContainsString(self::EM_DASH, $text, basename($file));
    }
  }

  /**
   * The mockup preview template text (outside Twig comments) has no em dash.
   */
  public function testMockupPreviewTemplate(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/templates/views-basic-mockup-preview.html.twig');
    $source = preg_replace('/\{#.*?#\}/s', '', $source);
    $this->assertStringNotContainsString(self::EM_DASH, $source);
  }

  /**
   * String literals passed to t() in the widget base class have no em dash.
   */
  public function testWidgetBaseTranslatedStrings(): void {
    $source = file_get_contents(dirname(__DIR__, 3) . '/src/Plugin/Field/FieldWidget/ViewsBasicWidgetBase.php');
    $count = preg_match_all("/\bt\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $matches);
    $this->assertGreaterThan(0, $count);
    foreach ($matches[1] as $string) {
      $this->assertStringNotContainsString(self::EM_DASH, $string);
    }
  }

}
