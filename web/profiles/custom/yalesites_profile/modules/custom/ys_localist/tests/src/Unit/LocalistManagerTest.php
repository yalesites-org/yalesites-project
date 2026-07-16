<?php

namespace Drupal\Tests\ys_localist\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\migrate\Plugin\MigrationPluginManager;
use Drupal\Core\Entity\EntityInterface;
use Drupal\ys_localist\LocalistManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Unit tests for the LocalistManager service.
 *
 * The Localist API is never hit for real here: the Guzzle client is mocked
 * throughout.
 *
 * @coversDefaultClass \Drupal\ys_localist\LocalistManager
 *
 * @group yalesites
 * @group ys_localist
 */
class LocalistManagerTest extends UnitTestCase {

  /**
   * The mocked config object for 'ys_localist.settings'.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $config;

  /**
   * Config values keyed by name, returned by the config mock's get().
   *
   * @var array
   */
  protected $configValues;

  /**
   * The mocked Guzzle client.
   *
   * @var \GuzzleHttp\Client|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $httpClient;

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The mocked messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $messenger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config = $this->createMock(ImmutableConfig::class);
    $this->configValues = ['localist_endpoint' => 'https://events.yale.edu'];
    $this->config->method('get')->willReturnCallback(
      fn ($key) => $this->configValues[$key] ?? NULL
    );

    $this->httpClient = $this->createMock(Client::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManager::class);
    $this->messenger = $this->createMock(MessengerInterface::class);
  }

  /**
   * Builds the manager under test with mocked constructor dependencies.
   */
  protected function createManager(): LocalistManager {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('ys_localist.settings')->willReturn($this->config);

    return new LocalistManager(
      $config_factory,
      $this->httpClient,
      $this->entityTypeManager,
      $this->createMock(MigrationPluginManager::class),
      $this->createMock(ModuleHandler::class),
      $this->createMock(TimeInterface::class),
      $this->messenger
    );
  }

  /**
   * Builds a mocked JSON HTTP response.
   */
  protected function jsonResponse(array $data): ResponseInterface {
    $json = json_encode($data);

    // getMultiPageUrls() decodes the stream object directly (relying on
    // __toString()), while getTicketInfo() calls ->getContents() first --
    // stub both so this helper covers either call site.
    $stream = $this->createMock(StreamInterface::class);
    $stream->method('__toString')->willReturn($json);
    $stream->method('getContents')->willReturn($json);

    $response = $this->createMock(ResponseInterface::class);
    $response->method('getBody')->willReturn($stream);

    return $response;
  }

  /**
   * @covers ::getEndpointUrls
   */
  public function testGetEndpointUrlsEventsReturnsEmptyWhenNoGroupConfigured(): void {
    $this->configValues['localist_group'] = NULL;

    $this->assertSame([], $this->createManager()->getEndpointUrls('events'));
  }

  /**
   * @covers ::getEndpointUrls
   */
  public function testGetEndpointUrlsEventsBuildsUrlWithGroupIdWhenConfigured(): void {
    $this->configValues['localist_group'] = 7;

    // A lightweight stand-in supporting the code's magic field access
    // ($term->field_localist_group_id->value). PHPUnit cannot mock the magic
    // __get, and EntityStorageInterface::load() has no return type, so a plain
    // object is accepted here.
    $term = (object) ['field_localist_group_id' => (object) ['value' => 42]];

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(7)->willReturn($term);
    $this->entityTypeManager->method('getStorage')->with('taxonomy_term')->willReturn($storage);

    $urls = $this->createManager()->getEndpointUrls('events');

    $this->assertCount(1, $urls);
    $this->assertStringStartsWith('https://events.yale.edu/api/2/events?end=', $urls[0]);
    $this->assertStringContainsString('group_id=42', $urls[0]);
    $this->assertStringContainsString('pp=100', $urls[0]);
  }

