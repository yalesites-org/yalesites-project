<?php

namespace Drupal\ys_core\Plugin\Validation\Constraint;

use Drupal\Core\Field\FieldItemListInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ItemCountRange constraint.
 */
class ItemCountRangeConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint) {
    if (!$value instanceof FieldItemListInterface) {
      return;
    }

    // Only deltas the editor actually filled in count. The link widget leaves a
    // spare empty row for "Add another item", and a block built in code can
    // carry empty deltas too; neither is an item the editor added. This is the
    // same predicate FieldItemList::filterEmptyItems() applies, counted in
    // place rather than by cloning the whole list to filter a copy of it.
    $number = 0;
    foreach ($value as $item) {
      if (!$item->isEmpty()) {
        $number++;
      }
    }

    if ($number >= $constraint->min && ($constraint->max === NULL || $number <= $constraint->max)) {
      return;
    }

    $arguments = [
      '@items' => $constraint->itemLabel,
      '@min' => $constraint->min,
      '@number' => $number,
    ];

    if ($constraint->max === NULL) {
      $this->context->addViolation($constraint->minimumMessage, $arguments);
      return;
    }

    // Only pass @max where the message uses it: a NULL placeholder value is
    // deprecated in Drupal 10 and an error in Drupal 11.
    $arguments['@max'] = $constraint->max;
    $this->context->addViolation($constraint->rangeMessage, $arguments);
  }

}
