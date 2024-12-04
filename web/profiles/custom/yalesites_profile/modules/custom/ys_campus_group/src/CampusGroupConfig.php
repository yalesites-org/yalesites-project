<?php

namespace Drupal\ys_campus_group;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Config class for managing campus group configurations.
 *
 * This service provides a way to access and manage the configuration
 * settings for the "ys_campus_group" module.
 */
class CampusGroupConfig implements ContainerInjectionInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs the CampusGroupService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory->getEditable('ys_campus_group.settings');
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
   * Gets the configuration for ys_campus_group.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The configuration object.
   */
  public function getConfig() {
    return $this->configFactory;
  }

}
