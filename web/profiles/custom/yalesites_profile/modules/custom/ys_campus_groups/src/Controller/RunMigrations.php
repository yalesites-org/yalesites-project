<?php

namespace Drupal\ys_campus_groups\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\ys_campus_groups\CampusGroupsManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Runs Campus Groups migrations on request.
 */
class RunMigrations extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Marker for the spurious legacy-database warning to strip from the screen.
   *
   * Whenever migration definitions are rebuilt, core's
   * migrate_drupal_migration_plugins_alter() probes the unrelated system_site
   * migration (whose source is the "variable" plugin) to detect a legacy
   * Drupal source database. This platform has none, so that probe fails and
   * core queues a "Failed to connect to your database server" messenger error
   * containing this text -- even though the Campus Groups sync (an HTTP feed,
   * not a database source) succeeded. It is harmless noise, unrelated to
   * Campus Groups. See yalesites-org/YaleSites-Internal#1394.
   */
  const SPURIOUS_MIGRATE_DB_WARNING = 'No database connection configured for source plugin variable';

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $campusGroupsConfig;

  /**
   * The Campus Groups Manager.
   *
   * @var \Drupal\ys_campus_groups\CampusGroupsManager
   */
  protected $campusGroupsManager;

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
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a new RunMigrations object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    CampusGroupsManager $campus_groups_manager,
    MessengerInterface $messenger,
    RequestStack $request_stack,
  ) {
    $this->campusGroupsConfig = $config_factory->get('ys_campus_groups.settings');
    $this->campusGroupsManager = $campus_groups_manager;
    $this->messenger = $messenger;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('ys_campus_groups.manager'),
      $container->get('messenger'),
      $container->get('request_stack'),
    );
  }

  /**
   * Runs all Localist migrations.
   */
  public function runAllMigrations() {
    if ($this->campusGroupsConfig->get('enable_campus_groups_sync')) {
      $messageData = $this->campusGroupsManager->runAllMigrations();
      $this->removeSpuriousMigrateDatabaseWarning();
      if ($messageData) {
        $eventsImported = $messageData['campus_groups_events']['imported'];
        $message = "Synchronized $eventsImported events.";
        $this->messenger()->addStatus($message);
      }
    }
    else {
      $message = 'Campus Groups syncing is not enabled.';
      $this->messenger()->addError($message);
    }

    $redirectUrl = $this->getRedirectUrl();
    $response = new RedirectResponse($redirectUrl);
    return $response;
  }

  /**
   * Retrieves the URL to redirect to after the migration is run.
   *
   * @return string
   *   The URL to redirect to.
   */
  protected function getRedirectUrl(): string {
    $referer = $this->requestStack->getCurrentRequest()->server->get('HTTP_REFERER');

    if ($referer == NULL) {
      $referer = Url::fromRoute('<front>')->toString();
    }

    return $referer;
  }

  /**
   * Removes the harmless legacy-database warning core queues during sync.
   *
   * See self::SPURIOUS_MIGRATE_DB_WARNING for why the message appears. The
   * match is intentionally narrow (that one message only): if core ever
   * rewords it we simply stop matching and the still-harmless message
   * reappears, which is safer than a broad filter that could hide a genuinely
   * different database error. All other errors are preserved.
   */
  private function removeSpuriousMigrateDatabaseWarning(): void {
    $messenger = $this->messenger();
    $errors = $messenger->messagesByType(MessengerInterface::TYPE_ERROR);
    if (!$errors) {
      return;
    }

    $messenger->deleteByType(MessengerInterface::TYPE_ERROR);
    foreach ($errors as $error) {
      if (!str_contains((string) $error, self::SPURIOUS_MIGRATE_DB_WARNING)) {
        $messenger->addError($error);
      }
    }
  }

}
