<?php

namespace Drupal\ys_embed\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Validation constraint for embed media objects.
 *
 * @Constraint(
 *   id = "embed",
 *   label = @Translation("Embed", context = "Validation"),
 *   type = "string"
 * )
 */
class EmbedConstraint extends Constraint {

  /**
   * Violation message for video embed codes.
   *
   * @var string
   */
  public $isVideo = 'YouTube and Vimeo are not valid "embed" objects. Instead, these can be added using the "Video" component.';

  /**
   * Violation message when the embed code does not match a supported provider.
   *
   * @var string
   */
  public $invalidPattern = 'The given source is not a supported embed code.';

}
