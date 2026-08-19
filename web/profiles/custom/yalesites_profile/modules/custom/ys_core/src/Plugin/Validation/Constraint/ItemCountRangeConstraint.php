<?php

namespace Drupal\ys_core\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Limits how many items a multi-value field may hold.
 *
 * Several YaleSites block types only render correctly within a set number of
 * items — Quick Links needs three to nine links, Tabs two to five tabs. The
 * rule used to be enforced in the browser by ys_core/block_form, which reported
 * it through setCustomValidity() on whichever input a selector list happened to
 * match first. That produced a native browser bubble on the wrong field, with
 * no visible error text and nothing for a screen reader to announce.
 *
 * Expressing the rule as an entity constraint hands it to Drupal's own
 * validation pipeline, which flags the widget of the field the violation names
 * and renders the message where every other form error appears.
 *
 * Core's Count constraint was considered first, but its messages are built for
 * Symfony's plural machinery and cannot express the single "must be between
 * three and nine, you added one" sentence editors already know, and it counts
 * empty deltas the editor never filled in.
 */
#[Constraint(
  id: 'YsItemCountRange',
  label: new TranslatableMarkup('Item count range', [], ['context' => 'Validation']),
  type: ['list']
)]
class ItemCountRangeConstraint extends SymfonyConstraint {

  /**
   * Violation message when the field has both a lower and an upper bound.
   *
   * @var string
   */
  public $rangeMessage = 'Number of @items must be between @min and @max. Number of @items added: @number.';

  /**
   * Violation message when the field only has a lower bound.
   *
   * @var string
   */
  public $minimumMessage = 'Number of @items must be @min or more. Number of @items added: @number.';

  /**
   * The fewest items the field may hold.
   *
   * @var int
   */
  public $min;

  /**
   * The most items the field may hold, or NULL for no upper bound.
   *
   * @var int|null
   */
  public $max = NULL;

  /**
   * What to call the items in the message, plural and lower case.
   *
   * @var string
   */
  public $itemLabel = 'items';

  /**
   * {@inheritdoc}
   */
  public function getRequiredOptions(): array {
    return ['min', 'itemLabel'];
  }

}