  /**
   * @covers ::getEndpointUrls
   * @covers ::getMultiPageUrls
   */
  public function testGetEndpointUrlsPlacesPaginatesAcrossReturnedPageCount(): void {
    $this->httpClient->method('get')
      ->with('https://events.yale.edu/api/2/places?pp=100')
      ->willReturn($this->jsonResponse(['page' => ['total' => 2]]));

    $urls = $this->createManager()->getEndpointUrls('places');

    $this->assertSame([
      'https://events.yale.edu/api/2/places?pp=100&page=1',
      'https://events.yale.edu/api/2/places?pp=100&page=2',
    ], $urls);
  }

  /**
   * @covers ::getEndpointUrls
   * @covers ::getMultiPageUrls
   */
  public function testGetEndpointUrlsGroupsReturnsEmptyArrayOnRequestException(): void {
    $this->httpClient->method('get')
      ->willThrowException(new RequestException('Connection failed', new GuzzleRequest('GET', 'https://events.yale.edu/api/2/groups?pp=100')));

    $this->assertSame([], $this->createManager()->getEndpointUrls('groups'));
  }

  /**
   * @covers ::getEndpointUrls
   */
  public function testGetEndpointUrlsFiltersReturnsSingleUrl(): void {
    $this->assertSame(
      ['https://events.yale.edu/api/2/events/filters'],
      $this->createManager()->getEndpointUrls('filters')
    );
  }

  /**
   * @covers ::getEndpointUrls
   */
  public function testGetEndpointUrlsPhotosReturnsSingleUrl(): void {
    $this->assertSame(
      ['https://events.yale.edu/api/2/photos'],
      $this->createManager()->getEndpointUrls('photos')
    );
  }

  /**
   * @covers ::getEndpointUrls
   */
  public function testGetEndpointUrlsTicketsReturnsSingleUrl(): void {
    $this->assertSame(
      ['https://events.yale.edu/api/2/events'],
      $this->createManager()->getEndpointUrls('tickets')
    );
  }

  /**
   * @covers ::getEndpointUrls
   */
  public function testGetEndpointUrlsReturnsEmptyArrayForUnknownType(): void {
    $this->assertSame([], $this->createManager()->getEndpointUrls('unknown'));
  }

  /**
   * @covers ::runAllMigrations
   */
  public function testRunAllMigrationsRunsAllMigrationsWhenEventsEndpointConfigured(): void {
    $manager = $this->getMockBuilder(LocalistManager::class)
      ->setConstructorArgs([
        $this->createConfigFactory(),
        $this->httpClient,
        $this->entityTypeManager,
        $this->createMock(MigrationPluginManager::class),
        $this->createMock(ModuleHandler::class),
        $this->createMock(TimeInterface::class),
        $this->messenger,
      ])
      ->onlyMethods(['getEndpointUrls', 'runMigration', 'getMigrationStatus'])
      ->getMock();

    $manager->method('getEndpointUrls')->with('events')->willReturn(['https://events.yale.edu/api/2/events?...']);

    $ran_migrations = [];
    $manager->method('runMigration')->willReturnCallback(function ($migration) use (&$ran_migrations) {
      $ran_migrations[] = $migration;
    });
    $manager->method('getMigrationStatus')->willReturn(3);

    $result = $manager->runAllMigrations();

    $this->assertSame(LocalistManager::LOCALIST_MIGRATIONS, $ran_migrations);
    foreach (LocalistManager::LOCALIST_MIGRATIONS as $migration) {
      $this->assertSame(['imported' => 3], $result[$migration]);
    }
  }

  /**
   * @covers ::runAllMigrations
   */
  public function testRunAllMigrationsShowsErrorWhenEventsEndpointNotConfigured(): void {
    $manager = $this->getMockBuilder(LocalistManager::class)
      ->setConstructorArgs([
        $this->createConfigFactory(),
        $this->httpClient,
        $this->entityTypeManager,
        $this->createMock(MigrationPluginManager::class),
        $this->createMock(ModuleHandler::class),
        $this->createMock(TimeInterface::class),
        $this->messenger,
      ])
      ->onlyMethods(['getEndpointUrls', 'runMigration'])
      ->getMock();

    $manager->method('getEndpointUrls')->with('events')->willReturn([]);
    $manager->expects($this->never())->method('runMigration');

    $this->messenger->expects($this->once())
      ->method('addError')
      ->with('Localist endpoint not configured correctly. No events imported.');

    $manager->runAllMigrations();
  }

