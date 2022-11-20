<?php

namespace Drupal\ys_embed\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Defines an interface for Embed Provider plugins.
 */
interface EmbedSourceInterface extends PluginInspectionInterface {

  /**
   * Check if a string matches the EmbedSource plugin patterns.
   *
   * @param string $input
   *   A raw embed code added by a content author.
   *
   * @return boolean
   *   TRUE if the input text matches the EmbedSource pattern.
   */
  public static function isValid(string $input): bool;

  /**
   * Get the named parameters from the matching embed code regex pattern.
   *
   * @param string $input
   *   A raw embed code added by a content author.
   *
   * @return array
   *   The captured named parameters from the regex match.
   */
  public function getParams(string $input): array;

  /**
   * Get user instructions for finding an embed code.
   *
   * @return string
   *   Instructions for finding an embed code.
   */
  public static function getInstructions(): string;

  /**
   * Get an example embed code.
   *
   * @return string
   *   An example embed code.
   */
  public static function getExample(): string;

}
