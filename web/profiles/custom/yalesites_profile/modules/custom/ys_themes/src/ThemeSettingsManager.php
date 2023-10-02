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
      'prop_type' => 'element',
      'selector' => '[data-global-theme]',
      'default' => 'one',
      'values' => [
        'one' => [
          'label' => 'Old Blues',
          'color_theme' => 'one',
        ],
        'two' => [
          'label' => 'New Haven Green',
          'color_theme' => 'two',
        ],
        'three' => [
          'label' => 'Shoreline Summer',
          'color_theme' => 'three',
        ],
      ],
    ],
    'nav_position' => [
      'name' => 'Navigation Position',
      'prop_type' => 'element',
      'selector' => '[data-site-header-nav-position]',
      'default' => 'left',
      'values' => [
        'right' => [
          'label' => 'Right',
        ],
        'center' => [
          'label' => 'Center',
        ],
        'left' => [
          'label' => 'Left',
        ],
      ],
    ],
    'nav_type' => [
      'name' => 'Navigation Type',
      'prop_type' => 'element',
      'selector' => '[data-menu-variation]',
      'default' => 'mega',
      'values' => [
        'mega' => [
          'label' => 'Mega Menu',
        ],
        'basic' => [
          'label' => 'Basic Menu',
        ],
      ],
    ],
    'button_theme' => [
      'name' => 'Button Theme',
      'prop_type' => 'element',
      'selector' => '[data-cta-theme]',
      'default' => 'one',
      'values' => [
        'one' => [
          'label' => 'Base',
          'color_theme' => 'one',
        ],
        'two' => [
          'label' => 'Action',
          'color_theme' => 'two',
        ],
        'three' => [
          'label' => 'Highlight',
          'color_theme' => 'three',
        ],
        'four' => [
          'label' => 'Subtle',
          'color_theme' => 'four',
        ],
        'five' => [
          'label' => 'Deep',
          'color_theme' => 'five',
        ],
        'six' => [
          'label' => 'Yale Blue',
          'color_theme' => 'six',
        ],
        'seven' => [
          'label' => 'Gray 800',
          'color_theme' => 'seven',
        ],
      ],
    ],
    'header_theme' => [
      'name' => 'Header Theme',
      'prop_type' => 'element',
      'selector' => 'header[data-header-theme]',
      'default' => 'one',
      'values' => [
        'one' => [
          'label' => 'Base & White',
          'color_theme' => 'one',
          'color_theme_2' => 'eight',
        ],
        'two' => [
          'label' => 'Action & Dark Gray',
          'color_theme' => 'two',
          'color_theme_2' => 'seven',
        ],
        'three' => [
          'label' => 'Highlight & Yale Blue',
          'color_theme' => 'three',
          'color_theme_2' => 'six',
        ],
      ],
    ],
    'header_accent' => [
      'name' => 'Header Accent',
      'prop_type' => 'element',
      'selector' => 'header[data-header-accent]',
      'default' => 'one',
      'values' => [
        'one' => [
          'label' => 'One',
          'color_theme' => 'one',
        ],
        'two' => [
          'label' => 'Two',
          'color_theme' => 'two',
        ],
        'three' => [
          'label' => 'Three',
          'color_theme' => 'three',
        ],
        'four' => [
          'label' => 'Four',
          'color_theme' => 'four',
        ],
        'five' => [
          'label' => 'Five',
          'color_theme' => 'five',
        ],
        'six' => [
          'label' => 'Six',
          'color_theme' => 'six',
        ],
        'seven' => [
          'label' => 'Seven',
          'color_theme' => 'seven',
        ],
        'eight' => [
          'label' => 'Eight',
          'color_theme' => 'eight',
        ],
      ],
    ],
    'footer_theme' => [
      'name' => 'Footer Theme',
      'prop_type' => 'element',
      'selector' => 'footer[data-footer-theme]',
      'default' => 'one',
      'values' => [
        'one' => [
          'label' => 'Base & White',
          'color_theme' => 'one',
          'color_theme_2' => 'eight',
        ],
        'two' => [
          'label' => 'Action & Dark Gray',
          'color_theme' => 'two',
          'color_theme_2' => 'seven',
        ],
        'three' => [
          'label' => 'Highlight & Yale Blue',
          'color_theme' => 'three',
          'color_theme_2' => 'six',
        ],
      ],
    ],
    'footer_accent' => [
      'name' => 'Footer Accent',
      'prop_type' => 'element',
      'selector' => 'footer[data-footer-accent]',
      'default' => 'one',
      'values' => [
        'one' => [
          'label' => 'One',
          'color_theme' => 'one',
        ],
        'two' => [
          'label' => 'Two',
          'color_theme' => 'two',
        ],
        'three' => [
          'label' => 'Three',
          'color_theme' => 'three',
        ],
        'four' => [
          'label' => 'Four',
          'color_theme' => 'four',
        ],
        'five' => [
          'label' => 'Five',
          'color_theme' => 'five',
        ],
        'six' => [
          'label' => 'Six',
          'color_theme' => 'six',
        ],
        'seven' => [
          'label' => 'Seven',
          'color_theme' => 'seven',
        ],
        'eight' => [
          'label' => 'Eight',
          'color_theme' => 'eight',
        ],
      ],
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
