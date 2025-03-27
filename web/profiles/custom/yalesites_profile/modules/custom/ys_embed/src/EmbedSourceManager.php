<?php

namespace Drupal\ys_embed;

use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\ys_embed\Plugin\EmbedSource\EmbedSourceInterface;

/**
 * Manages embed source plugins.
 */
class EmbedSourceManager extends DefaultPluginManager {

  /**
   * Constructs a new EmbedSourceManager object.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/EmbedSource', $namespaces, $module_handler, EmbedSourceInterface::class, 'Drupal\ys_embed\Annotation\EmbedSource');
    $this->alterInfo('ys_embed_embed_source_info');
    $this->setCacheBackend($cache_backend, 'ys_embed_embed_source_plugins');
  }

  /**
   * Finds the appropriate embed source plugin for the given embed code.
   *
   * @param string $embed_code
   *   The embed code to find a plugin for.
   *
   * @return \Drupal\ys_embed\Plugin\EmbedSource\EmbedSourceInterface|null
   *   The appropriate embed source plugin, or null if none found.
   */
  public function findEmbedSource(string $embed_code): ?EmbedSourceInterface {
    foreach ($this->getDefinitions() as $plugin_id => $definition) {
      $plugin = $this->createInstance($plugin_id);
      if ($plugin->isValid($embed_code)) {
        return $plugin;
      }
    }
    return NULL;
  }

}
