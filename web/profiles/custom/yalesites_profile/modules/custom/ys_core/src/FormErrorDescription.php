<?php

namespace Drupal\ys_core;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Element;
use Drupal\Core\Security\TrustedCallbackInterface;

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
 * Controls wrapped in a form_element (text, email, number, textarea, select,
 * single checkbox, file) link from the control itself. A rich text field is
 * its textarea; form-error-focus.js copies the link onto the CKEditor 5
 * editing area. Grouped controls render their error on the group instead:
 * radios, checkbox lists, and media pickers link from the fieldset, which
 * screen readers announce on entering the group (the GOV.UK error pattern),
 * and a datetime links from each date part, since its wrapper is a plain div.
 *
 * Remove this if core's Inline Form Errors starts wiring aria-describedby
 * itself.
 *
 * @see ys_core_form_alter()
 * @see ys_core_preprocess_form_element()
 * @see ys_core_preprocess_input()
 * @see ys_core_preprocess_fieldset()
 * @see ys_core_element_info_alter()
 */
final class FormErrorDescription implements TrustedCallbackInterface {

  /**
   * Templates that print an element's inline error, bar formdazzle suffixes.
   */
  private const ERROR_TEMPLATES = [
    'form_element',
    'fieldset',
    'datetime_wrapper',
    'media_library_element',
  ];

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['preRenderDatetime'];
  }

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
    $hooks = (array) ($element['#theme'] ?? []);
    foreach ($element['#theme_wrappers'] ?? [] as $key => $wrapper) {
      $hooks[] = is_string($key) ? $key : $wrapper;
    }
    // Match suggestions too: formdazzle rewrites the wrapper to
    // form_element__FORM_ID__FIELD before it renders.
    foreach ($hooks as $hook) {
      if (in_array(explode('__', $hook)[0], self::ERROR_TEMPLATES, TRUE)) {
        return $element['#id'] . '--error-message';
      }
    }
    return NULL;
  }

  /**
   * Gives the inline error rendered by Inline Form Errors its id.
   *
   * Every inline error renders through here, so this is also where the focus
   * behavior is attached.
   *
   * @param array $variables
   *   The form_element, fieldset, or datetime_wrapper template variables.
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
    $attributes['aria-describedby'] = self::describedBy($attributes['aria-describedby'] ?? NULL, $id);
  }

  /**
   * Ids a group's inline error and points the group's aria-describedby at it.
   *
   * @param array $variables
   *   The fieldset or media_library_element template variables.
   */
  public static function preprocessGroup(array &$variables): void {
    self::preprocessFormElement($variables);
    if (isset($variables['errors']['#attributes']['id'])) {
      self::preprocessControl($variables);
    }
  }

  /**
   * Renders the media picker's inline error, which Inline Form Errors skips.
   *
   * The media_library_element template prints errors, but its preprocess
   * blanks them and Inline Form Errors has no hook for this theme hook.
   * Nothing marks the element #error_no_message while that module is off, so
   * check it is on rather than render an error it would not.
   *
   * @param array $variables
   *   The media_library_element template variables.
   */
  public static function preprocessMediaLibraryElement(array &$variables): void {
    if (self::id($variables['element']) && \Drupal::moduleHandler()->moduleExists('inline_form_errors')) {
      $variables['errors'] = $variables['element']['#errors'];
      self::preprocessGroup($variables);
      // The template's bare wrapper has no error styling; Claro's has.
      $variables['errors']['#attributes']['class'][] = 'fieldset__error-message';
    }
  }

  /**
   * Points each date part of an invalid datetime at the datetime's error.
   *
   * @param array $element
   *   The datetime or datelist element.
   *
   * @return array
   *   The element, its date parts described by its error.
   */
  public static function preRenderDatetime(array $element): array {
    $id = self::id($element);
    if ($id) {
      foreach (Element::children($element) as $key) {
        // A datelist part keeps its own message, and so its own link.
        if (self::id($element[$key])) {
          continue;
        }
        $element[$key]['#attributes']['aria-describedby'] = self::describedBy($element[$key]['#attributes']['aria-describedby'] ?? NULL, $id);
      }
    }
    return $element;
  }

  /**
   * Adds the error id to an aria-describedby value, once.
   *
   * @param mixed $current
   *   The current value: NULL, a string, or an AttributeString.
   * @param string $id
   *   The error message id.
   *
   * @return string
   *   The value with the error id last.
   */
  private static function describedBy(mixed $current, string $id): string {
    $ids = array_filter(explode(' ', (string) $current));
    return implode(' ', array_unique([...$ids, $id]));
  }

}
