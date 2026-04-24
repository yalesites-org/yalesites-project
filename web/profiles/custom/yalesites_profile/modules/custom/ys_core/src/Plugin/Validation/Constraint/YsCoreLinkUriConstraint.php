<?php

namespace Drupal\ys_core\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Validates YaleSites link field URI values.
 *
 * @Constraint(
 *   id = "YsCoreLinkUri",
 *   label = @Translation("YaleSites link URI", context = "Validation"),
 *   type = "field_item"
 * )
 */
class YsCoreLinkUriConstraint extends Constraint {

  /**
   * Violation message for bare or malformed link URLs.
   *
   * @var string
   */
  public $invalidUri = 'The link URL %url must start with "/" for internal links or include a protocol such as "https://" for external links.';

}
