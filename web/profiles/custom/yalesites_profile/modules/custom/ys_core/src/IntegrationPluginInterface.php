<?php

namespace Drupal\ys_core;

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

}
