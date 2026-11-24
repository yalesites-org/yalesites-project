<?php

namespace Drupal\ys_core\Linkit;

use Drupal\linkit\Plugin\Linkit\Matcher\FileMatcher;

/**
 * Linkit's file matcher, ranked by filename.
 *
 * @see \Drupal\ys_core\Linkit\RankedMatcherTrait
 */
class RankedFileMatcher extends FileMatcher {

  use RankedMatcherTrait;

}
