<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\ItemCount\BlockItemCountRules;

/**
 * Tests the table of block types that limit how many items they may hold.
 *
 * The table is the one place a developer edits to add or change a limited
 * block type, so these assertions pin down what it declares today. The hook
 * that consumes it, and the constraint that enforces it, are covered by
 * BlockItemCountRangeConstraintTest.
 *
 * @group ys_core
 * @group yalesites
 *
 * @coversDefaultClass \Drupal\ys_core\ItemCount\BlockItemCountRules
 */
class BlockItemCountRulesTest extends UnitTestCase {

  /**
   * Every limited block type resolves to the field the message is about.
   *
   * @covers ::forBundle
   * @dataProvider limitedBundles
   */
  public function testLimitedBundleResolvesToItsRule(string $bundle, string $field, int $min, ?int $max, string $label): void {
    $rule = BlockItemCountRules::forBundle($bundle);

    $this->assertNotNull($rule, sprintf('The %s block type should declare an item-count rule.', $bundle));
    $this->assertSame($field, $rule->fieldName);
    $this->assertSame($min, $rule->min);
    $this->assertSame($max, $rule->max);
    $this->assertSame($label, $rule->itemLabel);
  }

  /**
   * The limited block types, with the bounds an editor is held to.
   *
   * Quick Links states its maximum because its field storage is unlimited.
   * The rest leave it NULL so the cap is read off field storage instead,
   * which is what keeps the message from drifting from the widget.
   */
  public static function limitedBundles(): array {
    return [
      'quick links' => ['quick_links', 'field_links', 3, 9, 'links'],
      'tabs' => ['tabs', 'field_tabs', 2, NULL, 'tabs'],
      'media grid' => ['media_grid', 'field_media_grid_items', 2, NULL, 'media grid items'],
      'gallery' => ['gallery', 'field_gallery_items', 2, NULL, 'gallery items'],
    ];
  }

  /**
   * A block type with no limit is left alone rather than defaulted.
   *
   * @covers ::forBundle
   */
  public function testUnlimitedBundleHasNoRule(): void {
    $this->assertNull(BlockItemCountRules::forBundle('text'));
  }

}
