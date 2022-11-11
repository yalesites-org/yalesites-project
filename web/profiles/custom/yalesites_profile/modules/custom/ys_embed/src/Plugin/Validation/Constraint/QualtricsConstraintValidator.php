<?php

namespace Drupal\ys_embed\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Constraint validator to ensure embedded Qualtrics forms us a valid URL.
 *
 * @todo Move invalidLink to separate constraint.
 * @todo Move pattern to Qualtrics class and call as static method.
 */
class QualtricsConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritDoc}
   */
  public function validate($value, Constraint $constraint) {

    /** @var \Drupal\media\MediaInterface $media */
    $media = $value->getEntity();
    $source = $media->getSource();
    $url = $source->getSourceFieldValue($media);

    // Test that the provided string is a valud URL.
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      $this->context->addViolation($constraint->invalidLink);
      return;
    }

    // Test that the provided string matches the Qualtrics URL pattern.
    $pattern = '/^https:\/\/yalesurvey.(\S+).qualtrics.com\/(\S+)/';
    if (empty(preg_match($pattern, $url, $matches))) {
      $this->context->addViolation($constraint->invalidPattern);
      return;
    }
  }

}
