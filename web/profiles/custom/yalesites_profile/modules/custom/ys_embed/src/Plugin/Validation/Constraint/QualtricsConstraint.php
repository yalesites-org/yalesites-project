<?php

namespace Drupal\ys_embed\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Validation constraint for Qualtric form embeds.
 *
 * @Constraint(
 *   id = "qualtrics",
 *   label = @Translation("Qualtrics", context = "Validation"),
 *   type = "string"
 * )
 */
class QualtricsConstraint extends Constraint {

  /**
   * Violation message when the input is not a valid URL.
   *
   * @var string
   */
  public $invalidLink = 'The given link is not a valid URL.';

  /**
   * Violation message when the link does not patch the Qualtrics URL pattern.
   *
   * @var string
   */
  public $invalidPattern = 'The given link is not a valid Qualtrics URL.';

}
