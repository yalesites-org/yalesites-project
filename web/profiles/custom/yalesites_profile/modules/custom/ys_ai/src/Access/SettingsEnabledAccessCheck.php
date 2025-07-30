<?php

namespace Drupal\ys_ai\Access;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access check for AI settings.
 */
class SettingsEnabledAccessCheck {
  /**
   * The configuration factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new SettingsEnabledAccessCheck.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory service.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Creates an instance of the access check.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }

  /**
   * Check if the AI settings are enabled and if the user has permission.
   */
  public function access(AccountInterface $account) {
    $enabled = $this->configFactory->get('ai_engine_chat.settings')->get('azure_base_url') ?? FALSE;
    $has_permission = $account->hasPermission('configure ys ai user settings');
    $integration_enabled = $this->configFactory->get('ys_integrations.integration_settings')->get('ys_ai') ?? FALSE;
    return AccessResult::allowedIf((bool) $enabled && $has_permission && (bool) $integration_enabled);
  }

}
