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
    $chat_config = $this->configFactory->get('ai_engine_chat.settings');

    // Check if Azure is configured AND AI is enabled.
    $azure_configured = $chat_config->get('azure_base_url') !== NULL;
    $ai_enabled = $chat_config->get('enable') ?? FALSE;

    $has_permission = $account->hasPermission('configure ys ai user settings');
    $integration_enabled = $this->configFactory->get('ys_integrations.integration_settings')->get('ys_ai') ?? FALSE;

    return AccessResult::allowedIf(
      $azure_configured && $ai_enabled && $has_permission && $integration_enabled
    );
  }

}
