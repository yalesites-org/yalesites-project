<?php

namespace Drupal\ys_localist;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManager;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service for Localist functions.
 */
class LocalistManager implements ContainerInjectionInterface {

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
   * Constructs a new AlertManager object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, Client $http_client, EntityTypeManager $entity_type_manager) {
    $this->localistConfig = $config_factory->get('ys_localist.settings');
    $this->endpointBase = $this->localistConfig->get('localist_endpoint');
    $this->httpClient = $http_client;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('http_client'),
      $container->get('entity_type.manager')
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
          $endpointsWithParams[] = NULL;
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
    $groupTermId = $this->localistConfig->get('localist_group');
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($groupTermId);
    $groupId = ($term) ? $term->field_localist_group_id->value : NULL;

    return $groupId;
  }

}
