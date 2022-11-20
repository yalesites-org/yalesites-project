<?php

namespace Drupal\ys_embed\Plugin;

use Drupal\Component\Plugin\PluginBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * Base class for EmbedSource plugins.
 */
abstract class EmbedSourceBase extends PluginBase implements EmbedSourceInterface {

  /**
   * A regex to match an embed code to the source plugin.
   *
   * The regex should used named groups that are stored in the 'params' field
   * in the database and used to populated the template.
   *
   * @var string
   */
  protected static $pattern;

  /**
   * Instructions for finding the embed code in on third party website.
   *
   * @var string
   */
  protected static $instructions;

  /**
   * An example of the embed code.
   *
   * @var string
   */
  protected static $example;

  /**
   * {@inheritdoc}
   */
  public static function isValid(?string $input): bool {
    return !empty(preg_match(static::$pattern, $input, $matches));
  }

  /**
   * {@inheritdoc}
   */
  public function getParams(string $input): array {
    preg_match(static::$pattern, $input, $matches);
    return $matches;
  }

  /**
   * {@inheritdoc}
   */
  public static function getInstructions(): string {
    return static::$instructions;
  }

  /**
   * {@inheritdoc}
   */
  public static function getExample(): string {
    return static::$example;
  }

}
