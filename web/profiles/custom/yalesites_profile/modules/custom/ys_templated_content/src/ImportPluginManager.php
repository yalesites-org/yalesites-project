<?php

namespace Drupal\ys_templated_content;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * The ImportPluginManager class.
 */
class ImportPluginManager extends DefaultPluginManager {

  /**
   * Constructs a FormatterPluginManager object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/TemplatedImporter',
      $namespaces,
      $module_handler,
      'Drupal\ys_templated_content\TemplateImporterInterface',
      'Drupal\ys_templated_content\Annotation\TemplatedImporter'
    );

    $this->setCacheBackend($cache_backend, 'ys_templated_content_importers');
    $this->alterInfo('template_importer_info');
  }

  /**
   * Get the plugin id from the extension.
   *
   * @param string $extension
   *   The extension.
   *
   * @return string|null
   *   The plugin id or NULL.
   */
  public function getPluginIdFromExtension($extension) {
    $plugin_definitions = $this->getDefinitions();
    // Log the number of definitions.
    foreach ($plugin_definitions as $plugin_id => $plugin_definition) {
      if ($plugin_definition['extension'] == $extension) {
        return $plugin_id;
      }
    }
    return NULL;
  }

}
