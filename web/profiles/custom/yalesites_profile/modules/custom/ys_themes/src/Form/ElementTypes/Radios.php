<?php

namespace Drupal\ys_themes\Form\ElementTypes;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Creates form elements for radios with default values for theme settings.
 */
class Radios {
  use StringTranslationTrait;

  /**
   * Generates the form element.
   */
  public function toElementDefinition($settingName, $settingDetail, $options, $themeSettingsManager) {
    $translation = $this->getStringTranslation();

    return [
      '#type' => 'radios',
      '#title' => $translation->translate(
        '@setting_name',
        ['@setting_name' => $settingDetail['name']]
      ),
      '#options' => $options,
      '#default_value' => $themeSettingsManager->getSetting($settingName) ?: $settingDetail['default'],
      '#attributes' => [
        'class' => [
          'ys-themes--setting',
        ],
        'data-prop-type' => $settingDetail['prop_type'],
        'data-selector' => $settingDetail['selector'],
      ],
    ];
  }

}
