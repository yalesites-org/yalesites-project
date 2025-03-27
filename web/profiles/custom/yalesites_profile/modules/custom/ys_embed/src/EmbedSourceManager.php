<?php

namespace Drupal\ys_embed\EmbedSource;

use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

class EmbedSourceManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/EmbedSource', $namespaces, $module_handler, 'Drupal\ys_embed\EmbedSource\EmbedSourceInterface', 'Drupal\ys_embed\Annotation\EmbedSource');

    $this->alterInfo('ys_embed_embed_source_info');
    $this->setCacheBackend($cache_backend, 'ys_embed_embed_source_plugins');
  }

  public function findEmbedSource($embed_code) {
    \Drupal::logger('ys_embed')->notice('Finding embed source for code: @code', ['@code' => $embed_code]);
    
    // First check for libcal_weekly
    $libcal_weekly = $this->createInstance('libcal_weekly');
    if ($libcal_weekly->matches($embed_code)) {
      \Drupal::logger('ys_embed')->notice('Found libcal_weekly match');
      return $libcal_weekly;
    }
    
    // Then check for libcal
    $libcal = $this->createInstance('libcal');
    if ($libcal->matches($embed_code)) {
      \Drupal::logger('ys_embed')->notice('Found libcal match');
      return $libcal;
    }
    
    // Then check for other sources
    foreach ($this->getDefinitions() as $id => $definition) {
      if ($id === 'libcal' || $id === 'libcal_weekly') {
        continue;
      }
      $instance = $this->createInstance($id);
      if ($instance->matches($embed_code)) {
        \Drupal::logger('ys_embed')->notice('Found match for source: @source', ['@source' => $id]);
        return $instance;
      }
    }
    
    \Drupal::logger('ys_embed')->notice('No embed source found');
    return NULL;
  }
} 