<?php

namespace Drupal\ys_themes;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service for managing YaleSites theme settings.
 */
class ThemeSettingsManager {

  /**
   * Theme setting values and default value.
   *
   * @var array
   */
  const THEME_SETTINGS = [
    'action_color' => [
      'name' => 'Action Color',
      'values' => [
        'blue-yale' => 'Blue',
        'basic-black' => 'Black',
      ],
      'default' => 'blue-yale',
    ],
    'accent_color' => [
      'name' => 'Accent Color',
      'values' => [
        'blue-light' => 'Light Blue',
      ],
      'default' => 'blue-light',
    ],
    'pull_quote_color' => [
      'name' => 'Pull Quote Color',
      'values' => [
        'one' => 'One',
        'two' => 'Two',
        'three' => 'Three',
      ],
      'default' => 'one',
    ],
    'line_color' => [
      'name' => 'Line Color',
      'values' => [
        'gray-500' => 'Gray',
        'blue-yale' => 'Blue',
        'accent' => 'Accent',
      ],
      'default' => 'gray-500',
    ],
    'line_thickness' => [
      'name' => 'Line Thickness',
      'values' => [
        'thin' => 'Thin',
        'thick' => 'Thick',
      ],
      'default' => 'thick',
    ],
    'nav_position' => [
      'name' => 'Navigation Position',
      'values' => [
        'right' => 'Right',
        'center' => 'Center',
        'left' => 'Left',
      ],
      'default' => 'left',
    ],
    'nav_type' => [
      'name' => 'Navigation Type',
      'values' => [
        'mega' => 'Mega Menu',
        'basic' => 'Basic Menu',
      ],
      'default' => 'mega',
    ],
    'header_theme' => [
      'name' => 'Header Theme',
      'values' => [
        'one' => 'One',
        'two' => 'Two',
        'three' => 'Three',
      ],
      'default' => 'one',
    ],
    'footer_theme' => [
      'name' => 'Footer Theme',
      'values' => [
        'one' => 'One',
        'two' => 'Two',
        'three' => 'Three',
        'four' => 'Four',
        'five' => 'Five',
      ],
      'default' => 'one',
    ],
  ];

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $yaleThemeSettings;

  /**
   * Construct function for theme settings manager.
   *
   * @param Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Configuration factory.
   */
  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->yaleThemeSettings = $configFactory->getEditable('ys_themes.theme_settings');
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
   * Gets all theme settings defaults and values.
   *
   * @param string $setting_name
   *   If passed, will return the options for that setting name only.
   */
  public function getOptions($setting_name = NULL) {
    if ($setting_name) {
      return self::THEME_SETTINGS[$setting_name]['values'];
    }
    else {
      return self::THEME_SETTINGS;
    }

  }

  /**
   * Gets theme setting from config.
   *
   * @param string $setting_name
   *   Setting machine name.
   */
  public function getSetting($setting_name) {
    return($this->yaleThemeSettings->get($setting_name));
  }

  /**
   * Gets all theme settings from config.
   */
  public function getAllSettings() {
    return($this->yaleThemeSettings->get(''));
  }

  /**
   * Sets theme setting to config.
   *
   * @param string $setting_name
   *   Setting machine name.
   * @param string $value
   *   Value to set.
   */
  public function setSetting($setting_name, $value) {
    $this->yaleThemeSettings->set($setting_name, $value);
    $this->yaleThemeSettings->save(TRUE);
  }

}
