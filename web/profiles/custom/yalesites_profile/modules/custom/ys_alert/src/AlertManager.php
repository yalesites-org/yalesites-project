<?php

namespace Drupal\ys_alert;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service for interacting with YaleSites alerts and their configuration.
 */
class AlertManager implements ContainerInjectionInterface {

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $alertConfig;

  /**
   * Constructs a new AlertManager object.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->alertConfig = $config_factory->getEditable('ys_alert.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
    );
  }

  /**
   * Get alert type data from ys_alert.settings config file.
   *
   * @return array
   *   An array of alert type definitions.
   */
  public function getAlertTypes() {
    return (array) $this->alertConfig->get('alert_types');
  }

  public function getTypeOptions() {
    $types = $this->getAlertTypes();
    return array_combine(
      array_column($types, 'id'),
      array_column($types, 'label')
    );
  }

  public function getTypeById($id) {
    return current(
      array_filter(
        $this->getAlertTypes(),
        function ($type) use ($id) {
          return $type['id'] == $id;
        }
      )
    );
  }

  public function getTypeDescription($id) {
    $type = $this->getTypeById($id);
    return !empty($type['description']) ? $type['description'] : '';
  }

}
