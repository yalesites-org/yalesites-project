<?php

namespace Drupal\ys_themes;

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
      new TwigFunction('getSettingValues', [$this, 'getSettingValues']),
      new TwigFunction('animateItem', [$this, 'animateItem']),
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
   * Function that returns setting values for a specific setting.
   */
  public function getSettingValues($setting_name) {
    return $this->themeSettingsManager->getOptions($setting_name);
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

  /**
   * Check if a component should animate based on global and component settings.
   *
   * This method checks the sitewide settings (levers) to see if an animation
   * style (such as 'artistic') has been set. It then gets the settings on the
   * component to see if an author disabled animation for a given block. This
   * method sets the data-animate-item attribute to 'enable' or 'disable'.
   *
   * @param ?string $field_style_motion_val
   *   The value stored in field_style_motion on a component.
   *
   * @return string
   *   String 'enable' or 'disable' to place on component as HTML attribute.
   */
  public function animateItem(?string $field_style_motion_val): string {
    return $this->themeSettingsManager->animateItem($field_style_motion_val);
  }

}
