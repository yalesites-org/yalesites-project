<?php

namespace Drupal\ys_embed\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'embed_default' widget.
 *
 * @FieldWidget(
 *   id = "embed_default",
 *   label = @Translation("Embed Default Widget"),
 *   field_types = {"embed"}
 * )
 */
class EmbedDefaultWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    return [
      $this->t('No settings.')
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {

    $element['embed_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Embed Code'),
      '#default_value' => $items[$delta]->embed_code ?? NULL,
      '#description' => 'https://yalesurvey.ca1.qualtrics.com/jfe/form/SV_cDezt2JVsNok77o',
      '#size' => 80,
      '#maxlength' => 1024,
      '#required' => !empty($element['#required']),
      '#element_validate' => ['qualtrics'],
      '#ajax' => [
        'callback' => [$this, 'myAjaxCallback'],
        'disable-refocus' => FALSE,
        'event' => 'input',
        'wrapper' => 'edit-settings',
      ],
    ];

    $form['settings'] = [
      '#type' => 'container',
      '#prefix' => '<div id="edit-settings">',
      '#suffix' => '</div>',
    ];

    $form['settings']['provider'] = [
      '#type' => 'hidden',
      '#title' => $this->t('Provider'),
      '#default_value' => $items[$delta]->provider ?? NULL,
      '#size' => 80,
      '#maxlength' => 1024,
    ];

    $form['settings']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $items[$delta]->title ?? NULL,
      '#description' => 'The title attribute for the embedded content, used for accessibility markup.',
      '#size' => 80,
      '#maxlength' => 1024,
      '#element_validate' => ['text'],
      '#required' => !empty($element['#required']),
      '#access' => $this->isQualtics($items[$delta]->embed_code),
    ];

    return $element;
  }

  public function myAjaxCallback(array &$form, FormStateInterface $form_state) {
    $code = $form_state->getTriggeringElement()['#value'];
    $form['settings']['title']['#access'] = $this->isQualtics($code);
    $form['settings']['title']['#value'] = $form_state->getUserInput()['title'];
    $form['settings']['provider']['#value'] = $this->isQualtics($code) ? 'qualtrics' : NULL;
    return $form['settings'];
  }

  public function isQualtics($embedCode) {
    $pattern = '/^https:\/\/yalesurvey.(\S+).qualtrics.com\/(\S+)/';
    return !empty(preg_match($pattern, $embedCode, $matches));
  }

  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      $value['title'] = $form_state->getValue('title');
      $value['provider'] = $form_state->getValue('provider');
    }
    return $values;
  }

}
