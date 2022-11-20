<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * Fallback plugin for media items with a broken EmbedSource.
 *
 * @EmbedSource(
 *   id = "broken",
 *   label = @Translation("Broken/Missing"),
 *   description = @Translation("Fallback plugin for media items with a broken EmbedSource."),
 *   active = FALSE,
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
