<?php

namespace Drupal\ys_ai_system_instructions\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure system instructions settings.
 */
class SystemInstructionsSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ys_ai_system_instructions.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_ai_system_instructions_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ys_ai_system_instructions.settings');

    $form['system_instructions_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable System Instruction Modification'),
      '#description' => $this->t("Allow site admins to edit the chatbot assistant's system instructions. When disabled, the system instructions management interface is hidden."),
      '#default_value' => $config->get('system_instructions_enabled') ?? FALSE,
    ];

    $form['length_controls'] = [
      '#type' => 'details',
      '#title' => $this->t('Content Length Controls'),
      '#description' => $this->t('Configure limits and warnings for system instruction length.'),
      '#open' => TRUE,
    ];

    $form['length_controls']['system_instructions_max_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Instructions Length'),
      '#description' => $this->t('The recommended maximum length for system instructions in characters. This is a soft limit - users will see a warning but can still save longer content.'),
      '#default_value' => $config->get('system_instructions_max_length') ?? 4000,
      '#min' => 100,
      '#max' => 50000,
      '#step' => 100,
      '#required' => TRUE,
    ];

    $form['length_controls']['system_instructions_warning_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Warning Threshold'),
      '#description' => $this->t('Show a warning when instructions approach this length. Should be less than the maximum length.'),
      '#default_value' => $config->get('system_instructions_warning_threshold') ?? 3500,
      '#min' => 100,
      '#max' => 50000,
      '#step' => 100,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $max_length = $form_state->getValue('system_instructions_max_length');
    $warning_threshold = $form_state->getValue('system_instructions_warning_threshold');

    if ($warning_threshold >= $max_length) {
      $form_state->setErrorByName('system_instructions_warning_threshold',
        $this->t('Warning threshold must be less than maximum length.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('ys_ai_system_instructions.settings')
      ->set('system_instructions_enabled', $form_state->getValue('system_instructions_enabled'))
      ->set('system_instructions_max_length', $form_state->getValue('system_instructions_max_length'))
      ->set('system_instructions_warning_threshold', $form_state->getValue('system_instructions_warning_threshold'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
