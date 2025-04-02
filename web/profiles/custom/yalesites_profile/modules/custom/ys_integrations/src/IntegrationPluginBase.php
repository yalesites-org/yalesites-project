<?php

namespace Drupal\ys_integrations;

use Drupal\Core\Url;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Base class for integration plugins.
 */
class IntegrationPluginBase implements IntegrationPluginInterface, ContainerFactoryPluginInterface {

  use \Drupal\Core\StringTranslation\StringTranslationTrait;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The plugin definition.
   *
   * @var array
   */
  protected $pluginDefinition;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new IntegrationPluginBase object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param array $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(ConfigFactoryInterface $config_factory, array $plugin_definition, AccountInterface $current_user) {
    $this->configFactory = $config_factory;
    $this->pluginDefinition = $plugin_definition;
    $this->currentUser = $current_user;
  }

  /**
   * Creates a new integration plugin.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   *
   * @return static
   *   A new integration plugin instance.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('config.factory'),
      $plugin_definition,
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isTurnedOn(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function configUrl() {
    return Url::fromRoute('ys_integrations.integrations_settings');
  }

  /**
   * {@inheritdoc}
   */
  public function syncUrl() {
    return Url::fromRoute('ys_integrations.integrations_settings');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function save($form, $form_state): void {
  }

}
