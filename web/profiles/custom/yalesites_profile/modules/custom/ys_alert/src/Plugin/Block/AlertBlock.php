<?php

namespace Drupal\ys_alert\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ys_alert\AlertManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a block to render an active alert.
 *
 * @Block(
 *   id = "alert_block",
 *   admin_label = @Translation("Alert block"),
 * )
 */
class AlertBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The YaleSites alerts management service.
   *
   * @var \Drupal\ys_alert\AlertManager
   */
  protected $alertManager;

  /**
   * Constructs a SyndicateBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\ys_alert\AlertManager $alert_manager
   *   The YaleSites Alert Manager service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    AlertManager $alert_manager
    ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->alertManager = $alert_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
    ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ys_alert.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $alert = $this->alertManager->getAlert();
    return [
      '#theme' => 'ys_alert',
      '#id' => $alert['id'],
      '#status' => $alert['status'],
      '#type' => $alert['type'],
      '#headline' => $alert['headline'],
      '#message' => $alert['message'],
      '#link_title' => $alert['link_title'],
      '#link_url' => $alert['link_url'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    if (!$this->alertManager->showAlert()) {
      return AccessResult::forbidden();
    }
    return AccessResult::allowedIfHasPermission($account, 'access content');
  }

}
