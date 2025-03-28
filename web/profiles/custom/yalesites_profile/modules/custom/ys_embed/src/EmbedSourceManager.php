<?php

namespace Drupal\ys_embed\EmbedSource;

use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Manages embed source plugins.
 *
 * This class is responsible for managing and finding appropriate embed source
 * plugins based on embed codes.
 */
class EmbedSourceManager extends DefaultPluginManager {

  /**
   * Constructs a new EmbedSourceManager.
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
    parent::__construct('Plugin/EmbedSource', $namespaces, $module_handler, 'Drupal\ys_embed\EmbedSource\EmbedSourceInterface', 'Drupal\ys_embed\Annotation\EmbedSource');

    $this->alterInfo('ys_embed_embed_source_info');
    $this->setCacheBackend($cache_backend, 'ys_embed_embed_source_plugins');
  }

  /**
   * Finds the appropriate embed source for a given embed code.
   *
   * @param string $embed_code
   *   The embed code to find a source for.
   *
   * @return \Drupal\ys_embed\Plugin\EmbedSource\EmbedSourceInterface|null
   *   The embed source plugin if found, NULL otherwise.
   */
  public function findEmbedSource($embed_code) {
    \Drupal::logger('ys_embed')->notice('Finding embed source for code: @code', ['@code' => $embed_code]);

    // First check for libcal_weekly.
    $libcal_weekly = $this->createInstance('libcal_weekly');
    if ($libcal_weekly->matches($embed_code)) {
      \Drupal::logger('ys_embed')->notice('Found libcal_weekly match.');
      return $libcal_weekly;
    }

    // Then check for libcal.
    $libcal = $this->createInstance('libcal');
    if ($libcal->matches($embed_code)) {
      \Drupal::logger('ys_embed')->notice('Found libcal match.');
      return $libcal;
    }

    // Then check for other sources.
    foreach ($this->getDefinitions() as $id => $definition) {
      if ($id === 'libcal' || $id === 'libcal_weekly') {
        continue;
      }
      $instance = $this->createInstance($id);
      if ($instance->matches($embed_code)) {
        \Drupal::logger('ys_embed')->notice('Found match for source: @source.', ['@source' => $id]);
        return $instance;
      }
    }

    \Drupal::logger('ys_embed')->notice('No embed source found.');
    return NULL;
  }

}
