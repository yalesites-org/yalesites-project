<?php

namespace Drupal\ys_beacon\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Checks access to the Beacon system instructions screens.
 */
class SystemInstructionsAccessCheck implements AccessInterface {

  /**
   * Constructs a SystemInstructionsAccessCheck object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Checks access to the system instructions functionality.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account) {
    if (!$account->hasPermission('manage ys beacon system instructions')) {
      return AccessResult::forbidden('User lacks required permission.')
        ->cachePerPermissions();
    }

    $integrationsConfig = $this->configFactory->get('ys_integrations.integration_settings');
    if (!$integrationsConfig->get('ys_beacon')) {
      return AccessResult::forbidden('The Beacon integration is not enabled.')
        ->addCacheableDependency($integrationsConfig)
        ->cachePerPermissions();
    }

    return AccessResult::allowed()
      ->addCacheableDependency($integrationsConfig)
      ->cachePerPermissions();
  }

}
