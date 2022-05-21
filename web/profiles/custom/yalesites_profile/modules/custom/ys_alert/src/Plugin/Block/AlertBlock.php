<?php

namespace Drupal\ys_alert\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Provides a block with a simple text.
 *
 * @Block(
 *   id = "alert_block",
 *   admin_label = @Translation("Alert block"),
 * )
 */
class AlertBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a SyndicateBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $configFactory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->configFactory->get('ys_alert.settings');
    return [
      '#theme' => 'ys_alert',
      '#id' => $config->get('id'),
      '#status' => $config->get('status'),
      '#type' => $config->get('type'),
      '#headline' => $config->get('headline'),
      '#message' => $config->get('message'),
      '#link_title' => $config->get('link_title'),
      '#link_url' => $config->get('link_url'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    if (!$this->configFactory->get('ys_alert.settings')->get('status')) {
      return AccessResult::forbidden();
    }
    return AccessResult::allowedIfHasPermission($account, 'access content');
  }

}
