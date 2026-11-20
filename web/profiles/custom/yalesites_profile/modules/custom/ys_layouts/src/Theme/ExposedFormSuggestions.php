<?php

namespace Drupal\ys_layouts\Theme;

/**
 * Keeps gin_lb templates off View block filter forms.
 *
 * Gin_lb appends __gin_lb suggestions to every form on some Layout Builder
 * routes (such as layout_builder.remove_block). Those outrank atomic's
 * form--views--*.html.twig, so after a block is removed the remaining filter
 * forms lose the ys-filter-form classes and render as a floating card
 * (yalesites-org/YaleSites-Internal#2310). The media library filter form is
 * left alone because gin_lb styles it on purpose.
 */
class ExposedFormSuggestions {

  /**
   * Removes __gin_lb suggestions for a site exposed filter form.
   *
   * @param string[] $suggestions
   *   The form theme suggestions.
   * @param array $element
   *   The form element.
   *
   * @return string[]
   *   The suggestions to use.
   */
  public static function filter(array $suggestions, array $element): array {
    if (($element['#form_id'] ?? '') !== 'views_exposed_form'
      || str_starts_with((string) ($element['#id'] ?? ''), 'views-exposed-form-media-library-')) {
      return $suggestions;
    }
    return array_values(array_filter(
      $suggestions,
      static fn (string $suggestion): bool => !str_ends_with($suggestion, '__gin_lb'),
    ));
  }

}
