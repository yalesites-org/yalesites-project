<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests that the Basic HTML text format permits aria-level on headings.
 *
 * Content editors need to set aria-level on heading tags via the CKEditor
 * Source Editing view so a heading can be given a logical outline level
 * independent of its visual level. That requires two config values to agree:
 * the filter_html filter must keep the aria-level attribute on each heading it
 * allows, and the CKEditor Source Editing plugin must list aria-level on those
 * same headings so it is not stripped when the editor leaves Source view.
 *
 * @group ys_core
 * @group yalesites
 */
class BasicHtmlAriaLevelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['filter'];

  /**
   * The heading tags the Basic HTML format permits (h2-h6; h1 is reserved).
   */
  protected const HEADING_TAGS = ['h2', 'h3', 'h4', 'h5', 'h6'];

  /**
   * Reads a Basic HTML config file from the profile's sync directory.
   */
  protected function readConfig(string $name): array {
    $path = \Drupal::root() . '/profiles/custom/yalesites_profile/config/sync/' . $name . '.yml';
    return Yaml::decode(file_get_contents($path));
  }

  /**
   * The filter_html filter keeps aria-level on every heading it allows.
   */
  public function testAriaLevelSurvivesFilterOnHeadings(): void {
    $settings = $this->readConfig('filter.format.basic_html')['filters']['filter_html']['settings'];
    $filter = $this->container->get('plugin.manager.filter')
      ->createInstance('filter_html', ['settings' => $settings]);

    foreach (self::HEADING_TAGS as $tag) {
      $result = $filter->process("<$tag aria-level=\"3\">Heading</$tag>", 'en')
        ->getProcessedText();
      $this->assertStringContainsString('aria-level="3"', $result, "aria-level should survive filtering on <$tag>");
    }
  }

  /**
   * CKEditor Source Editing lists aria-level on every heading it allows.
   *
   * Guards against the filter and editor configs drifting apart: without this
   * the attribute would pass the filter but be stripped by CKEditor's GHS.
   */
  public function testSourceEditingAllowsAriaLevelOnHeadings(): void {
    $allowed_tags = $this->readConfig('editor.editor.basic_html')['settings']['plugins']['ckeditor5_sourceEditing']['allowed_tags'];

    foreach (self::HEADING_TAGS as $tag) {
      $entries = array_filter($allowed_tags, fn(string $entry) => str_starts_with($entry, "<$tag "));
      $this->assertNotEmpty($entries, "Source Editing should list <$tag>");
      foreach ($entries as $entry) {
        $this->assertStringContainsString('aria-level', $entry, "Source Editing <$tag> should permit aria-level");
      }
    }
  }

}
