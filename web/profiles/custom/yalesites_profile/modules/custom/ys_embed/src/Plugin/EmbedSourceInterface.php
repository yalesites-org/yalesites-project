<?php

namespace Drupal\ys_embed\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Defines an interface for Embed Provider plugins.
 */
interface EmbedSourceInterface extends PluginInspectionInterface {

  /**
   * Returns the URI of the default thumbnail.
   *
   * @return string
   *   The default thumbnail URI.
   */
  public function getDefaultThumbnailUri(): string;

  /**
   * Check if a string matches the EmbedSource plugin patterns.
   *
   * @param string $input
   *   A raw embed code added by a content author.
   *
   * @return bool
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

  /**
   * Does the example contain markup that should not be escaped.
   *
   * @return bool
   *   If the example contains markup.
   */
  public static function exampleContainsMarkup(): bool;

  /**
   * Get the Klaro consent service this embed source belongs to.
   *
   * Sources that load third-party content declare the Klaro service (app) the
   * visitor must consent to before that content may load. Returning NULL marks
   * the source as ungated, which is only correct for Yale-controlled tenants
   * and for sources that make no third-party request at all.
   *
   * @return string|null
   *   The Klaro service (app) ID, or NULL if the source is not consent-gated.
   */
  public function getKlaroService(): ?string;

  /**
   * Get a render array for an embed code.
   *
   * @param array $params
   *   An array of parameters required to build the embed code.
   *
   * @return array
   *   The renderable array for an embed code.
   */
  public function build(array $params): array;

}
