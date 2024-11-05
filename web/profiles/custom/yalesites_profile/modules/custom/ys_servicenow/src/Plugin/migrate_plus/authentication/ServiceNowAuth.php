<?php

declare(strict_types=1);

namespace Drupal\ys_servicenow\Plugin\migrate_plus\authentication;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate_plus\AuthenticationPluginBase;
use Drupal\ys_servicenow\BasicAuthWithKeys;

/**
 * Provides authentication for service now endpoint.
 *
 * @Authentication(
 *   id = "servicenow_auth",
 *   title = @Translation("ServiceNow Authentication")
 * )
 */
class ServiceNowAuth extends AuthenticationPluginBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function getAuthenticationOptions(): array {
    $servicenow_config = \Drupal::config('ys_servicenow.settings');
    $servicenow_key_id = $servicenow_config->get('servicenow_auth_key');
    $basicAuthWithKeys = new BasicAuthWithKeys($servicenow_config, $servicenow_key_id);
    return $basicAuthWithKeys->getAuthenticationOptions();
  }

}
