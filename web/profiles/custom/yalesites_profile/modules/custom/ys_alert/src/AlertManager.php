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
  public function getAlertTypes(): array {
    $config = (array) $this->alertConfig->get('alert_types');
    return !empty($config) ? $config : [];
  }

  /**
   * Get array of options for alert-types form.
   *
   * This is used by the AlertSettings form to display a list of alert types.
   * Options are keyed by alert id and have the value of alert label.
   *
   * @return array
   *   An array of alert types to display as form options.
   */
  public function getTypeOptions(): array {
    $types = $this->getAlertTypes();
    $options = array_combine(
      array_column($types, 'id'),
      array_column($types, 'label')
    );
    return !empty($options) ? $options : [];
  }

  /**
   * Get the alert type definition from its id.
   *
   * @param string $id
   *   The id for a given alert type defined in the config file.
   *
   * @return array
   *   The alert definition matching the given id.
   */
  public function getTypeById($id): array {
    return current(
      array_filter(
        $this->getAlertTypes(),
        function ($type) use ($id) {
          return $type['id'] == $id;
        }
      )
    );
  }

  /**
   * Get the alert type description from its id.
   *
   * @param string $id
   *   The id for a given alert type defined in the config file.
   *
   * @return string
   *   The description for a given alert or an empty string.
   */
  public function getTypeDescription(string $id): string {
    $type = $this->getTypeById($id);
    return !empty($type['description']) ? $type['description'] : '';
  }

}
