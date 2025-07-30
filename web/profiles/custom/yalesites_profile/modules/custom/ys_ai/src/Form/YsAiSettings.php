<?php

namespace Drupal\ys_ai\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\ai_engine_chat\Form\AiEngineChatSettings;

/**
 * Form for configuring the AI chat settings.
 */
class YsAiSettings extends AiEngineChatSettings {

  /**
   * {@inheritdoc}
   *
   * Builds the YaleSites AI settings form by extending the base AI Engine
   * chat settings form with YaleSites-specific configuration options.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $chat_config = $this->config('ai_engine_chat.settings');
    $embedding_config = $this->config('ai_engine_embedding.settings');

    if ($chat_config->get('azure_base_url') != NULL) {
      $form['enable'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable chat widget'),
        '#default_value' => $chat_config->get('enable') ?? FALSE,
        '#description' => $this->t('Enable or disable chat service across the site. Chat can be launched by using the href="#launch-chat" on any link.'),
        '#weight' => -10,
      ];
      $form['floating_button'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable floating chat button'),
        '#default_value' => $chat_config->get('floating_button') ?? FALSE,
        '#weight' => -10,
      ];
      $form['floating_button_text'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Floating button text'),
        '#default_value' => $chat_config->get('floating_button_text') ?? $this->t('Ask Yale Chat'),
        '#required' => TRUE,
        '#weight' => -10,
      ];
    }

    if (
      $embedding_config->get('azure_embedding_service_url') != NULL &&
        $embedding_config->get('azure_search_service_name') != NULL &&
        $embedding_config->get('azure_search_service_index') != NULL
    ) {
      $form['enable_embedding'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable embedding services'),
        '#default_value' => $embedding_config->get('enable') ?? FALSE,
        '#description' => $this->t('Enable automatic updates of vector database.'),
        '#weight' => -11,
      ];
    }

    $form = parent::buildForm($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Handles form submission by saving YaleSites-specific AI configuration
   * to the appropriate AI Engine configuration objects.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('ai_engine_chat.settings')
      ->set('enable', $form_state->getValue('enable'))
      ->set('floating_button', $form_state->getValue('floating_button'))
      ->set('floating_button_text', $form_state->getValue('floating_button_text'))
      ->save();
    $this->configFactory->getEditable('ai_engine_embedding.settings')
      ->set('enable', $form_state->getValue('enable_embedding'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
