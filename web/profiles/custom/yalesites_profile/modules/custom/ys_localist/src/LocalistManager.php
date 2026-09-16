<?php

namespace Drupal\ys_localist;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateMessage;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrationPluginManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service for Localist functions.
 *
 * On outbound timeouts (yalesites-org/YaleSites-Internal#1701,
 * docs/development.md): getMultiPageUrls() runs on the cron/migrate path and
 * checkGroupsEndpoint() on an admin request, so both are left to the
 * platform-wide defaults in settings.php rather than bounding themselves.
 * Note those defaults arrive with a Pantheon upstream update, not with the
 * profile release, so on a site that has taken the profile bump but not yet
 * the upstream merge these two calls still inherit core's unbounded connect.
 * getTicketInfo() is the exception - it runs on the front-end render path, so
 * it bounds itself explicitly below and does not depend on that rollout.
 */
class LocalistManager extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Seconds to wait for a response on the render path.
   *
   * This lookup runs from ys_localist_preprocess_node() once per event teaser,
   * and its URL is deliberately cache-busted, so a slow Localist multiplies
   * across a listing page instead of being absorbed by a cache. Hence tighter
   * than the platform-wide default.
   *
   * Not tighter still, though, and the reason is measured rather than guessed:
   * this host answered a list endpoint in 4.7s during testing, so a bound in
   * the low single digits would trip on a merely slow response. That matters
   * more than usual here because the degraded result is not transient - an
   * empty return reads downstream as "this event has no registration" and gets
   * frozen into the node's render cache, so an over-tight bound silently drops
   * a registration link until the node is re-saved. See
   * yalesites-org/YaleSites-Internal#1701.
   */
  const TICKET_REQUEST_TIMEOUT = 10;

  /**
   * Seconds to wait for the connection itself on the render path.
   *
   * Only DNS, TCP and the TLS handshake happen inside this window, and this is
   * a CDN-fronted host; taking longer than this to answer the door on a page
   * render is a fault rather than a slow success. Kept well below
   * TICKET_REQUEST_TIMEOUT so an unreachable host fails fast while a reachable
   * but slow one still gets its full response budget.
   */
  const TICKET_CONNECT_TIMEOUT = 3;

  /**
   * List of migrations to run. Place migrations from first to last.
   */
  const LOCALIST_MIGRATIONS = [
    'localist_source_taxonomy',
    'localist_event_types',
    'localist_audience',
    'localist_topics',
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
   * Drupal messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

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
    MessengerInterface $messenger,
  ) {
    $this->localistConfig = $config_factory->get('ys_localist.settings');
    $this->endpointBase = $this->localistConfig->get('localist_endpoint');
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
      $container->get('datetime.time'),
      $container->get('messenger'),
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

        if ($groupId) {
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

      case 'photos':
        $endpointsWithParams[] = "$this->endpointBase/api/2/photos";

        break;

      case 'tickets':
        $endpointsWithParams[] = "$this->endpointBase/api/2/events";

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

    try {
      $response = $this->httpClient->get($url);
    }
    catch (RequestException $e) {
      return $endpointUrls;
    }
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
    if ($this->getEndpointUrls('events')) {
      foreach (self::LOCALIST_MIGRATIONS as $migration) {
        $this->runMigration($migration);
        $messageData[$migration] = [
          'imported' => $this->getMigrationStatus($migration),
        ];
      }
      return $messageData;
    }
    else {
      $this->messenger()->addError('Localist endpoint not configured correctly. No events imported.');
    }

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
   * Checks the group endpoint to make sure we are receiving a JSON feed.
   */
  public function checkGroupsEndpoint() {
    $returnVal = FALSE;
    if ($endpoint = $this->localistConfig->get('localist_endpoint')) {
      $endpointUrl = $endpoint . "/api/2/groups";
      try {
        $response = $this->httpClient->get($endpointUrl);
        $returnVal = str_contains($response->getHeader("Content-Type")[0], 'json') ? TRUE : FALSE;
      }
      catch (\Throwable $th) {

      }

    }

    return $returnVal;

  }

  /**
   * When Localist is first installed, remove the old terms for experiences.
   */
  public function removeOldExperiences() {
    $termsToRemove = [
      'In-person',
      'Online',
      'Hybrid',
    ];

    foreach ($termsToRemove as $term) {
      $terms = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadByProperties(['name' => $term, 'vid' => 'event_type']);
      if ($terms) {
        foreach ($terms as $term) {
          $term->delete();
        }
      }
    }
  }

  /**
   * Returns ticket info for a given event ID.
   *
   * Runs on the render path, so it is bounded tighter than the platform
   * default - see TICKET_REQUEST_TIMEOUT.
   */
  public function getTicketInfo($eventId) {
    $ticketData = [];
    $response = NULL;
    $ticketEndpoint = $this->getEndpointUrls('tickets');
    $version = time();
    $url = "$ticketEndpoint[0]/$eventId/tickets?v=$version";
    try {
      $response = $this->httpClient->get($url, [
        'timeout' => self::TICKET_REQUEST_TIMEOUT,
        'connect_timeout' => self::TICKET_CONNECT_TIMEOUT,
      ]);
    }
    catch (\Throwable $th) {
    }

    if ($response) {
      $data = json_decode($response->getBody()->getContents(), TRUE);
      foreach ($data['tickets'] as $ticket) {
        $ticketData[] = [
          'name' => $ticket['ticket']['name'],
          'desc' => $ticket['ticket']['description'],
          'id' => $ticket['ticket']['id'],
          'price' => $ticket['ticket']['price'],
        ];
      }
    }
    return $ticketData;
  }

}
