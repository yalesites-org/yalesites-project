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
   * The prop_type is used for the JavaScript form to dynamically change the
   * elements on the page. 'root' is a CSS root variable, 'element' is a
   * data attribute on a specific element.
   *
   * The selector is the CSS selector of the attribute that is used in the DOM.
   * For root CSS variables, this would be something like --color-theme-action
   * For data attributes on an element, this would be something like
   * header[data-component-theme] for a specific element or to match all
   * elements with the same data attribute, use [data-component-theme]
   *
   * @var array
   */
  const THEME_SETTINGS = [
    'global_theme' => [
      'name' => 'Color Palette',
      'values' => [
        'one' => 'Old Blues',
        'two' => 'New Haven Green',
        'three' => 'Shoreline Summer',
        'four' => 'Elm City Nights',
        'five' => 'Quiet Corner',
      ],
      'prop_type' => 'element',
      'selector' => '[data-global-theme]',
      'default' => 'one',
    ],
    'nav_position' => [
      'name' => 'Navigation Position',
      'values' => [
        'right' => 'Right',
        'center' => 'Center',
        'left' => 'Left',
      ],
      'prop_type' => 'element',
      'selector' => '[data-site-header-nav-position]',
      'default' => 'left',
    ],
    'nav_type' => [
      'name' => 'Navigation Type',
      'values' => [
        'mega' => 'Mega Menu',
        'basic' => 'Basic Menu',
      ],
      'prop_type' => 'element',
      'selector' => '[data-menu-variation]',
      'default' => 'mega',
    ],
    'header_theme' => [
      'name' => 'Header Theme',
      'values' => [
        'one' => 'Base',
        'two' => 'Action',
        'three' => 'Highlight',
      ],
      'prop_type' => 'element',
      'selector' => 'header[data-header-theme]',
      'default' => 'one',
    ],
    'footer_theme' => [
      'name' => 'Footer Theme',
      'values' => [
        'one' => 'Base',
        'two' => 'Action',
        'three' => 'Highlight',
        'four' => 'Subtle',
        'five' => 'Deep',
      ],
      'prop_type' => 'element',
      'selector' => 'footer[data-footer-theme]',
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
