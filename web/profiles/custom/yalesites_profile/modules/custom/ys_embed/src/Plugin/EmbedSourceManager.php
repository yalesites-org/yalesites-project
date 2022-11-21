<?php

namespace Drupal\ys_embed\Plugin;

use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Provides the EmbedSource plugin manager.
 */
class EmbedSourceManager extends DefaultPluginManager {

  /**
   * The id for the broken source embed plugin.
   *
   * @var string
   */
  const BROKEN_ID = 'broken';

  /**
   * Instances of loaded plugins indexed by EmbedSource id.
   *
   * @var array
   */
  protected $instances = [];

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
    parent::__construct('Plugin/EmbedSource', $namespaces, $module_handler, 'Drupal\ys_embed\Plugin\EmbedSourceInterface', 'Drupal\ys_embed\Annotation\EmbedSource');
    $this->alterInfo('ys_embed_embed_source_info');
    $this->setCacheBackend($cache_backend, 'ys_embed_embed_source_plugins');
  }

  /**
   * Gets the definition of all EmbedSource plugins.
   *
   * This method filters the list of EmbedSource plugins to once with the
   * annotation 'active = TRUE'. This allows legacy embed sources to exist in
   * the media library but prevents new instances from being created.
   *
   * @return mixed[]
   *   An array of plugin definitions keyed by plugin ID.
   */
  public function getSources(): array {
    return array_filter($this->getDefinitions(), function ($def) {
      return $def['active'] == TRUE;
    });
  }

  /**
   * Find the EmbedSource plugin that matches an embed code.
   *
   * Each EmbedSource plugin has a regex to test if a given string matches
   * the expected embed code. This method returns the first matching source.
   *
   * @param string $input
   *   A raw embed code added by a content author.
   *
   * @return array|null
   *   The plugin definition array for the first matching EmbedSource.
   */
  public function findEmbedSource($input): ?array {
    foreach ($this->getSources() as $source) {
      if ($source['class']::isValid($input)) {
        return $source;
      }
    }
    return NULL;
  }

  /**
   * Check if a string matches one of the EmbedSource plugin patterns.
   *
   * @todo determine if this should check plugins that are not active.
   *
   * @param string $input
   *   A raw embed code added by a content author.
   *
   * @return bool
   *   TRUE if the given string matches at least one of the embed sources.
   */
  public function isValid(string $input): bool {
    return !empty($this->findEmbedSource($input));
  }

  /**
   * Check if a string matches one of the EmbedSource plugin IDs.
   *
   * @param string $plugin_id
   *   The ID of a EmbedSource plugin.
   *
   * @return bool
   *   TRUE if the given string matches at one of the EmbedSource IDs.
   */
  public function isValidSourceId(string $plugin_id): bool {
    return array_key_exists($plugin_id, $this->getDefinitions());
  }

  /**
   * Load an EmbedSource plugin instance from its plugin ID.
   *
   * If the plugin ID does not match an active EmbedSource plugin, then this
   * method returns the broken EmbedSource plugin.
   *
   * @param string $plugin_id
   *   The ID of a EmbedSource plugin.
   *
   * @return \Drupal\ys_embed\Plugin\EmbedSourceInterface
   *   A fully configured plugin instance.
   */
  public function loadPluginById($plugin_id) {
    if (!$this->isValidSourceId($plugin_id)) {
      return $this->instance[static::BROKEN_ID];
    }
    if (!empty($this->instance[$plugin_id])) {
      return $this->instance[$plugin_id];
    }
    $this->instance[$plugin_id] = $this->createInstance($plugin_id);
    return $this->instance[$plugin_id];
  }

  /**
   * Load an EmbedSource plugin instance from user input.
   *
   * If the input does not match an active EmbedSource plugin, then this method
   * returns the broken EmbedSource plugin.
   *
   * @param string $input
   *   A raw embed code added by a content author.
   *
   * @return \Drupal\ys_embed\Plugin\EmbedSourceInterface
   *   A fully configured plugin instance.
   */
  public function loadPluginByCode($input) {
    $source = $this->findEmbedSource($input);
    $id = !empty($source) ? $source['id'] : static::BROKEN_ID;
    return $this->loadPluginById($id);
  }

  /**
   * Load all EmbedSource plugins.
   *
   * @return array
   *   An array of instances of all EmbedSource plugins.
   */
  public function loadAll() {
    foreach ($this->getSources() as $plugin_id => $source) {
      $this->loadPluginById($plugin_id);
    }
    return $this->instance;
  }

}
