<?php

namespace Drupal\ys_portkey\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai\AiProviderPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Portkey API access.
 */
class PortkeyConfigForm extends ConfigFormBase {

  /**
   * Config settings.
   */
  const CONFIG_NAME = 'ys_portkey.settings';

  /**
   * Default provider ID.
   */
  const PROVIDER_ID = 'portkey';

  /**
   * Constructs a new PortkeyConfigForm object.
   */
  final public function __construct(
    private readonly AiProviderPluginManager $aiProviderManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  final public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai.provider'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_portkey_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      static::CONFIG_NAME,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::CONFIG_NAME);

    $form['api_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('Portkey API Key'),
      '#description' => $this->t('Select a Key entity containing your Portkey API key. This is sent as the x-portkey-api-key header.'),
      '#default_value' => $config->get('api_key'),
      '#required' => TRUE,
    ];

    $form['gateway_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Gateway URL'),
      '#description' => $this->t('The Portkey gateway endpoint. Change only for self-hosted Portkey deployments.'),
      '#default_value' => $config->get('gateway_url'),
    ];

    $form['model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model Name'),
      '#description' => $this->t('Model ID passed in API requests and shown in the model dropdown. Portkey handles actual model routing. E.g., claude-sonnet-4-20250514'),
      '#default_value' => $config->get('model'),
      '#required' => TRUE,
    ];

    $form['custom_headers'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Custom Headers'),
      '#description' => $this->t('Additional HTTP headers sent with every API request. One per line in "Header-Name: value" format. Use [key:key_name] to reference a Key module key. E.g., x-portkey-config: my-config-id'),
      '#default_value' => $config->get('custom_headers'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config(static::CONFIG_NAME)
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('gateway_url', $form_state->getValue('gateway_url'))
      ->set('model', $form_state->getValue('model'))
      ->set('custom_headers', $form_state->getValue('custom_headers'))
      ->save();

    $this->setDefaultModels();
    parent::submitForm($form, $form_state);
  }

  /**
   * Set default models for the AI provider.
   */
  private function setDefaultModels() {
    $provider = $this->aiProviderManager->createInstance(static::PROVIDER_ID);
    if (is_callable([$provider, 'getSetupData'])) {
      $setup_data = $provider->getSetupData();
      if (!empty($setup_data) && is_array($setup_data) && !empty($setup_data['default_models']) && is_array($setup_data['default_models'])) {
        foreach ($setup_data['default_models'] as $op_type => $model_id) {
          $this->aiProviderManager->defaultIfNone($op_type, static::PROVIDER_ID, $model_id);
        }
      }
    }
  }

}
