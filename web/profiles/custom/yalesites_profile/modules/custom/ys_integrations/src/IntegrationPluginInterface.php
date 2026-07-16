<?php

namespace Drupal\ys_integrations;

/**
 * Defines an interface for integration plugins.
 */
interface IntegrationPluginInterface {

  /**
   * Determines if the integration is turned on.
   */
  public function isTurnedOn(): bool;

  /**
   * Get the configuration form for the integration.
   */
  public function configUrl();

  /**
   * Get the sync url for the integration.
   */
  public function syncUrl();

  /**
   * Get the build array for the user facing fields.
   *
   * @return array
   *   The build array.
   */
  public function build();

  /**
   * Save data allowed in the configuration form.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return void
   *   No return value
   */
  public function save($form, $form_state): void;

}
