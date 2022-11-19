<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * Class Broken.
 *
 * @EmbedSource(
 *   id = "broken",
 *   label = @Translation("Broken/Missing"),
 *   description = @Translation("Broken/missing embed provider plugin."),
 *   active = FALSE,
 *   require_title = FALSE,
 * )
 */
class Broken extends EmbedSourceBase implements EmbedSourceInterface {

  /**
   * {@inheritdoc}
   */
  protected static $pattern;

  /**
   * {@inheritdoc}
   */
  protected static $instructions = 'Broken/Missing';

  /**
   * {@inheritdoc}
   */
  protected static $example = 'Broken/Missing';

  /**
   * {@inheritdoc}
   *
   * Override the isValid method so that the 'broken' plugin never matches.
   */
  public static function isValid(?string $input): bool {
    return FALSE;
  }

}
