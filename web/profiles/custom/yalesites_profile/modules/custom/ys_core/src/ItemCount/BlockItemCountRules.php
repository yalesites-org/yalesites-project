<?php

namespace Drupal\ys_core\ItemCount;

/**
 * The block types that only render correctly within a set number of items.
 *
 * This is the single place to edit when a block type gains, loses, or changes
 * an item-count limit. Adding one here is enough: the rule is picked up by
 * ys_core_entity_bundle_field_info_alter(), which attaches the
 * YsItemCountRange constraint to the named field.
 *
 * @see ys_core_entity_bundle_field_info_alter()
 * @see \Drupal\ys_core\Plugin\Validation\Constraint\ItemCountRangeConstraint
 */
final class BlockItemCountRules {

  /**
   * Item-count limits, keyed by block_content bundle.
   *
   * Only Quick Links states a maximum, because its field storage cardinality
   * is unlimited. The others leave it NULL so the cap is read from field
   * storage — Tabs is capped at five there — which keeps the message an
   * editor reads in step with what the widget lets them do.
   */
  private const RULES = [
    'quick_links' => ['field_links', 3, 9, 'links'],
    'tabs' => ['field_tabs', 2, NULL, 'tabs'],
    'media_grid' => ['field_media_grid_items', 2, NULL, 'media grid items'],
    'gallery' => ['field_gallery_items', 2, NULL, 'gallery items'],
  ];

  /**
   * Returns the item-count rule for a block type, if it has one.
   *
   * @param string $bundle
   *   The block_content bundle machine name.
   *
   * @return \Drupal\ys_core\ItemCount\ItemCountRule|null
   *   The rule, or NULL for a block type that sets no limit.
   */
  public static function forBundle(string $bundle): ?ItemCountRule {
    if (!isset(self::RULES[$bundle])) {
      return NULL;
    }

    [$field_name, $min, $max, $item_label] = self::RULES[$bundle];

    return new ItemCountRule($field_name, $min, $max, $item_label);
  }

}
