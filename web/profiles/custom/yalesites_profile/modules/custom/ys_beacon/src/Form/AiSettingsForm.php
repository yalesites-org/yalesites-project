<?php

namespace Drupal\ys_beacon\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\ai_engine_chat\Form\AiEngineChatSettings;
use Drupal\ai_engine_chat\Form\AiEngineChatAdmin;

/**
 * Form for AI Settings.
 *
 * @package Drupal\ys_ai_engine\Form
 */
class AiSettingsForm extends AiEngineChatSettings {
  /**
   * {@inheritdoc}
   */
  const CONFIG_NAME = 'ai_engine_chat.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ys_beacon_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG_NAME);

    $adminConfig = $this->config(AiEngineChatAdmin::CONFIG_NAME);

    $form['enable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable chat widget'),
      '#default_value' => $adminConfig->get('enable') ?? FALSE,
      '#description' => $this->t('Enable or disable chat service across the site. Chat can be launched by using the href="#launch-chat" on any link.'),
    ];
    $form['system_instructions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System Instructions'),
      '#description' => $this->t('Instructions that will be used by the AI bot when interpreting questions.'),
      '#default_value' => $config->get('system_instructions') ?? NULL,
      '#rows' => 10,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::CONFIG_NAME)
      ->set('system_instructions', $form_state->getValue('system_instructions'))
      ->save();

    $adminConfig = $this->config(AiEngineChatAdmin::CONFIG_NAME);
    $adminConfig
      ->set('enable', $form_state->getValue('enable'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
