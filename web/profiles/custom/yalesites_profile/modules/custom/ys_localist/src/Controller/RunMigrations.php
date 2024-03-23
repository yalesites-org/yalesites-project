<?php

namespace Drupal\ys_localist\Controller;

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
 * Runs Localist migrations on request.
 */
class RunMigrations extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $localistConfig;

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
   * Constructs a new AlertManager object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    MessengerInterface $messenger,
    MigrationPluginManager $migration_manager,
    ModuleHandler $module_handler,
    TimeInterface $time,
    ) {
    $this->localistConfig = $config_factory->get('ys_localist.settings');
    $this->messenger = $messenger;
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
      $container->get('messenger'),
      $container->get('plugin.manager.migration'),
      $container->get('module_handler'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Runs all Localist migrations.
   */
  public function runMigrations() {
    // Note: Code taken from migrate_scheduler module and modified.
    // Loop over the list of the migrations and check if they require
    // execution.
    foreach (self::LOCALIST_MIGRATIONS as $migration) {
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

    $this->messenger()->addMessage("Testing", "self::TYPE_STATUS");
    $redirectUrl = Url::fromRoute('ys_localist.settings')->toString();
    $response = new RedirectResponse($redirectUrl);
    return $response;
  }

}
