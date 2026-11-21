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
   * Property set on every element inside a site exposed filter form.
   *
   * The label, input, select and wrapper templates inside the form receive
   * their own element, not the form, so they need a marker to be recognised.
   */
  public const MARKER = '#ys_skip_gin_lb';

  /**
   * Whether the element is a site exposed filter form (not media library).
   */
  public static function isSiteExposedForm(array $element): bool {
    return ($element['#form_id'] ?? '') === 'views_exposed_form'
      && !str_starts_with((string) ($element['#id'] ?? ''), 'views-exposed-form-media-library-');
  }

  /**
   * Marks every child element of a site exposed filter form.
   *
   * @param array $form
   *   The form (or a sub-element) to mark, passed by reference.
   */
  public static function markChildren(array &$form): void {
    foreach (array_keys($form) as $key) {
      if (is_string($key) && str_starts_with($key, '#')) {
        continue;
      }
      if (is_array($form[$key])) {
        $form[$key][self::MARKER] = TRUE;
        self::markChildren($form[$key]);
      }
    }
  }

  /**
   * Copies the marker from a field element onto its label render array.
   *
   * Core builds the label in template_preprocess_form_element() as a new
   * render array that copies only a few keys from the field, so the marker
   * is lost and the label would still take gin_lb's label template.
   *
   * @param array $variables
   *   The form_element theme variables, passed by reference.
   */
  public static function markLabel(array &$variables): void {
    if (!empty($variables['element'][self::MARKER]) && isset($variables['label']) && is_array($variables['label'])) {
      $variables['label'][self::MARKER] = TRUE;
    }
  }

  /**
   * Removes __gin_lb suggestions for a site exposed filter form or its fields.
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
    if (empty($element[self::MARKER]) && !self::isSiteExposedForm($element)) {
      return $suggestions;
    }
    return array_values(array_filter(
      $suggestions,
      static fn (string $suggestion): bool => !str_ends_with($suggestion, '__gin_lb'),
    ));
  }

}
