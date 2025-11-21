<?php

namespace Drupal\ys_ai\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ai_engine_chat\Form\AiEngineChatSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for configuring the AI chat settings.
 */
class YsAiSettings extends AiEngineChatSettings {

  /**
   * The system instructions access check service.
   *
   * @var \Drupal\ys_ai_system_instructions\Access\SystemInstructionsAccessCheck
   */
  protected $systemInstructionsAccess;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->systemInstructionsAccess = $container->get('ys_ai_system_instructions.access_check');
    return $instance;
  }

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

    if (!empty($chat_config->get('azure_base_url'))) {
      $form['enable'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable chat widget'),
        '#default_value' => $chat_config->get('enable') ?? FALSE,
        '#description' => $this->t('Enable or disable chat service across the site. Chat can be launched by using the href="#launch-chat" on any link.'),
        '#weight' => -10,
      ];
    }

    if (
      !empty($embedding_config->get('azure_embedding_service_url')) &&
        !empty($embedding_config->get('azure_search_service_name')) &&
        !empty($embedding_config->get('azure_search_service_index'))
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

    // Add system instructions link if user has access.
    if ($this->systemInstructionsAccess->access($this->currentUser())->isAllowed()) {
      $form['system_instructions_link'] = [
        '#type' => 'item',
        '#title' => $this->t('System Instructions Management'),
        '#description' => $this->t("Configure the AI assistant's behavior and responses."),
        '#markup' => $this->t('<a href="@url">Manage System Instructions</a>', [
          '@url' => Url::fromRoute('ys_ai_system_instructions.form')->toString(),
        ]),
        '#weight' => 100,
      ];
    }

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
      ->save();
    $this->configFactory->getEditable('ai_engine_embedding.settings')
      ->set('enable', $form_state->getValue('enable_embedding'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
