<?php

namespace Drupal\ys_core\Plugin\Validation\Constraint;

use Drupal\Component\Utility\UrlHelper;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Constraint validator for YaleSites link field URI values.
 */
class YsCoreLinkUriConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($item, Constraint $constraint) {
    if (!is_object($item) || !isset($item->uri)) {
      return;
    }

    $uri = trim((string) $item->uri);

    if ($uri === '' || $this->isValidUri($uri)) {
      return;
    }

    $this->context->buildViolation($constraint->invalidUri)
      ->setParameter('%url', $uri)
      ->addViolation();
  }

  /**
   * Determines whether a link field URI can be safely saved and rendered.
   */
  protected function isValidUri(string $uri): bool {
    if (str_starts_with($uri, '/')) {
      return TRUE;
    }

    if (preg_match('/^https?:/i', $uri)) {
      return UrlHelper::isValid($uri, TRUE);
    }

    return (bool) preg_match('/^[a-z][a-z0-9+.-]*:/i', $uri);
  }

}
