<?php

namespace Drupal\ys_servicenow\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxy;
use Drupal\ys_servicenow\ServiceNowManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the manage ServiceNow settings interface.
 */
class ServiceNowSettings extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The ServiceNow manager.
   *
   * @var \Drupal\ys_servicenow\ServiceNowManager
   */
  protected $servicenowManager;

  /**
   * Current user session.
   *
   * @var \Drupal\Core\Session\AccountProxy
   */
  protected $currentUserSession;

  /**
   * Constructs a SiteInformationForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\ys_servicenow\ServiceNowManager $servicenow_manager
   *   The ServiceNow manager.
   * @param \Drupal\Core\Session\AccountProxy $current_user_session
   *   The current user session.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    ServiceNowManager $servicenow_manager,
    AccountProxy $current_user_session,
  ) {
    parent::__construct($config_factory);
    $this->entityTypeManager = $entity_type_manager;
    $this->servicenowManager = $servicenow_manager;
    $this->currentUserSession = $current_user_session;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('ys_servicenow.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_servicenow_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ys_servicenow.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ys_servicenow.settings');

    $allowSecretItems = function_exists('ys_core_allow_secret_items') ? ys_core_allow_secret_items($this->currentUserSession) : FALSE;

    if (
      $config->get('enable_servicenow_sync') &&
      $config->get('servicenow_auth_key')
      ) {
      $form['sync_now_button'] = [
        '#type' => 'markup',
        '#markup' => '<a class="button" href="/admin/yalesites/servicenow/sync">Sync now</a>',
      ];
    }

    $form['enable_servicenow_sync'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable ServiceNow sync'),
      '#description' => $this->t('Once enabled, ServiceNow data will sync knowledge base articles roughly every hour.'),
      '#default_value' => $config->get('enable_servicenow_sync') ?: FALSE,
      '#disabled' => !$allowSecretItems,
    ];

    $form['servicenow_auth_key'] = [
      '#type' => 'key_select',
      '#title' => $this->t('ServiceNow Authentication Credentials'),
      '#default_value' => $config->get('servicenow_auth_key') ?: '',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $enabled = $form_state->getValue('enable_servicenow_sync');
    if ($enabled) {
      $requiredFields = [];

      foreach ($requiredFields as $field) {
        if (!$form_state->getValue($field)) {
          $form_state->setErrorByName(
          $field,
          $this->t("%required_field is required.", ['%required_field' => $form_state->getCompleteForm()[$field]['#title']->__toString()])
          );
        }
      }

    }

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $this->configFactory->getEditable('ys_servicenow.settings')
      // Set the submitted configuration setting.
      ->set('enable_servicenow_sync', $form_state->getValue('enable_servicenow_sync'))
      ->set('servicenow_auth_key', $form_state->getValue('servicenow_auth_key'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
