<?php

namespace Drupal\ys_localist\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxy;
use Drupal\ys_localist\LocalistManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the manage Localist settings interface.
 */
class LocalistSettings extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The Localist manager.
   *
   * @var \Drupal\ys_localist\LocalistManager
   */
  protected $localistManager;

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
   * @param \Drupal\ys_localist\LocalistManager $localist_manager
   *   The Localist manager.
   * @param \Drupal\Core\Session\AccountProxy $current_user_session
   *   The current user session.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager,
    LocalistManager $localist_manager,
    AccountProxy $current_user_session,
  ) {
    parent::__construct($config_factory);
    $this->entityTypeManager = $entity_type_manager;
    $this->localistManager = $localist_manager;
    $this->currentUserSession = $current_user_session;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('ys_localist.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_localist_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ys_localist.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ys_localist.settings');
    $groupsImported = $this->localistManager->getMigrationStatus('localist_groups') > 0;

    $allowSecretItems = function_exists('ys_core_allow_secret_items') ? ys_core_allow_secret_items($this->currentUserSession) : FALSE;

    if (
      $config->get('enable_localist_sync') &&
      $config->get('localist_group') &&
      $groupsImported
      ) {
      $form['sync_now_button'] = [
        '#type' => 'markup',
        '#markup' => '<a class="button" href="/admin/yalesites/localist/sync">Sync now</a>',
      ];
    }

    $form['enable_localist_sync'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Localist sync'),
      '#description' => $this->t('Once enabled, Localist data will sync events for the selected group roughly every hour.'),
      '#default_value' => $config->get('enable_localist_sync') ?: FALSE,
      '#disabled' => !$allowSecretItems,
    ];

    $form['localist_endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Localist endpoint base URL'),
      '#description' => $this->t('Ex: https://yale.enterprise.localist.com'),
      '#default_value' => $config->get('localist_endpoint') ?: 'https://yale.enterprise.localist.com',
      '#disabled' => !$allowSecretItems,
    ];

    // Only show the group picker if the group migration has been run.
    if ($config->get('enable_localist_sync') && $groupsImported) {
      $term = $config->get('localist_group') ? $this->entityTypeManager->getStorage('taxonomy_term')->load($config->get('localist_group')) : NULL;

      $form['localist_group'] = [
        '#title' => $this->t('Group to sync events'),
        '#type' => 'entity_autocomplete',
        '#target_type' => 'taxonomy_term',
        '#tags' => FALSE,
        '#default_value' => $term ?: NULL,
        '#selection_handler' => 'default',
        '#selection_settings' => [
          'target_bundles' => ['event_groups'],
        ],
        '#required' => TRUE,
        '#disabled' => !$allowSecretItems,
      ];
    }
    elseif ($config->get('enable_localist_sync') && !$groupsImported) {
      $form['no_group_sync_message'] = [
        '#type' => 'markup',
        '#markup' => '
          <p>Groups have not yet created. A selected group is required before synchronizing events.</p>
          <a class="button" href="/admin/yalesites/localist/sync-groups">Create Groups</a>',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $enabled = $form_state->getValue('enable_localist_sync');
    if ($enabled) {
      $requiredFields = [
        'localist_endpoint',
      ];

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

    $this->configFactory->getEditable('ys_localist.settings')
      // Set the submitted configuration setting.
      ->set('enable_localist_sync', $form_state->getValue('enable_localist_sync'))
      ->set('localist_endpoint', rtrim($form_state->getValue('localist_endpoint'), "/"))
      ->set('localist_group', $form_state->getValue('localist_group'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
