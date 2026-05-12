<?php

namespace Drupal\ys_dcn_field\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'dcn_field_default' formatter.
 *
 * @FieldFormatter(
 *   id = "dcn_field_default",
 *   label = @Translation("Default"),
 *   field_types = {
 *     "dcn_field"
 *   }
 * )
 */
class DcnFieldDefaultFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'separator' => ' ',
      'show_label' => TRUE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['separator'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Separator'),
      '#description' => $this->t('The text to display between the DCN type and identifier.'),
      '#default_value' => $this->getSetting('separator'),
      '#size' => 10,
    ];

    $elements['show_label'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show DCN type label'),
      '#description' => $this->t('If checked, displays the DCN type term name. Otherwise, only the identifier is shown.'),
      '#default_value' => $this->getSetting('show_label'),
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $separator = $this->getSetting('separator');
    $show_label = $this->getSetting('show_label');

    if ($show_label) {
      $summary[] = $this->t('Format: DCN Type@separator@identifier', [
        '@separator' => $separator,
        '@identifier' => '[identifier]',
      ]);
    }
    else {
      $summary[] = $this->t('Format: [identifier] only');
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $separator = $this->getSetting('separator');
    $show_label = $this->getSetting('show_label');

    foreach ($items as $delta => $item) {
      // Load the DCN type term.
      $dcn_type_term = $item->getDcnType();
      $dcn_identifier = $item->dcn_identifier;

      if ($dcn_type_term && $dcn_identifier) {
        if ($show_label) {
          $elements[$delta] = [
            '#markup' => $dcn_type_term->getName() . $separator . $dcn_identifier,
          ];
        }
        else {
          $elements[$delta] = [
            '#markup' => $dcn_identifier,
          ];
        }
      }
    }

    return $elements;
  }

}
