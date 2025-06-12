<?php

namespace Drupal\ys_icons\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media\MediaInterface;

/**
 * Plugin implementation of the 'ys_icon_component' formatter.
 *
 * @FieldFormatter(
 *   id = "ys_icon_component",
 *   label = @Translation("Icon (YDS Component)"),
 *   description = @Translation("Display the icon using YDS icon component."),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class YsIconComponentFormatter extends EntityReferenceFormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'show_title' => TRUE,
      'additional_classes' => '',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements['show_title'] = [
      '#title' => $this->t('Show title'),
      '#type' => 'checkbox',
      '#default_value' => $this->getSetting('show_title'),
    ];

    $elements['additional_classes'] = [
      '#title' => $this->t('Additional CSS classes'),
      '#type' => 'textfield',
      '#default_value' => $this->getSetting('additional_classes'),
      '#description' => $this->t('Additional CSS classes to add to the icon element.'),
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    if ($this->getSetting('show_title')) {
      $summary[] = $this->t('Show title');
    }
    else {
      $summary[] = $this->t('Hide title');
    }

    if ($additional_classes = $this->getSetting('additional_classes')) {
      $summary[] = $this->t('Additional classes: @classes', ['@classes' => $additional_classes]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $media) {
      if ($media instanceof MediaInterface && $media->bundle() === 'icon') {
        $classes = [];
        if ($additional_classes = $this->getSetting('additional_classes')) {
          $classes = explode(' ', trim($additional_classes));
        }

        $elements[$delta] = [
          '#type' => 'pattern',
          '#id' => 'yds_icon',
          '#fields' => [
            'icon' => $media->get('field_fontawesome_name')->value,
            'title' => $media->get('field_icon_title')->value,
            'description' => $media->get('field_icon_description')->value,
            'classes' => $classes,
          ],
          '#cache' => [
            'tags' => $media->getCacheTags(),
          ],
        ];

        if ($this->getSetting('show_title')) {
          $elements[$delta]['#fields']['show_title'] = TRUE;
        }
      }
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable($field_definition) {
    // Only show this formatter for entity reference fields targeting media entities.
    $target_type = $field_definition->getFieldStorageDefinition()->getSetting('target_type');
    return $target_type === 'media';
  }

}
