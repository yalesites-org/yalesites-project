<?php

namespace Drupal\ys_core;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\ys_core\Attribute\PlatformAdminSetting;

/**
 * Manages platform admin setting plugins.
 */
class PlatformAdminSettingManager extends DefaultPluginManager {

  /**
   * Constructs a PlatformAdminSettingManager object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/PlatformAdminSetting', $namespaces, $module_handler, 'Drupal\ys_core\PlatformAdminSettingInterface', PlatformAdminSetting::class);
    $this->setCacheBackend($cache_backend, 'ys_core_platform_admin_setting_plugins');
    $this->alterInfo('ys_core_platform_admin_setting_info');
  }

}
