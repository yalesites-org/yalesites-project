<?php

namespace Drupal\ys_embed\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'embed_debug' widget.
 *
 * @FieldWidget(
 *   id = "embed_debug",
 *   label = @Translation("Embed Debug Widget"),
 *   field_types = {"embed"}
 * )
 */
class EmbedDebugWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    return [
      $this->t('The debug widget shows all hidden field values.')
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {

    $element['embed_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Embed Code'),
      '#default_value' => '',
      '#description' => 'https://yalesurvey.ca1.qualtrics.com/jfe/form/SV_cDezt2JVsNok77o',
      '#size' => 80,
      '#maxlength' => 1024,
      '#required' => !empty($element['#required']),
      '#element_validate' => ['qualtrics'],
    ];

    $element['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => '',//isset($settings['url']) ? $settings['url'] : '',
      '#description' => 'The title of the Qualtrics form, used for accessibility markup.',
      '#size' => 80,
      '#maxlength' => 1024,
      '#element_validate' => ['text'],
    ];

    $element['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#default_value' => '',//$settings['title'],
      '#size' => 80,
      '#maxlength' => 1024,
      '#element_validate' => ['text'],
    ];

    $element['class'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Additional CSS Classes'),
      '#default_value' => '',
      '#size' => 80,
      '#maxlength' => 1024,
      '#element_validate' => ['text'],
    ];

    $element['width'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Width'),
      '#default_value' => '',
      '#size' => 24,
      '#maxlength' => 1024,
      // '#element_validate' => [[$this, 'validateWidth']],
    ];

    $element['height'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Height'),
      '#default_value' => '',
      '#size' => 24,
      '#maxlength' => 1024,
      // '#element_validate' => [[$this, 'validateHeight']],
    ];

    return $element;
  }

}
