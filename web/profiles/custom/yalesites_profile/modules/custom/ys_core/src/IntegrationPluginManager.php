<?php

namespace Drupal\ys_core;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\ys_core\Attribute\Integration;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Provides a IntegrationPluginManager to manage integrations.
 */
class IntegrationPluginManager extends DefaultPluginManager {

  /**
   * Constructs a FormatterPluginManager object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ys_core', $namespaces, $module_handler, 'Drupal\ys_core\IntegrationPluginInterface', Integration::class);
    $this->setCacheBackend($cache_backend, 'ys_core_integration_plugins');
    $this->alterInfo('ys_core_integration_info');
  }

}