  /**
   * @covers ::checkGroupsEndpoint
   */
  public function testCheckGroupsEndpointReturnsTrueWhenContentTypeIsJson(): void {
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getHeader')->with('Content-Type')->willReturn(['application/json; charset=utf-8']);
    $this->httpClient->method('get')->with('https://events.yale.edu/api/2/groups')->willReturn($response);

    $this->assertTrue($this->createManager()->checkGroupsEndpoint());
  }

  /**
   * @covers ::checkGroupsEndpoint
   */
  public function testCheckGroupsEndpointReturnsFalseWhenContentTypeIsNotJson(): void {
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getHeader')->with('Content-Type')->willReturn(['text/html']);
    $this->httpClient->method('get')->willReturn($response);

    $this->assertFalse($this->createManager()->checkGroupsEndpoint());
  }

  /**
   * @covers ::checkGroupsEndpoint
   */
  public function testCheckGroupsEndpointReturnsFalseWhenNoEndpointConfigured(): void {
    $this->config = $this->createMock(ImmutableConfig::class);
    $this->configValues['localist_endpoint'] = '';
    $this->httpClient->expects($this->never())->method('get');

    $this->assertFalse($this->createManager()->checkGroupsEndpoint());
  }

  /**
   * @covers ::checkGroupsEndpoint
   */
  public function testCheckGroupsEndpointReturnsFalseOnThrowable(): void {
    $this->httpClient->method('get')->willThrowException(new \RuntimeException('Connection refused'));

    $this->assertFalse($this->createManager()->checkGroupsEndpoint());
  }

  /**
   * @covers ::removeOldExperiences
   */
  public function testRemoveOldExperiencesDeletesLegacyTerms(): void {
    $terms = [
      'In-person' => $this->createMock(EntityInterface::class),
      'Online' => $this->createMock(EntityInterface::class),
      'Hybrid' => $this->createMock(EntityInterface::class),
    ];
    foreach ($terms as $term) {
      $term->expects($this->once())->method('delete');
    }

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturnCallback(
      fn (array $properties) => [$terms[$properties['name']]]
    );
    $this->entityTypeManager->method('getStorage')->with('taxonomy_term')->willReturn($storage);

    $this->createManager()->removeOldExperiences();
  }

  /**
   * @covers ::removeOldExperiences
   */
  public function testRemoveOldExperiencesSkipsWhenNoMatchingTerms(): void {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadByProperties')->willReturn([]);
    $this->entityTypeManager->method('getStorage')->with('taxonomy_term')->willReturn($storage);

    // No exception, no calls to delete() -- nothing to assert beyond this
    // completing without error.
    $this->createManager()->removeOldExperiences();
    $this->addToAssertionCount(1);
  }

  /**
   * @covers ::getTicketInfo
   */
  public function testGetTicketInfoReturnsMappedTicketData(): void {
    $this->httpClient->method('get')
      ->willReturn($this->jsonResponse([
        'tickets' => [
          ['ticket' => ['id' => 1, 'name' => 'General', 'description' => 'General admission', 'price' => 10]],
          ['ticket' => ['id' => 2, 'name' => 'Free', 'description' => 'Free entry', 'price' => 0]],
        ],
      ]));

    $result = $this->createManager()->getTicketInfo(555);

    $this->assertSame([
      ['name' => 'General', 'desc' => 'General admission', 'id' => 1, 'price' => 10],
      ['name' => 'Free', 'desc' => 'Free entry', 'id' => 2, 'price' => 0],
    ], $result);
  }

  /**
   * @covers ::getTicketInfo
   */
  public function testGetTicketInfoReturnsEmptyArrayOnThrowable(): void {
    $this->httpClient->method('get')->willThrowException(new \RuntimeException('Connection refused'));

    $this->assertSame([], $this->createManager()->getTicketInfo(555));
  }

  /**
   * Builds a config factory mock returning $this->config for the settings.
   */
  protected function createConfigFactory(): ConfigFactoryInterface {
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('ys_localist.settings')->willReturn($this->config);
    return $config_factory;
  }

}
