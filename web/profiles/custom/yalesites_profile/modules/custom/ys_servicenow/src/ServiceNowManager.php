<?php

namespace Drupal\ys_servicenow;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateMessage;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrationPluginManager;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service for ServiceNow functions.
 */
class ServiceNowManager extends ControllerBase implements ContainerInjectionInterface {

  const SERVICENOW_MIGRATIONS = [
    'servicenow_knowledge_base_article_block',
    'servicenow_knowledge_base_articles',
  ];

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $servicenowConfig;

  /**
   * ServiceNow endpoint.
   *
   * @var string
   */
  protected $servicenowEndpoint;

  /**
   * The Http client service.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
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
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Drupal time interface.
   *
   * @var \Drupal\Core\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Drupal messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new ServiceNowManager object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    Client $http_client,
    EntityTypeManagerInterface $entity_type_manager,
    MigrationPluginManager $migration_manager,
    ModuleHandlerInterface $module_handler,
    TimeInterface $time,
    MessengerInterface $messenger,
  ) {
    $this->servicenowConfig = $config_factory->get('ys_servicenow.settings');
    $this->httpClient = $http_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->migrationManager = $migration_manager;
    $this->moduleHandler = $module_handler;
    $this->time = $time;
    $this->messenger = $messenger;
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
      $container->get('date.formatter'),
      $container->get('messenger'),
    );

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
    $migrationInstance = $this->migrationManager->createInstance($migration);
    if ($migrationInstance) {
      // Check if the migration status is IDLE, if not, make it so.
      $status = $migrationInstance->getStatus();
      if ($status !== MigrationInterface::STATUS_IDLE) {
        $migrationInstance->setStatus(MigrationInterface::STATUS_IDLE);
      }

      /*
       * @todo Possibly implement the following flags, if needed.
       * Runs migration with the --update flag.
       * $migration_update = $migration->getIdMap();
       * $migration_update->prepareUpdate();
       * Runs migration with the --sync flag.
       * The problem here is if editor adds layout builder, this will wipe those
       * changes out before recreating. So, this not be a good idea.
       * $migrationInstance->set('syncSource', TRUE);
       */

      $migrationInstance->set('syncSource', TRUE);
      $message = new MigrateMessage();
      $executable = new MigrateExecutable($migrationInstance, $message);
      $executable->import();

      /* If using migrate_plus module, update the migrate_last_imported value
       * for the migration.
       */

      if ($this->moduleHandler->moduleExists('migrate_plus')) {
        $migrate_last_imported_store = $this->keyValue('migrate_last_imported');
        $migrate_last_imported_store->set($migrationInstance->id(), round($this->time->getCurrentMicroTime() * 1000));
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
   * Runs all Localist migrations.
   *
   * @return array
   *   Array of status of all migrations run.
   */
  public function runAllMigrations() {
    foreach (self::SERVICENOW_MIGRATIONS as $migration) {
      $this->runMigration($migration);
      $messageData[$migration] = [
        'imported' => $this->getMigrationStatus($migration),
      ];
    }
    return $messageData;
  }

}
