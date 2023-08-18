<?php

namespace Drupal\ys_themes\Form\ElementTypes;

/**
 * Creates form elements for radios with default values for theme settings.
 */
class Radios extends ElementBase {

  /**
   * Generates the form element.
   */
  public function toElementDefinition() {
    return [
      '#type' => 'radios',
      '#title' => $this->translation->translate(
        '@setting_name',
        ['@setting_name' => $this->settingDetail['name']]
      ),
      '#options' => $this->options,
      '#default_value' => $this->themeSettingsManager->getSetting($this->settingName) ?: $this->settingDetail['default'],
      '#attributes' => [
        'class' => [
          'ys-themes--setting',
        ],
        'data-prop-type' => $this->settingDetail['prop_type'],
        'data-selector' => $this->settingDetail['selector'],
      ],
    ];
  }

}
