<?php

namespace Drupal\ys_core;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;

/**
 * Ties an inline form error to its field for screen reader users.
 *
 * Inline Form Errors renders each validation message next to its field and
 * core marks the field aria-invalid, but nothing points the field's
 * aria-describedby at the message, so a screen reader says "invalid" with no
 * reason. This gives the message an id derived from the field id, appends it
 * to the field's aria-describedby, and attaches the behavior that moves focus
 * to the first invalid field (yalesites-org/YaleSites-Internal#1670).
 *
 * Covers every control wrapped in a form_element (text, email, number,
 * textarea, select, single checkbox, file). Grouped controls (radios,
 * checkboxes, datetime) render their error on the fieldset instead; they keep
 * aria-invalid and the inline message but get no aria-describedby link.
 *
 * Remove this if core's Inline Form Errors starts wiring aria-describedby
 * itself.
 *
 * @see ys_core_form_alter()
 * @see ys_core_preprocess_form_element()
 * @see ys_core_preprocess_input()
 */
final class FormErrorDescription {

  /**
   * Keeps Inline Form Errors to admin forms.
   *
   * Front-end forms keep core's error list at the top of the page, unchanged.
   * Set from hook_form_alter() rather than a default #process on the form
   * element: entity and config forms bring their own #process, which replaces
   * the element default, so a #process would miss every webform.
   *
   * @param array $form
   *   The form being altered.
   */
  public static function alterForm(array &$form): void {
    if (!\Drupal::service('router.admin_context')->isAdminRoute()) {
      $form['#disable_inline_form_errors'] = TRUE;
    }
  }

  /**
   * Returns the id of an element's inline error message, if one renders.
   *
   * @param array $element
   *   The form element render array.
   *
   * @return string|null
   *   The error message id, or NULL when no inline message renders for it.
   */
  public static function id(array $element): ?string {
    if (empty($element['#errors']) || !empty($element['#error_no_message']) || empty($element['#id'])) {
      return NULL;
    }
    // Match suggestions too: formdazzle rewrites the wrapper to
    // form_element__FORM_ID__FIELD before it renders.
    foreach ($element['#theme_wrappers'] ?? [] as $key => $wrapper) {
      $hook = is_string($key) ? $key : $wrapper;
      if ($hook === 'form_element' || str_starts_with($hook, 'form_element__')) {
        return $element['#id'] . '--error-message';
      }
    }
    return NULL;
  }

  /**
   * Gives the inline error rendered by Inline Form Errors its id.
   *
   * @param array $variables
   *   The form_element template variables.
   */
  public static function preprocessFormElement(array &$variables): void {
    $id = self::id($variables['element']);
    $errors = $variables['errors'] ?? NULL;
    if (!$id || empty($errors)) {
      return;
    }
    $variables['errors'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#attributes' => ['id' => $id],
      // Twig autoescaped a plain-string error; html_tag would only
      // admin-filter it, so escape it here to keep that.
      '#value' => $errors instanceof MarkupInterface ? $errors : Html::escape((string) $errors),
    ];
    $variables['#attached']['library'][] = 'ys_core/form_error_focus';
  }

  /**
   * Points an invalid control's aria-describedby at its inline error.
   *
   * @param array $variables
   *   The input, select, or textarea template variables. Their attributes are
   *   an array (input, select) or an Attribute object (textarea).
   */
  public static function preprocessControl(array &$variables): void {
    $id = self::id($variables['element']);
    if (!$id) {
      return;
    }
    $attributes = &$variables['attributes'];
    $current = isset($attributes['aria-describedby']) ? $attributes['aria-describedby'] . ' ' : '';
    $attributes['aria-describedby'] = $current . $id;
  }

}
