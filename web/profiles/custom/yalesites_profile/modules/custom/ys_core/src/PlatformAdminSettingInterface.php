<?php

namespace Drupal\ys_core;

use Drupal\Core\Form\FormStateInterface;

/**
 * Defines an interface for platform admin setting plugins.
 *
 * Each plugin contributes a self-contained section (build, validate, and save)
 * to the platform-admin-only Platform Admin Settings form. The form owns
 * discovery and ordering; each plugin owns its own configuration.
 */
interface PlatformAdminSettingInterface {

  /**
   * Builds this plugin's settings section.
   *
   * @param array $form
   *   The section's form subtree (rendered with #tree set to TRUE).
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array
   *   The render array of form elements for this section.
   */
  public function buildSettings(array $form, FormStateInterface $form_state): array;

  /**
   * Validates this plugin's settings section.
   *
   * @param array $form
   *   The complete form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function validateSettings(array &$form, FormStateInterface $form_state): void;

  /**
   * Saves this plugin's settings section.
   *
   * @param array $form
   *   The complete form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function submitSettings(array &$form, FormStateInterface $form_state): void;

}
