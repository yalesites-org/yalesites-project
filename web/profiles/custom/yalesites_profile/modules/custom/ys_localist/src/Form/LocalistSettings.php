<?php

namespace Drupal\ys_localist\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Session\AccountProxy;
use Drupal\localist_drupal\Service\LocalistManager;
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
   * @var \Drupal\localist_drupal\LocalistManager
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
      $container->get('localist_drupal.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'localist_drupal_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['localist_drupal.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('localist_drupal.settings');
    $localistEnabled = $config->get('enable_localist_sync');
    $endpointValid = $groupMigrationExists = $groupTaxonomyStatus = $groupsImported = $localistGroup = $examplesCreated = FALSE;

    $form['enable_localist_sync'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Localist sync'),
      '#description' => $this->t('Once enabled, Localist data will sync events for the selected group roughly every hour.'),
      '#default_value' => $config->get('enable_localist_sync') ?: FALSE,
    ];

    if ($this->localistManager->preflightChecks()) {
      $syncUrl = Url::fromRoute('localist_drupal.run_migrations')->toString();
      $form['sync_now_button'] = [
        '#type' => 'markup',
        '#markup' => "<a class='button' href='$syncUrl'>Sync now</a>",
      ];
    }

    if ($localistEnabled) {
      $endpointValid = $this->localistManager->checkEndpoint();
      $groupMigrationExists = $this->localistManager->getMigrationStatus($config->get('localist_group_migration'));
      $groupTaxonomyStatus = $this->localistManager->checkGroupTaxonomy();
      $groupsImported = !empty($groupMigrationExists) ? $this->localistManager->getMigrationStatus($config->get('localist_group_migration'))['imported'] > 0 : FALSE;
      $localistGroup = $this->localistManager->getGroupTaxonomyEntity();
      $examplesCreated = $this->localistManager->examplesCreated();

      $statusArea = [
        '#theme' => 'localist_status',
        '#endpoint_status' => $endpointValid,
        '#group_migration_status' => $groupMigrationExists,
        '#group_taxonomy_status' => $groupTaxonomyStatus,
        '#group_selected' => $localistGroup,
        '#svg_check' => $this->localistManager->getIcon('circle-check.svg'),
        '#svg_xmark' => $this->localistManager->getIcon('circle-xmark.svg'),
      ];

      $renderedStatus = \Drupal::service('renderer')->render($statusArea);

      $form['status'] = [
        '#type' => 'item',
        '#markup' => $renderedStatus,
      ];
    }

    if ($this->localistManager->preflightChecks()) {

      $form['example_area_container'] = [
        '#type' => 'details',
        '#title' => $this->t('Example Migration'),
        '#collapsed' => TRUE,
      ];

      $exampleArea = [
        '#theme' => 'localist_example',
        '#create_example_url' => Url::fromRoute('localist_drupal.create_example')->toString(),
        '#examples_created' => $examplesCreated,
      ];

      $renderedExample = \Drupal::service('renderer')->render($exampleArea);

      $form['example_area_container']['example'] = [
        '#type' => 'item',
        '#markup' => $renderedExample,
      ];

    }

    $form['localist_endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Localist endpoint base URL'),
      '#description' => $this->t('E.g. https://calendar.example.edu'),
      '#allowed_tags' => ['span', 'svg', 'path'],
      '#default_value' => $config->get('localist_endpoint') ?: 'https://calendar.example.edu',
      '#required' => TRUE,
    ];

    $form['groups'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Localist Group'),
      '#description' => $this->t('This module only imports Localist events from a specific group.'),
      '#disabled' => !$localistEnabled,
    ];

    $form['groups']['localist_group_migration'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Group Migration'),
      '#description' => $this->t('Machine name, e.g. localist_groups. See README.md on how to override with a custom group migration.'),
      '#default_value' => $config->get('localist_group_migration') ?: 'localist_groups',
      '#required' => TRUE,
    ];

    // Only show the group picker if the group migration has been run.
    if ($groupsImported && $endpointValid) {
      $term = NULL;
      if ($localistGroup) {
        $term = $config->get('localist_group') ? $this->entityTypeManager->getStorage('taxonomy_term')->load($config->get('localist_group')) : NULL;
      }

      $form['groups']['localist_group'] = [
        '#title' => $this->t('Group to Sync Events'),
        '#type' => 'entity_autocomplete',
        '#target_type' => 'taxonomy_term',
        '#tags' => FALSE,
        '#default_value' => $term ?: NULL,
        '#selection_handler' => 'default',
        '#selection_settings' => [
          'target_bundles' => [$this->localistManager::GROUP_VOCABULARY],
        ],
        '#required' => TRUE,
      ];
    }
    elseif ($localistEnabled && $endpointValid && !$groupsImported && !empty($groupMigrationExists)) {
      $syncGroupsUrl = Url::fromRoute('localist_drupal.sync_groups')->toString();
      $form['groups']['no_group_sync_message'] = [
        '#type' => 'markup',
        '#markup' => "<p>" . $this->t('Groups have not yet created. A selected group is required before synchronizing events.') . "</p>" .
        "<a class='button' href='$syncGroupsUrl'>" . $this->t('Create Groups') . "</a>",
      ];
    }

    $dependencyMigrations = $config->get('localist_dependency_migrations') ? implode("\n", $config->get('localist_dependency_migrations')) : NULL;

    $form['localist_dependency_migrations'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Dependency Migrations'),
      '#default_value' => $dependencyMigrations,
      '#description' => $this->t("Specify dependency migrations to run by machine name. Enter one migration per line. E.g.: localist_places. See README.md on how to create additional migrations."),
      '#disabled' => !$localistEnabled,
    ];

    $form['localist_event_migration'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Event Migration'),
      '#description' => $this->t('Machine name, e.g. localist_events. This is the main migration of events and comes after the dependency migrations.'),
      '#default_value' => $config->get('localist_event_migration') ?: NULL,
      '#disabled' => !$localistEnabled,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable('localist_drupal.settings');

    // Set the submitted configuration setting.
    $config->set('enable_localist_sync', $form_state->getValue('enable_localist_sync'))
      ->set('localist_endpoint', rtrim($form_state->getValue('localist_endpoint'), "/"))
      ->set('localist_group', $form_state->getValue('localist_group'))
      ->set('localist_group_migration', $form_state->getValue('localist_group_migration'))
      ->set('localist_dependency_migrations', array_map('trim', explode("\n", $form_state->getValue('localist_dependency_migrations'))))
      ->set('localist_event_migration', $form_state->getValue('localist_event_migration'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
