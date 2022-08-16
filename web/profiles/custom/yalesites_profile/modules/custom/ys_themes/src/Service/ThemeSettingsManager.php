<?php

namespace Drupal\ys_themes\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

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
        'blue' => 'Blue',
      ],
      'default' => 'blue',
    ],
    'pull_quote_color' => [
      'name' => 'Pull Quote Color',
      'values' => [
        'gray-200' => 'Light Gray',
        'gray-500' => 'Gray',
        'blue' => 'Blue',
        'accent' => 'Accent',
      ],
      'default' => 'gray-500',
    ],
    'line_color' => [
      'name' => 'Line Color',
      'values' => [
        'gray-500' => 'Gray',
        'blue' => 'Blue',
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
      'default' => 'right',
    ],
    'header_theme' => [
      'name' => 'Header Theme',
      'values' => [
        'white' => 'White',
        'gray-100' => 'Light Gray',
        'blue' => 'Blue',
      ],
      'default' => 'white',
    ],
    'footer_theme' => [
      'name' => 'Footer Theme',
      'values' => [
        'white' => 'White',
        'gray-100' => 'Light Gray',
        'gray-700' => 'Gray',
        'gray-800' => 'Dark Gray',
        'blue' => 'Blue',
      ],
      'default' => 'gray-700',
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
