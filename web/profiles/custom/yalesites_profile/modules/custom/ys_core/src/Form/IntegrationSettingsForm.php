<?php

namespace Drupal\ys_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ys_core\IntegrationPluginManager;

/**
 * Form for managing integrations for site admins+.
 *
 * @package Drupal\ys_core\Form
 */
class IntegrationSettingsForm extends ConfigFormBase {

  /**
   * The integration plugin manager.
   *
   * @var \Drupal\ys_core\IntegrationPluginManager
   */
  protected $integrationPluginManager;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_core_integration_settings_form';
  }

  public function __construct(IntegrationPluginManager $integrationPluginManager) {
    $this->integrationPluginManager = $integrationPluginManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ys_core.integration_plugin_manager')
    );
  }

  /**
   * Settings configuration form.
   *
   * @param array $form
   *   Form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state.
   *
   * @return array
   *   Form array to render.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $integrationsConfig = $this->config('ys_core.integration_settings');

    $form['integrations'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Integrations'),
    ];

    $integrations = $this->integrationPluginManager->getDefinitions();
    foreach ($integrations as $id => $integration) {
      $form['integrations'][$id] = [
        '#type' => 'checkbox',
        '#title' => $integration['label'],
        '#default_value' => $integrationsConfig->get($id),
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $integrationConfig = $this->config('ys_core.integration_settings');

    $integrations = $this->integrationPluginManager->getDefinitions();
    foreach ($integrations as $id => $integration) {
      $integrationConfig->set($id, $form_state->getValue($id));
    }

    $integrationConfig->save();

    return parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'ys_core.integration_settings',
    ];
  }

}
