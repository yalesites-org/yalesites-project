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
   * Check if the embed provider allows a title attribute to be set.
   *
   * This is defined in the @EmbedSource plugin annotation as a boolean. This
   * method exists so individual providers can dynamically show/hide the title.
   *
   * @return bool
   *   TRUE if the embed provider allows the title attribute to be set.
   */
  public function requireTitle(): bool;

}
