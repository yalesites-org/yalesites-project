<?php

namespace Drupal\ys_campus_groups\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateMessage;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrationPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Runs Campus Groups migrations on request.
 */
class RunMigrations extends ControllerBase implements ContainerInjectionInterface {

  const MIGRATION_NAME = 'campus_groups_events';

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $campusGroupsConfig;

  /**
   * Drupal messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Drupal migration manager.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManager
   */
  protected $migrationManager;

  /**
   * Drupal time interface.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Drupal module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * Constructs a new RunMigrations object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    MigrationPluginManager $migration_manager,
    MessengerInterface $messenger,
    TimeInterface $time,
    ModuleHandler $module_handler,
  ) {
    $this->campusGroupsConfig = $config_factory->get('ys_campus_groups.settings');
    $this->migrationManager = $migration_manager;
    $this->messenger = $messenger;
    $this->time = $time;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('plugin.manager.migration'),
      $container->get('messenger'),
      $container->get('datetime.time'),
      $container->get('module_handler'),
    );
  }

  /**
   * Runs all Localist migrations.
   */
  public function runAllMigrations() {
    $migration = self::MIGRATION_NAME;
    $messageData = [];

    if ($this->campusGroupsConfig->get('enable_campus_groups_sync')) {
      $this->runMigration($migration);
      $imported = $this->getMigrationStatus($migration);

      $message = "Synchronized $imported events.";
      $this->messenger()->addStatus($message);
    }
    else {
      $this->messenger()->addError('Campus Groups syncing is not enabled.');
    }

    $redirectUrl = Url::fromRoute('ys_campus_groups.settings')->toString();
    $response = new RedirectResponse($redirectUrl);
    return $response;
  }

  /**
   * Runs specific migration.
   *
   * @param string $migration
   *   The migration ID.
   *
   * @return void
   *   Runs the migration.
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

}
