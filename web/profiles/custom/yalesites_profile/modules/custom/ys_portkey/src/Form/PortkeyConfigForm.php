<?php

namespace Drupal\ys_portkey\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Service\AiProviderFormHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Portkey AI Provider models.
 */
class PortkeyConfigForm extends ConfigFormBase {

  /**
   * Config settings.
   */
  const CONFIG_NAME = 'ys_portkey.settings';

  /**
   * The AI Provider service.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected AiProviderPluginManager $aiProviderManager;

  /**
   * The form helper.
   *
   * @var \Drupal\ai\Service\AiProviderFormHelper
   */
  protected AiProviderFormHelper $formHelper;

  /**
   * Constructs a new PortkeyConfigForm object.
   */
  final public function __construct(
    AiProviderPluginManager $ai_provider_manager,
    AiProviderFormHelper $form_helper,
  ) {
    $this->aiProviderManager = $ai_provider_manager;
    $this->formHelper = $form_helper;
  }

  /**
   * {@inheritdoc}
   */
  final public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai.provider'),
      $container->get('ai.form_helper'),
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
    $provider = $this->aiProviderManager->createInstance('portkey');
    $form['models'] = $this->formHelper->getModelsTable($form, $form_state, $provider);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Model create/edit is handled by the framework's AiModelSettingsForm.
  }

}
