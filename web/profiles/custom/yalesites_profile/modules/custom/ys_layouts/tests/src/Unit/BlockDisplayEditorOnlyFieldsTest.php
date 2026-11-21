<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards block displays against printing editor-only fields to visitors.
 *
 * Fields listed under a display's `content` render as visible text unless the
 * block's template skips them. Editor-only settings must sit under `hidden`.
 *
 * @group yalesites
 * @group ys_layouts
 */
class BlockDisplayEditorOnlyFieldsTest extends UnitTestCase {

  /**
   * Fields that only configure presentation and must never print as content.
   */
  protected const EDITOR_ONLY_FIELDS = ['field_padding_options'];

  /**
   * No block_content view display shows an editor-only field to visitors.
   */
  public function testBlockDisplaysHideEditorOnlyFields(): void {
    $dir = dirname(__DIR__, 4) . '/../../config/sync';
    $files = glob($dir . '/core.entity_view_display.block_content.*.yml');
    $this->assertNotEmpty($files, 'Block content view displays were found.');

    $leaks = [];
    foreach ($files as $file) {
      $display = Yaml::parseFile($file);
      foreach (self::EDITOR_ONLY_FIELDS as $field) {
        if (isset($display['content'][$field])) {
          $leaks[] = basename($file, '.yml') . ' shows ' . $field;
        }
      }
    }
    $this->assertSame([], $leaks, "Editor-only fields must be under 'hidden':\n" . implode("\n", $leaks));
  }

}
