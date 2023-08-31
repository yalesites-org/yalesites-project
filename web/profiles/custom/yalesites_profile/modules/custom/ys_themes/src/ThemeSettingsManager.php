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
        'four' => [
          'label' => 'Elm City Nights',
          'color_theme' => 'four',
        ],
        'five' => [
          'label' => 'Quiet Corner',
          'color_theme' => 'five',
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
          'label' => 'Deep',
          'color_theme' => 'five',
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
          'color_theme_2' => '--color-basic-white',
        ],
        'two' => [
          'label' => 'Highlight & Light Gray',
          'color_theme' => 'three',
          'color_theme_2' => '--color-gray-100',
        ],
        'three' => [
          'label' => 'Highlight & Base',
          'color_theme' => 'three',
          'color_theme_2' => 'one',
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
          'color_theme_2' => '--color-gray-100',
        ],
        'two' => [
          'label' => 'Deep & White',
          'color_theme' => 'five',
          'color_theme_2' => '--color-basic-white',
        ],
        'three' => [
          'label' => 'Highlight & Light Gray',
          'color_theme' => 'three',
          'color_theme_2' => '--color-gray-100',
        ],
        'four' => [
          'label' => 'Highlight & Dark Gray',
          'color_theme' => 'three',
          'color_theme_2' => '--color-gray-800',
        ],
        'five' => [
          'label' => 'Highlight & Base',
          'color_theme' => 'three',
          'color_theme_2' => 'one',
        ],
      ],
    ],
    'animation_style' => [
      'name' => 'Animation Style',
      'prop_type' => 'element',
      'selector' => '[data-site-animation]',
      'default' => 'minimal',
      'values' => [
        'minimal' => [
          'label' => 'Minimal',
        ],
        'artistic' => [
          'label' => 'Artistic',
        ],
      ],
    ],
  ];

  /**
   * A string to represent a false value in an HTML attribute.
   *
   * @var string
   */
  const ATTRIBUTE_FALSE = "['false']";

  /**
   * A string to represent a true value in an HTML attribute.
   *
   * @var string
   */
  const ATTRIBUTE_TRUE = "['true']";

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

  /**
   * Check if a component should animate based on global and component settings.
   *
   * This method checks the sitewide settings (levers) to see if an animation
   * style (such as 'artistic') has been set. It then gets the settings on the
   * component to see if an author disabled animation for a given block. The
   * method returns a string that represents a true or false attribute to add
   * to the component.
   *
   * @param ?string $field_style_motion_value
   *   The value stored in field_style_motion on a component.
   *
   * @return string
   *   A true or false HTML attribute to render to enable or disable animation.
   */
  public function allowAnimation(?string $field_style_motion_value) {
    $globalStyle = $this->getSetting('animation_style');
    // Disable component animation if a global animation style is not set.
    if (empty($globalStyle) || $globalStyle == 'minimal') {
      return self::ATTRIBUTE_FALSE;
    }
    // Disable component animation if componet style is set to 'minimal'.
    if ($field_style_motion_value == 'minimal') {
      return self::ATTRIBUTE_FALSE;
    }
    return self::ATTRIBUTE_TRUE;
  }

}
