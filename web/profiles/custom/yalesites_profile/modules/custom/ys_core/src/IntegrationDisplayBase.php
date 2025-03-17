<?php

namespace Drupal\ys_core;

/**
 * Base class for integration display.
 */
class IntegrationDisplayBase {

  const INTEGRATION_NAME = 'ys_noexist';

  /**
   * The configuration object.
   *
   * @var \Drupal\Core\Config\Config
   */
  public $config;

  /**
   * Constructs a new instance of the class.
   *
   * @param \Drupal\Core\Config\Config $config
   *   The configuration object.
   */
  public function __construct($config) {
    $this->config = $config;
  }

  /**
   * Creates a new instance of the class.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   */
  public static function create($container) {
    return new static($container->get('config.factory')->get(static::INTEGRATION_NAME));
  }

  /**
   * Tells if the integration is turned on.
   *
   * @return bool
   *   TRUE if the integration is turned on, FALSE otherwise.
   */
  public function isTurnedOn(): bool {
    return $this->config->get('ys_noexist_enabled');
  }

}
