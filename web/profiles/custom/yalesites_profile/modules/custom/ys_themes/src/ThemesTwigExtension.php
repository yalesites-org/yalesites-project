<?php

namespace Drupal\ys_themes;

use Drupal\ys_themes\ThemeSettingsManager;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;


/**
 * Twig functions to retrieve YaleSites theme settings.
 */
class ThemesTwigExtension extends AbstractExtension {

  /**
   * Themes Settings Manager.
   *
   * @var \Drupal\ys_themes\ThemeSettingsManager
   */
  protected $themeSettingsManager;

  /**
   * {@inheritdoc}
   */
  public function getFunctions() {
    return [
      new TwigFunction('getThemeSetting', [$this, 'getThemeSetting']),
      new TwigFunction('getAllThemeSettings', [$this, 'getAllThemeSettings']),
    ];
  }

  /**
   * Actual function that returns theme setting based on setting machine name.
   *
   * @param string $setting_name
   *   Setting machine name to pass in to retrieve setting from config.
   */
  public function getThemeSetting($setting_name) {
    return $this->themeSettingsManager->getSetting($setting_name);
  }

  /**
   * Function that returns all theme settings.
   */
  public function getAllThemeSettings() {
    return $this->themeSettingsManager->getAllSettings();
  }

  /**
   * Constructs the object.
   *
   * @param \Drupal\ys_themes\Service\ThemeSettingsManager $theme_settings_manager
   *   The Theme Settings Manager.
   */
  public function __construct(ThemeSettingsManager $theme_settings_manager) {
    $this->themeSettingsManager = $theme_settings_manager;
  }

}
