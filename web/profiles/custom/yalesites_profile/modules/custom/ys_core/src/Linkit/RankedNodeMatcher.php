<?php

namespace Drupal\ys_core\Linkit;

use Drupal\linkit\Plugin\Linkit\Matcher\NodeMatcher;

/**
 * Linkit's content matcher, ranked by title.
 *
 * @see \Drupal\ys_core\Linkit\RankedMatcherTrait
 */
class RankedNodeMatcher extends NodeMatcher {

  use RankedMatcherTrait;

}
