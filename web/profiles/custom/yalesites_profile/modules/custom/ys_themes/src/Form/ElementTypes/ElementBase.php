<?php

namespace Drupal\ys_themes\Form\ElementTypes;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ys_themes\Form\ElementTypes\Radios;
use Drupal\ys_themes\Form\ElementTypes\Select;

/**
 * Base class for elements to share off of.
 */
class ElementBase {
  use StringTranslationTrait;

  /**
   * Theme settings manager.
   *
   * @var \Drupal\ys_themes\ThemeSettingsManager
   */
  protected $themeSettingsManager;

  /**
   * Setting name.
   *
   * @var string
   */
  protected $settingName;

  /**
   * Setting details.
   *
   * @var array
   */
  protected $settingDetail;

  /**
   * Setting options.
   *
   * @var array
   */
  protected $options;

  /**
   * Translation service.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface
   */
  protected $translation;

  /**
   * Creates a new element.
   */
  public function __construct($settingName, $settingDetail, $options, $themeSettingsManager) {
    $this->settingName = $settingName;
    $this->settingDetail = $settingDetail;
    $this->options = $options;
    $this->themeSettingsManager = $themeSettingsManager;
    $this->translation = $this->getStringTranslation();
  }

  /**
   * Generates the form element.
   *
   * @return array
   *   Form element definition.
   */
  public function toElementDefinition() {
    return [];
  }

  /**
   * Element factory.
   */
  public static function getElement($settingName, $settingDetail, $options, $themeSettingsManager) {
    $element = NULL;

    switch ($settingDetail['type'] ?? 'radios') {
      case 'select':
        $element = new Select($settingName, $settingDetail, $options, $themeSettingsManager);

        break;

      default:
        $element = new Radios($settingName, $settingDetail, $options, $themeSettingsManager);
        break;
    }

    return $element;
  }

}
