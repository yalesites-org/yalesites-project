<?php

namespace Drupal\ys_ai\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 *
 */
class YsAiSettings extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_ai_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = \Drupal::formBuilder()->getForm('Drupal\ai_engine_chat\Form\AiEngineChatSettingsForm');

    return $form;
  }

}
