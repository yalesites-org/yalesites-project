<?php

namespace Drupal\ys_file_management\Plugin\Validation\Constraint;

use Drupal\file\Plugin\Validation\Constraint\FileSizeLimitConstraint;

/**
 * File size constraint that tells editors how to shrink an oversize image.
 *
 * Replaces core's FileSizeLimit constraint (see
 * ys_file_management_validation_constraint_alter()). Non-image files keep
 * core's message.
 */
class ImageGuidanceFileSizeLimitConstraint extends FileSizeLimitConstraint {

  /**
   * Message for an oversize image, with a concrete next step.
   *
   * @var string
   */
  public string $imageMessage = 'The file is %filesize exceeding the maximum file size of %maxsize. Resize the image to no more than %resolution pixels, or export it at a lower quality, then upload it again.';

  /**
   * Message for an oversize image when no maximum resolution is configured.
   *
   * @var string
   */
  public string $imageMessageNoResolution = 'The file is %filesize exceeding the maximum file size of %maxsize. Make the image smaller, or export it at a lower quality, then upload it again.';

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return ImageGuidanceFileSizeLimitConstraintValidator::class;
  }

}
