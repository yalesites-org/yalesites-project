<?php

namespace Drupal\ys_ai_system_instructions\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure system instructions settings.
 */
class SystemInstructionsSettingsForm extends ConfigFormBase {

  /**
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * Constructs a SystemInstructionsSettingsForm object.
   *
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository service.
   */
  public function __construct(KeyRepositoryInterface $key_repository) {
    $this->keyRepository = $key_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('key.repository')
    );
  }

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

    // Get available keys for the API key selection.
    $keys = $this->keyRepository->getKeys();
    $key_options = [];
    foreach ($keys as $key_id => $key) {
      $key_options[$key_id] = $key->label();
    }

    $form['system_instructions_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable System Instruction Modification'),
      '#description' => $this->t('Allow users to modify system instructions via the API. When disabled, the system instructions management interface will be hidden.'),
      '#default_value' => $config->get('system_instructions_enabled') ?? FALSE,
    ];

    $form['api_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('API Configuration'),
      '#description' => $this->t('Configure the external API for managing system instructions.'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="system_instructions_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['api_settings']['system_instructions_api_endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('API Endpoint'),
      '#description' => $this->t('The URL endpoint for the system instructions API.'),
      '#default_value' => $config->get('system_instructions_api_endpoint'),
      '#states' => [
        'required' => [
          ':input[name="system_instructions_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['api_settings']['system_instructions_web_app_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Web App Name'),
      '#description' => $this->t('The web app name/index used in API calls.'),
      '#default_value' => $config->get('system_instructions_web_app_name') ?? '',
      '#states' => [
        'required' => [
          ':input[name="system_instructions_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['api_settings']['system_instructions_api_key'] = [
      '#type' => 'select',
      '#title' => $this->t('API Key'),
      '#description' => $this->t('Select the key to use for API authentication. Keys are managed in the Key module.'),
      '#options' => $key_options,
      '#default_value' => $config->get('system_instructions_api_key') ?? '',
      '#empty_option' => $this->t('- Select a key -'),
      '#states' => [
        'required' => [
          ':input[name="system_instructions_enabled"]' => ['checked' => TRUE],
        ],
      ],
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

    // If system instructions are enabled, validate required API fields.
    if ($form_state->getValue('system_instructions_enabled')) {
      if (empty($form_state->getValue('system_instructions_api_endpoint'))) {
        $form_state->setErrorByName('system_instructions_api_endpoint',
          $this->t('API endpoint is required when system instructions are enabled.'));
      }

      if (empty($form_state->getValue('system_instructions_web_app_name'))) {
        $form_state->setErrorByName('system_instructions_web_app_name',
          $this->t('Web app name is required when system instructions are enabled.'));
      }

      if (empty($form_state->getValue('system_instructions_api_key'))) {
        $form_state->setErrorByName('system_instructions_api_key',
          $this->t('API key is required when system instructions are enabled.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('ys_ai_system_instructions.settings')
      ->set('system_instructions_enabled', $form_state->getValue('system_instructions_enabled'))
      ->set('system_instructions_api_endpoint', $form_state->getValue('system_instructions_api_endpoint'))
      ->set('system_instructions_web_app_name', $form_state->getValue('system_instructions_web_app_name'))
      ->set('system_instructions_api_key', $form_state->getValue('system_instructions_api_key'))
      ->set('system_instructions_max_length', $form_state->getValue('system_instructions_max_length'))
      ->set('system_instructions_warning_threshold', $form_state->getValue('system_instructions_warning_threshold'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
