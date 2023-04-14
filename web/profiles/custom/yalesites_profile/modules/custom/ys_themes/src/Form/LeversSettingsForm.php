<?php

namespace Drupal\ys_themes\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class LeversSettingsForm extends FormBase {

  public function getFormId() {
    return 'levers_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $dir = NULL, $img = NULL) {

    $form['test_input'] = [
      '#type' => 'textfield',
      '#title' => t('Test Input field'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => t('Save form'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

  }
}
