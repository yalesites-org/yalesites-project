<?php

namespace Drupal\ys_embed\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Constraint validator to ensure embedded code matches a valid provider.
 *
 * @todo Move pattern to Qualtrics class and call as static method.
 */
class EmbedConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritDoc}
   */
  public function validate($value, Constraint $constraint) {

    /** @var \Drupal\media\MediaInterface $media */
    $media = $value->getEntity();
    $source = $media->getSource();
    $embed_code = $source->getSourceFieldValue($media);

    // Check if the embed code should use the video media type instead.
    if ($this->isVideo($embed_code)) {
      $this->context->addViolation($constraint->isVideo);
      return;
    }

    // Check if the embed coude matches one of the allowed providers.
    if (!$this->isValid($embed_code)) {
      $this->context->addViolation($constraint->invalidPattern);
      return;
    }

  }

  /**
   * Check if the embed code matches a supported video media provider.
   *
   * @param string $source
   *   The user provided embed code.
   *
   * @return boolean
   *   TRUE if the embed code matches one of the remote video providers.
   */
  protected function isVideo(string $code): bool {
    $p1 = "^https:\/\/\S+.youtube.com\/\S+";
    $p2 = "^https:\/\/youtu.be\/\S+";
    $p3 = "^https:\/\/vimeo.com\/\S+";
    $pattern = "/{$p1}|{$p2}|{$p3}/";
    return (bool) preg_match($pattern, $code, $matches);
  }

  /**
   * Check if the embed code matches a valid embed provider.
   *
   * @param string $source
   *   The user provided embed code.
   *
   * @return boolean
   *   TRUE if the embed code matches one of the allowed providers.
   */
  protected function isValid(string $code): bool {
    $pattern = '/^https:\/\/yalesurvey.(\S+).qualtrics.com\/(\S+)/';
    return (bool) preg_match($pattern, $code, $matches);
  }

}
