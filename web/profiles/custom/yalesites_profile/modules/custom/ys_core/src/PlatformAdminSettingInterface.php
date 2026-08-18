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
   * The permission that marks a user as a platform admin.
   *
   * This is the platform's single mechanism for "is this a platform admin"
   * (yalesites-org/YaleSites-Internal#1560). It gates the Platform Admin
   * Settings route, and any setting left behind on a mixed-audience form gates
   * on it too, so a rename has one place to change on the PHP side. It is
   * granted only to the platform_admin role; user 1 satisfies it through
   * Drupal's permission bypass. The route requirement in ys_core.routing.yml
   * and the declaration in ys_core.permissions.yml necessarily repeat the
   * string, because YAML cannot reference a PHP constant.
   */
  const PERMISSION = 'administer platform admin settings';

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
