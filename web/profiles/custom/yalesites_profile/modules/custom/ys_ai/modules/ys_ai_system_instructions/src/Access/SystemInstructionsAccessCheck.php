<?php

namespace Drupal\ys_ai_system_instructions\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Checks access to system instructions based on configuration.
 */
class SystemInstructionsAccessCheck implements AccessInterface {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a SystemInstructionsAccessCheck object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Checks access to system instructions functionality.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account) {
    // Check if user has the required permission.
    if (!$account->hasPermission('manage ys ai system instructions')) {
      return AccessResult::forbidden('User lacks required permission.');
    }

    // Check if the integration is enabled in the integrations settings.
    $integrationsConfig = $this->configFactory->get('ys_integrations.integration_settings');
    if (!$integrationsConfig->get('ys_ai_system_instructions')) {
      return AccessResult::forbidden('System instructions integration is not enabled.')
        ->addCacheableDependency($integrationsConfig);
    }

    // Check if the feature is enabled and fully configured.
    $config = $this->configFactory->get('ys_ai_system_instructions.settings');
    if (!$config->get('system_instructions_enabled')) {
      return AccessResult::forbidden('System instruction modification is not enabled.')
        ->addCacheableDependency($config);
    }

    // Verify all required API configuration is present.
    $required_settings = [
      'system_instructions_api_endpoint',
      'system_instructions_web_app_name',
      'system_instructions_api_key',
    ];

    foreach ($required_settings as $setting) {
      if (empty($config->get($setting))) {
        return AccessResult::forbidden('System instructions are not fully configured.')
          ->addCacheableDependency($config);
      }
    }

    return AccessResult::allowed()
      ->addCacheableDependency($config)
      ->addCacheableDependency($integrationsConfig)
      ->cachePerPermissions();
  }

}
