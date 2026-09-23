<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_layouts\Plugin\Layout\YSLayoutOptions;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests which layout plugin definitions expose the color theme picker.
 *
 * @group yalesites
 * @group ys_layouts
 */
class YsLayoutsDefinitionsTest extends UnitTestCase {

  /**
   * Parses the layout plugin definitions.
   *
   * @return array
   *   The decoded ys_layouts.layouts.yml content.
   */
  protected function getLayoutDefinitions(): array {
    $path = __DIR__ . '/../../../ys_layouts.layouts.yml';
    return Yaml::parseFile($path);
  }

  /**
   * Two Column (70/30) is wired to the same layout class as 50/50 and 33/33/33.
   *
   * Two Column (70/30) previously used core LayoutDefault with no color
   * theme picker at all, unlike Two Column (50/50) and Three Column
   * (33/33/33). This pins the plugin definition's `class` key to
   * YSLayoutOptions for all three -- the actual form/picker behavior that
   * class provides is covered separately by YSLayoutOptionsTest.
   */
  public function testTwoColumnUsesLayoutOptionsClass(): void {
    $definitions = $this->getLayoutDefinitions();

    // Also pin the layouts that already had it, so a future edit that
    // removes the class from one of these is caught here too.
    foreach ([
      'ys_layout_two_column',
      'ys_layout_two_column_50_50',
      'ys_layout_three_column_33_33_33',
    ] as $id) {
      $this->assertSame('\\' . YSLayoutOptions::class, $definitions[$id]['class'] ?? NULL, $id);
    }
  }

}
