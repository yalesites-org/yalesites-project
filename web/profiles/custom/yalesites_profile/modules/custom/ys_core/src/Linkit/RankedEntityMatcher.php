<?php

namespace Drupal\ys_core\Linkit;

use Drupal\linkit\Plugin\Linkit\Matcher\EntityMatcher;

/**
 * Linkit's generic entity matcher (media, among others), ranked by label.
 *
 * @see \Drupal\ys_core\Linkit\RankedMatcherTrait
 */
class RankedEntityMatcher extends EntityMatcher {

  use RankedMatcherTrait;

}
