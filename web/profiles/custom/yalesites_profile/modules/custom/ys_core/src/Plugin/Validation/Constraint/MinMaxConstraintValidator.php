<?php

namespace Drupal\ys_core\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the MinMax constraint.
 */
class MinMaxConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint) {

    // Quick links are not referenced entities.
    if ($constraint->type == 'links') {
      $items = $value->getValue();
    }
    else {
      $items = $value->referencedEntities();
    }

    // Get item count for the field.
    if (!empty($items)) {
      $item_count = count($items);

      // Constraints with minimum but no maximum.
      if (isset($constraint->min) && isset($constraint->max)) {
        // Constraints with minimum and maximum.
        if ($item_count < $constraint->min || $item_count > $constraint->max) {
          $this->context->addViolation($constraint->outsideMinMax, [
            '@min' => $constraint->min,
            '@max' => $constraint->max,
            '@type' => $constraint->type,
            '@count' => $item_count,
          ]);
        }
      }
      elseif (isset($constraint->min) && !isset($constraint->max)) {
        if ($item_count < $constraint->min) {
          $this->context->addViolation($constraint->belowMin, [
            '@min' => $constraint->min,
            '@type' => $constraint->type,
            '@count' => $item_count,
          ]);
        }
      }
    }
  }

}