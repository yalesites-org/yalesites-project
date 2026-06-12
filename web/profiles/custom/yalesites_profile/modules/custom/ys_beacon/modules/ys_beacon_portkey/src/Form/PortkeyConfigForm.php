<?php

declare(strict_types=1);

namespace Drupal\ys_beacon_portkey\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure the Portkey AI gateway provider.
 */
final class PortkeyConfigForm extends ConfigFormBase {

  /**
   * Config settings.
   */
  const CONFIG_NAME = 'ys_beacon_portkey.settings';

  /**
   * The AI provider manager.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected $aiProviderManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->aiProviderManager = $container->get('ai.provider');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ys_beacon_portkey_config';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [static::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(static::CONFIG_NAME);

    $form['api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Portkey API Key (chat)'),
      '#description' => $this->t('The key entity that holds the Portkey API key used for chat completions.'),
      '#default_value' => $config->get('api_key'),
      '#required' => TRUE,
    ];

    $form['embeddings_api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Portkey API Key (embeddings)'),
      '#description' => $this->t('The key entity that holds the Portkey API key used for embeddings. Leave empty to use the chat key.'),
      '#default_value' => $config->get('embeddings_api_key'),
      '#empty_option' => $this->t('- Use the chat key -'),
    ];

    $form['host'] = [
      '#type' => 'url',
      '#title' => $this->t('Portkey gateway URL'),
      '#description' => $this->t('The Portkey gateway base URI. Leave the default unless using a self-hosted gateway.'),
      '#default_value' => $config->get('host') ?: 'https://api.portkey.ai/v1',
      '#required' => TRUE,
    ];

    $form['routing'] = [
      '#type' => 'details',
      '#title' => $this->t('Provider routing'),
      '#description' => $this->t('How Portkey routes requests to the upstream LLM provider. Model ids using the model catalog format (for example @example) need no extra routing. Otherwise set a virtual key or a saved config slug.', ['@example' => '@openai-slug/gpt-4o']),
      '#open' => TRUE,
    ];

    $form['routing']['virtual_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Virtual key slug'),
      '#description' => $this->t('Optional. A Portkey virtual key identifying the upstream provider credentials. This is a reference slug, not a secret.'),
      '#default_value' => $config->get('virtual_key'),
    ];

    $form['routing']['config_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Config slug'),
      '#description' => $this->t('Optional. A saved Portkey config id controlling routing, fallbacks, and caching.'),
      '#default_value' => $config->get('config_id'),
    ];

    $form['chat_models'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Chat models'),
      '#description' => $this->t('One model id per line, for example gpt-4o or @example.', ['@example' => '@openai-slug/gpt-4o']),
      '#default_value' => implode("\n", $config->get('chat_models') ?? []),
      '#required' => TRUE,
    ];

    $form['embeddings_models'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Embeddings models'),
      '#description' => $this->t('One model id per line, for example text-embedding-3-small. The model must match the vector dimensions of the search index.'),
      '#default_value' => implode("\n", $config->get('embeddings_models') ?? []),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $chat_models = $this->textareaToList($form_state->getValue('chat_models'));
    $embeddings_models = $this->textareaToList($form_state->getValue('embeddings_models'));

    $this->config(static::CONFIG_NAME)
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('embeddings_api_key', $form_state->getValue('embeddings_api_key'))
      ->set('host', rtrim($form_state->getValue('host'), '/'))
      ->set('virtual_key', $form_state->getValue('virtual_key'))
      ->set('config_id', $form_state->getValue('config_id'))
      ->set('chat_models', $chat_models)
      ->set('embeddings_models', $embeddings_models)
      ->save();

    // Set Portkey as the site default for chat and embeddings when no
    // defaults exist yet.
    if (!empty($chat_models)) {
      $this->aiProviderManager->defaultIfNone('chat', 'portkey', reset($chat_models));
    }
    if (!empty($embeddings_models)) {
      $this->aiProviderManager->defaultIfNone('embeddings', 'portkey', reset($embeddings_models));
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Converts textarea input into a clean list of values.
   *
   * @param string $value
   *   Raw textarea value.
   *
   * @return string[]
   *   One entry per non-empty line.
   */
  private function textareaToList(string $value): array {
    return array_values(array_filter(array_map('trim', explode("\n", $value))));
  }

}
