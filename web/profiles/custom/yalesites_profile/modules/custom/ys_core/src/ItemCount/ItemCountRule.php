<?php

namespace Drupal\ys_core\ItemCount;

/**
 * How many items one block type's counted field may hold.
 *
 * A value object rather than an array so the four things a rule needs are
 * named at every use site, and so adding a fifth thing later is a compiler
 * error rather than a silently missing key.
 */
final class ItemCountRule {

  /**
   * Constructs an ItemCountRule.
   *
   * @param string $fieldName
   *   The multi-value field whose items are counted. The violation is raised
   *   against this field, so it is also the field the editor sees flagged.
   * @param int $min
   *   The fewest items the block renders correctly with.
   * @param int|null $max
   *   The most items allowed, or NULL to read the cap off field storage
   *   cardinality instead. Leaving this NULL is preferred wherever storage
   *   already caps the field, so the message cannot drift from what the
   *   widget actually permits.
   * @param string $itemLabel
   *   What the items are called in the error message, lower case and plural
   *   ("links", "gallery items").
   */
  public function __construct(
    public readonly string $fieldName,
    public readonly int $min,
    public readonly ?int $max,
    public readonly string $itemLabel,
  ) {}

  /**
   * Returns the constraint options this rule is applied with.
   *
   * @return array
   *   Options keyed as the YsItemCountRange constraint declares them.
   */
  public function toConstraintOptions(): array {
    return [
      'min' => $this->min,
      'max' => $this->max,
      'itemLabel' => $this->itemLabel,
    ];
  }

}
