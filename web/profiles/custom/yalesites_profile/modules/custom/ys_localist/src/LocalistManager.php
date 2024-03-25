<?php

namespace Drupal\ys_localist;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateMessage;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrationPluginManager;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service for Localist functions.
 */
class LocalistManager extends ControllerBase implements ContainerInjectionInterface {

  /**
   * List of migrations to run. Place migrations from first to last.
   */
  const LOCALIST_MIGRATIONS = [
    'localist_event_types',
    'localist_experiences',
    'localist_groups',
    'localist_places',
    'localist_status',
    'localist_events',
  ];

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $localistConfig;

  /**
   * Localist endpoint base.
   *
   * @var string
   */
  protected $endpointBase;

  /**
   * The Http client service.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Drupal migration manager.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManager
   */
  protected $migrationManager;

  /**
   * Drupal module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * Drupal time interface.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Constructs a new LocalistManager object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    Client $http_client,
    EntityTypeManager $entity_type_manager,
    MigrationPluginManager $migration_manager,
    ModuleHandler $module_handler,
    TimeInterface $time,
    ) {
    $this->localistConfig = $config_factory->get('ys_localist.settings');
    $this->endpointBase = $this->localistConfig->get('localist_endpoint');
    $this->httpClient = $http_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->migrationManager = $migration_manager;
    $this->moduleHandler = $module_handler;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('http_client'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.migration'),
      $container->get('module_handler'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Gets the endpoint URLs for migration.
   *
   * @param string $endpointType
   *   The endpoint to fetch.
   *
   * @return array
   *   An array of endpoint URLs with dynamic parameters.
   */
  public function getEndpointUrls($endpointType) {
    $endpointsWithParams = [];

    switch ($endpointType) {
      case 'events':
        // Group ID is required.
        $groupId = $this->getGroupTaxonomyEntity();

        if (!$groupId) {
          $endpointsWithParams[] = '';
        }
        else {
          $eventsURL = "$this->endpointBase/api/2/events";

          // Gets the latest version from the API by changing the URL each time.
          $version = time();

          // Localist supports only getting 365 days from today.
          $endDate = date('Y-m-d', strtotime("+364 days"));

          $endpointsWithParams[] = "$eventsURL?end=$endDate&group_id=$groupId&v=$version&pp=100";
        }

        break;

      case 'places':
        $placesURL = "$this->endpointBase/api/2/places?pp=100";
        $endpointsWithParams = $this->getMultiPageUrls($placesURL);

        break;

      case 'filters':
        $endpointsWithParams[] = "$this->endpointBase/api/2/events/filters";

        break;

      case 'groups':
        $groupsURL = "$this->endpointBase/api/2/groups?pp=100";
        $endpointsWithParams = $this->getMultiPageUrls($groupsURL);

        break;

      default:
        $endpointsWithParams = [];
        break;
    }
    return $endpointsWithParams;
  }

  /**
   * Gets the total number of pages from a Localist API endpoint.
   *
   * @param string $url
   *   The endpoint to fetch.
   *
   * @return array
   *   Endpoint URLs with pages attached.
   */
  private function getMultiPageUrls($url) {
    $endpointUrls = [];
    $response = $this->httpClient->get($url);
    $data = json_decode($response->getBody(), TRUE);

    $i = 1;
    while ($i <= $data['page']['total']) {
      $endpointUrls[] = "$url&page=$i";
      $i++;
    }

    return $endpointUrls;
  }

  /**
   * Gets an entity object for the selected group taxonomy term.
   *
   * @return array
   *   Endpoint URLs with pages attached.
   */
  private function getGroupTaxonomyEntity() {
    $groupId = NULL;
    $groupTermId = $this->localistConfig->get('localist_group');
    if ($groupTermId) {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($groupTermId);
      $groupId = ($term) ? $term->field_localist_group_id->value : NULL;
    }

    return $groupId;
  }

  /**
   * Runs all Localist migrations.
   *
   * @return array
   *   Array of status of all migrations run.
   */
  public function runAllMigrations() {
    foreach (self::LOCALIST_MIGRATIONS as $migration) {
      $this->runMigration($migration);
      $messageData[$migration] = [
        'imported' => $this->getMigrationStatus($migration),
      ];
    }
    return $messageData;
  }

  /**
   * Runs specific migration.
   *
   * @param string $migration
   *   The migration ID.
   */
  public function runMigration($migration) {
    // Loop over the list of the migrations and check if they require
    // execution.
    // Prevent non-existent migrations from breaking cron.
    $migration = $this->migrationManager->createInstance($migration);
    if ($migration) {
      // Check if the migration status is IDLE, if not, make it so.
      $status = $migration->getStatus();
      if ($status !== MigrationInterface::STATUS_IDLE) {
        $migration->setStatus(MigrationInterface::STATUS_IDLE);
      }

      /*
       * @todo Possibly implement the following flags, if needed.
       * Runs migration with the --update flag.
       * $migration_update = $migration->getIdMap();
       * $migration_update->prepareUpdate();
       * Runs migration with the --sync flag.
       * $migration->set('syncSource', TRUE);
       */

      $message = new MigrateMessage();
      $executable = new MigrateExecutable($migration, $message);
      $executable->import();

      /* If using migrate_plus module, update the migrate_last_imported value
       * for the migration.
       */

      if ($this->moduleHandler->moduleExists('migrate_plus')) {
        $migrate_last_imported_store = $this->keyValue('migrate_last_imported');
        $migrate_last_imported_store->set($migration->id(), round($this->time->getCurrentMicroTime() * 1000));
      }
    }
  }

  /**
   * Gets the migration status such as number of items imported.
   *
   * @return int
   *   For now, just the number of items imported.
   */
  public function getMigrationStatus($migration_id) {
    $migration = $this->migrationManager->createInstance($migration_id);
    $map = $migration->getIdMap();
    $imported = $map->importedCount();
    return $imported;
  }

  /**
   * Imports groups, if specific criteria are met.
   */
  public function generateGroups() {
    if (
      $this->localistConfig->get('enable_localist_sync') &&
      $this->localistConfig->get('localist_endpoint') &&
      $this->getMigrationStatus('localist_groups') == 0
      ) {
      $this->runMigration('localist_groups');
      if ($this->getMigrationStatus('localist_groups') == 0) {
        $this->messenger()->addError($this->t("Error getting groups."));
      }
      else {
        $this->messenger()->addStatus($this->t("Localist groups successfully imported."));
      }
    }
  }

}
