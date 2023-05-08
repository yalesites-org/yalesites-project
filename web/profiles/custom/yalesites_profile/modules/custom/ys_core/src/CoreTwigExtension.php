<?php

namespace Drupal\ys_core;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig functions to retrieve YaleSites core settings.
 */
class CoreTwigExtension extends AbstractExtension {

  /**
   * Core Settings Manager.
   *
   * @var \Drupal\ys_core\CoreSettingsManager
   */
  protected $coreSettingsManager;

  /**
   * {@inheritdoc}
   */
  public function getFunctions() {
    return [
      new TwigFunction('getCoreSetting', [$this, 'getCoreSetting']),
      new TwigFunction('getAllCoreSettings', [$this, 'getAllCoreSettings']),
    ];
  }

  /**
   * Actual function that returns core setting based on setting machine name.
   *
   * @param string $setting_name
   *   Setting machine name to pass in to retrieve setting from config.
   */
  public function getCoreSetting($setting_name) {
    return $this->coreSettingsManager->getSetting($setting_name);
  }

  /**
   * Function that returns all core settings.
   */
  public function getAllCoreSettings() {
    return $this->coreSettingsManager->getAllSettings();
  }

  /**
   * Constructs the object.
   *
   * @param \Drupal\ys_core\Service\CoreSettingsManager $core_settings_manager
   *   The Core Settings Manager.
   */
  public function __construct(CoreSettingsManager $core_settings_manager) {
    $this->coreSettingsManager = $core_settings_manager;
  }

}
