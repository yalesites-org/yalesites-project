<?php

namespace Drupal\ys_core;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service for managing YaleSites core settings.
 */
class CoreSettingsManager {

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $yaleCoreSettings;

  /**
   * Construct function for core settings manager.
   *
   * @param Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Configuration factory.
   */
  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->yaleCoreSettings = $configFactory->getEditable('ys_core.site');
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
   * Gets core setting from config.
   *
   * @param string $setting_name
   *   Setting machine name.
   */
  public function getSetting($setting_name) {
    return($this->yaleCoreSettings->get($setting_name));
  }

  /**
   * Gets all core settings from config.
   */
  public function getAllSettings() {
    return($this->yaleCoreSettings->get(''));
  }

  /**
   * Sets core setting to config.
   *
   * @param string $setting_name
   *   Setting machine name.
   * @param string $value
   *   Value to set.
   */
  public function setSetting($setting_name, $value) {
    $this->yaleCoreSettings->set($setting_name, $value);
    $this->yaleCoreSettings->save(TRUE);
  }

}
