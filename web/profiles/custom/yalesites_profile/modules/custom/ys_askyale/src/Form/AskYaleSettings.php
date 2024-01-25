<?php

namespace Drupal\ys_askyale\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the manage alerts interface.
 */
class AskYaleSettings extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_askyale_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ys_askyale.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ys_askyale.settings');

    $form['azure_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Azure base URL'),
      '#description' => $this->t('Ex: https://askyalewebapp.azurewebsites.net'),
      '#default_value' => $config->get('azure_base_url') ?? NULL,
    ];

    $form['initial_questions'] = [
      '#type' => 'multivalue',
      '#title' => $this->t('Initial question prompts'),
      '#cardinality' => 4,
      '#default_value' => ($config->get('initial_questions')) ?? [],
      '#description' => $this->t('A list of prompts to show when the chat is empty'),

      'question' => [
        '#type' => 'textfield',
        '#title' => $this->t('Question'),
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $this->configFactory->getEditable('ys_askyale.settings')
      // Set the submitted configuration setting.
      ->set('azure_base_url', $form_state->getValue('azure_base_url'))
      ->set('initial_questions', $form_state->getValue('initial_questions'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
