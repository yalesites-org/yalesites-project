<?php

namespace Drupal\ys_embed\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validation constraint for embed media objects.
 */
#[Constraint(
  id: 'embed',
  label: new TranslatableMarkup('Embed', [], ['context' => 'Validation']),
  type: 'string',
)]
class EmbedConstraint extends SymfonyConstraint {

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

  /**
   * Violation message for invalid audio embed codes.
   *
   * @var string
   */
  public $invalidAudioTrack = 'The given source must reference a track only.';

}
