<?php

namespace Drupal\ys_integrations;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\ys_integrations\Attribute\Integration;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Provides a IntegrationPluginManager to manage integrations.
 */
class IntegrationPluginManager extends DefaultPluginManager {

  /**
   * Constructs a FormatterPluginManager object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ys_integrations', $namespaces, $module_handler, 'Drupal\ys_integrations\IntegrationPluginInterface', Integration::class);
    $this->setCacheBackend($cache_backend, 'ys_integrations_integration_plugins');
    $this->alterInfo('ys_integrations_integration_info');
  }

}
