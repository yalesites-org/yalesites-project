<?php

namespace Drupal\ys_core\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Checks that the number of items added falls between a min and max.
 *
 * @Constraint(
 *   id = "MinMax",
 *   label = @Translation("Min/Max referenced field items", context = "Validation"),
 *   type = "string"
 * )
 */
class MinMaxConstraint extends Constraint {

  public string $min;
  public string $max;
  public string $type;
  public string $outsideMinMax = 'Number of @type must be between @min and @max. Number of @type added: @count';
  public string $belowMin = 'There must be a minimum of @min @type. Number of @type added: @count';

}