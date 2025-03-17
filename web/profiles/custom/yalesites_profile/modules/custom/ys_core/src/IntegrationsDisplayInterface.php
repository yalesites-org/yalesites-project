<?php

namespace Drupal\ys_core;

/**
 * Interface for integrations display.
 */
interface IntegrationsDisplayInterface {

  /**
   * Tells if the integration is turned on.
   *
   * @return bool
   *   TRUE if the integration is turned on, FALSE otherwise.
   */
  public function isTurnedOn(): bool;

}
