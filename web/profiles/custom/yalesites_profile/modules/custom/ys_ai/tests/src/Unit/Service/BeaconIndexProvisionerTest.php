<?php

namespace Drupal\Tests\ys_ai\Unit\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\ai_vdb_provider_azure_ai_search\AzureAiSearch;
use Drupal\ai_vdb_provider_azure_ai_search\ResponseData;
use Drupal\key\KeyInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai\Service\BeaconIndexProvisioner;
use Drupal\ys_ai\Service\BeaconIndexResult;
use Psr\Log\AbstractLogger;

/**
 * @coversDefaultClass \Drupal\ys_ai\Service\BeaconIndexProvisioner
 *
 * @group yalesites
 */
class BeaconIndexProvisionerTest extends UnitTestCase {

  /**
   * A valid Beacon server backend_config used by the happy-path tests.
   */
  const VALID_BACKEND = [
    'database_settings' => ['database_name' => 'mysite-dev'],
    'embeddings_engine_configuration' => ['dimensions' => 768],
  ];

  /**
   * The capturing logger shared with the service under test.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->logger = new class() extends AbstractLogger {

      /**
       * Captured log records.
       *
       * @var array
       */
      public array $records = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, $message, array $context = []): void {
        $this->records[] = [
          'level' => (string) $level,
          'message' => (string) $message,
          'context' => $context,
        ];
      }

    };
  }

  /**
   * Builds an immutable config mock returning the given values by key.
   *
   * @param array $values
   *   Keyed config values.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The mocked config object.
   */
  protected function immutableConfig(array $values): ImmutableConfig {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      fn($key = '') => $values[$key] ?? NULL
    );
    return $config;
  }

  /**
   * Builds a config factory for the Beacon server and VDB settings.
   *
   * @param array|null $backend_config
   *   The Beacon server backend_config (NULL simulates an unconfigured server).
   * @param string $url
   *   The Azure VDB url.
   * @param string $api_key_id
   *   The Key id stored in the VDB settings.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   The mocked config factory.
   */
  protected function configFactory(?array $backend_config, string $url, string $api_key_id): ConfigFactoryInterface {
    $server = $this->immutableConfig(['backend_config' => $backend_config]);
    $vdb = $this->immutableConfig(['url' => $url, 'api_key' => $api_key_id]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnCallback(function ($name) use ($server, $vdb) {
      return match ($name) {
        BeaconIndexProvisioner::SERVER_CONFIG => $server,
        BeaconIndexProvisioner::VDB_SETTINGS => $vdb,
        default => $this->immutableConfig([]),
      };
    });
    return $factory;
  }

  /**
   * Builds a key repository returning the given API key value.
   *
   * @param string|null $value
   *   The key value, or NULL to simulate a missing Key entity.
   *
   * @return \Drupal\key\KeyRepositoryInterface
   *   The mocked repository.
   */
  protected function keyRepository(?string $value): KeyRepositoryInterface {
    $repository = $this->createMock(KeyRepositoryInterface::class);
    if ($value === NULL) {
      $repository->method('getKey')->willReturn(NULL);
      return $repository;
    }
    $key = $this->createMock(KeyInterface::class);
    $key->method('getKeyValue')->willReturn($value);
    $repository->method('getKey')->willReturn($key);
    return $repository;
  }

  /**
   * Builds a ResponseData mock with the given status code.
   *
   * @param int $status
   *   The HTTP status code.
   *
   * @return \Drupal\ai_vdb_provider_azure_ai_search\ResponseData
   *   The mocked response.
   */
  protected function response(int $status): ResponseData {
    $response = $this->createMock(ResponseData::class);
    $response->method('getStatusCode')->willReturn($status);
    return $response;
  }

  /**
   * Builds the provisioner under test.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository.
   * @param \Drupal\ai_vdb_provider_azure_ai_search\AzureAiSearch $client
   *   The Azure client.
   *
   * @return \Drupal\ys_ai\Service\BeaconIndexProvisioner
   *   The service.
   */
  protected function provisioner(ConfigFactoryInterface $config_factory, KeyRepositoryInterface $key_repository, AzureAiSearch $client): BeaconIndexProvisioner {
    return new BeaconIndexProvisioner($config_factory, $key_repository, $client, $this->logger);
  }

  /**
   * Asserts no captured log record exposes the given secret.
   *
   * @param string $secret
   *   The secret that must not appear anywhere in the log records.
   */
  protected function assertSecretNotLogged(string $secret): void {
    foreach ($this->logger->records as $record) {
      $this->assertStringNotContainsString($secret, $record['message']);
      $this->assertStringNotContainsString($secret, var_export($record['context'], TRUE));
    }
  }

  /**
   * @covers ::ensureIndexExists
   */
  public function testFailsWhenServerNotConfigured(): void {
    $client = $this->createMock(AzureAiSearch::class);
    $client->expects($this->never())->method('getClient');

    $result = $this->provisioner(
      $this->configFactory(NULL, 'https://x.search.windows.net', 'azure_key'),
      $this->keyRepository('secret'),
      $client
    )->ensureIndexExists();

    $this->assertSame(BeaconIndexResult::FAILED, $result->getStatus());
    $this->assertStringContainsString('not configured', $result->getMessage());
  }

  /**
   * @covers ::ensureIndexExists
   */
  public function testFailsWhenIndexNameMissing(): void {
    $client = $this->createMock(AzureAiSearch::class);
    $client->expects($this->never())->method('getClient');

    $backend = [
      'database_settings' => ['database_name' => ''],
      'embeddings_engine_configuration' => ['dimensions' => 768],
    ];
    $result = $this->provisioner(
      $this->configFactory($backend, 'https://x.search.windows.net', 'azure_key'),
      $this->keyRepository('secret'),
      $client
    )->ensureIndexExists();

    $this->assertSame(BeaconIndexResult::FAILED, $result->getStatus());
    $this->assertStringContainsString('index name', $result->getMessage());
  }

  /**
   * @covers ::ensureIndexExists
   */
  public function testFailsWhenDimensionsMissing(): void {
    $client = $this->createMock(AzureAiSearch::class);
    $client->expects($this->never())->method('getClient');

    $backend = ['database_settings' => ['database_name' => 'mysite-dev']];
    $result = $this->provisioner(
      $this->configFactory($backend, 'https://x.search.windows.net', 'azure_key'),
      $this->keyRepository('secret'),
      $client
    )->ensureIndexExists();

    $this->assertSame(BeaconIndexResult::FAILED, $result->getStatus());
    $this->assertStringContainsString('dimensions', $result->getMessage());
  }

  /**
   * @covers ::ensureIndexExists
   */
  public function testFailsWhenUrlMissing(): void {
    $client = $this->createMock(AzureAiSearch::class);
    $client->expects($this->never())->method('getClient');

    $result = $this->provisioner(
      $this->configFactory(self::VALID_BACKEND, '', 'azure_key'),
      $this->keyRepository('secret'),
      $client
    )->ensureIndexExists();

    $this->assertSame(BeaconIndexResult::FAILED, $result->getStatus());
    $this->assertStringContainsString('URL', $result->getMessage());
  }

  /**
   * @covers ::ensureIndexExists
   */
  public function testFailsWhenApiKeyMissing(): void {
    $client = $this->createMock(AzureAiSearch::class);
    $client->expects($this->never())->method('getClient');

    $result = $this->provisioner(
      $this->configFactory(self::VALID_BACKEND, 'https://x.search.windows.net', 'azure_key'),
      $this->keyRepository(NULL),
      $client
    )->ensureIndexExists();

    $this->assertSame(BeaconIndexResult::FAILED, $result->getStatus());
    $this->assertStringContainsString('API key', $result->getMessage());
  }

  /**
   * @covers ::ensureIndexExists
   */
  public function testFailsWhenApiKeyEmpty(): void {
    $client = $this->createMock(AzureAiSearch::class);
    $client->expects($this->never())->method('getClient');

    $result = $this->provisioner(
      $this->configFactory(self::VALID_BACKEND, 'https://x.search.windows.net', 'azure_key'),
      $this->keyRepository(''),
      $client
    )->ensureIndexExists();

    $this->assertSame(BeaconIndexResult::FAILED, $result->getStatus());
    $this->assertStringContainsString('API key', $result->getMessage());
  }

  /**
   * @covers ::ensureIndexExists
   */
  public function testIdempotentWhenIndexExists(): void {
    $client = $this->createMock(AzureAiSearch::class);
    $client->method('getClient')->willReturnSelf();
    $client->method('describeIndex')->with('mysite-dev')
      ->willReturn(['name' => 'mysite-dev']);
    $client->expects($this->never())->method('request');
    $client->expects($this->never())->method('clearIndexesCache');

    $result = $this->provisioner(
      $this->configFactory(self::VALID_BACKEND, 'https://x.search.windows.net', 'azure_key'),
      $this->keyRepository('secret'),
      $client
    )->ensureIndexExists();

    $this->assertSame(BeaconIndexResult::EXISTS, $result->getStatus());
    $this->assertStringContainsString('already exists', $result->getMessage());
  }

  /**
   * @covers ::ensureIndexExists
   * @covers ::buildSchema
   */
  public function testCreatesIndexWhenMissing(): void {
    $captured = [];
    $client = $this->createMock(AzureAiSearch::class);
    $client->method('getClient')->willReturnSelf();
    $client->method('describeIndex')->willReturn([]);
    $client->expects($this->once())->method('request')
      ->willReturnCallback(function ($path, $method, $options) use (&$captured) {
        $captured = [
          'path' => $path,
          'method' => $method,
          'options' => $options,
        ];
        return $this->response(201);
      });
    $client->expects($this->once())->method('clearIndexesCache');

    $result = $this->provisioner(
      $this->configFactory(self::VALID_BACKEND, 'https://x.search.windows.net', 'azure_key'),
      $this->keyRepository('secret'),
      $client
    )->ensureIndexExists();

    $this->assertSame(BeaconIndexResult::CREATED, $result->getStatus());
    $this->assertSame('mysite-dev', $result->getIndexName());

    // The request targets the resolved index name with a PUT.
    $this->assertSame('/indexes/mysite-dev', $captured['path']);
    $this->assertSame('PUT', $captured['method']);

    // The schema body carries the resolved name and injected dimensions.
    $schema = $captured['options']['json'];
    $this->assertSame('mysite-dev', $schema['name']);
    $vector = $this->fieldByName($schema['fields'], 'vector');
    $this->assertSame(768, $vector['dimensions']);
    $this->assertSame('beacon-vector-profile', $vector['vectorSearchProfile']);
  }

  /**
   * @covers ::ensureIndexExists
   */
  public function testFailsWhenCreateReturnsNonSuccess(): void {
    $client = $this->createMock(AzureAiSearch::class);
    $client->method('getClient')->willReturnSelf();
    $client->method('describeIndex')->willReturn([]);
    $client->method('request')->willReturn($this->response(400));
    $client->expects($this->never())->method('clearIndexesCache');

    $result = $this->provisioner(
      $this->configFactory(self::VALID_BACKEND, 'https://x.search.windows.net', 'azure_key'),
      $this->keyRepository('secret'),
      $client
    )->ensureIndexExists();

    $this->assertSame(BeaconIndexResult::FAILED, $result->getStatus());
    $this->assertStringContainsString('400', $result->getMessage());
  }

  /**
   * @covers ::ensureIndexExists
   */
  public function testFailsWhenRequestThrows(): void {
    $client = $this->createMock(AzureAiSearch::class);
    $client->method('getClient')->willReturnSelf();
    $client->method('describeIndex')->willReturn([]);
    $client->method('request')
      ->willThrowException(new \RuntimeException('boom'));
    $client->expects($this->never())->method('clearIndexesCache');

    $result = $this->provisioner(
      $this->configFactory(self::VALID_BACKEND, 'https://x.search.windows.net', 'azure_key'),
      $this->keyRepository('secret'),
      $client
    )->ensureIndexExists();

    $this->assertSame(BeaconIndexResult::FAILED, $result->getStatus());
    // The raw exception detail is logged but kept out of the user-facing
    // message, which only points to the logs.
    $this->assertStringNotContainsString('boom', $result->getMessage());
    $logged = array_map(
      fn($record) => $record['message'] . var_export($record['context'], TRUE),
      $this->logger->records
    );
    $this->assertStringContainsString('boom', implode("\n", $logged));
  }

  /**
   * The resolved API key must never appear in messages or log output.
   *
   * @covers ::ensureIndexExists
   */
  public function testSecretNeverLeaks(): void {
    $secret = 'super-secret-key';
    $client = $this->createMock(AzureAiSearch::class);
    $client->method('getClient')->willReturnSelf();
    $client->method('describeIndex')->willReturn([]);
    $client->method('request')->willReturn($this->response(201));

    $result = $this->provisioner(
      $this->configFactory(self::VALID_BACKEND, 'https://x.search.windows.net', 'azure_key'),
      $this->keyRepository($secret),
      $client
    )->ensureIndexExists();

    $this->assertSame(BeaconIndexResult::CREATED, $result->getStatus());
    $this->assertStringNotContainsString($secret, $result->getMessage());
    $this->assertSecretNotLogged($secret);
  }

  /**
   * The shipped Azure schema asset has the fields the provider reads/writes.
   */
  public function testSchemaAssetIsValid(): void {
    $path = __DIR__ . '/../../../../data/azure_beacon_index.schema.json';
    $this->assertFileExists($path);

    $schema = json_decode(file_get_contents($path), TRUE);
    $this->assertIsArray($schema);

    $fields = [];
    foreach ($schema['fields'] as $field) {
      $fields[$field['name']] = $field;
    }

    foreach (['id', 'drupal_entity_id', 'drupal_long_id', 'index_id', 'server_id', 'content', 'vector'] as $name) {
      $this->assertArrayHasKey($name, $fields, "Missing field: $name");
    }
    $this->assertTrue($fields['id']['key']);

    $vector = $fields['vector'];
    $this->assertSame('Collection(Edm.Single)', $vector['type']);
    $this->assertTrue($vector['searchable']);
    $this->assertNotEmpty($vector['vectorSearchProfile']);

    $algorithm = $schema['vectorSearch']['algorithms'][0];
    $profile = $schema['vectorSearch']['profiles'][0];
    $this->assertSame('hnsw', $algorithm['kind']);
    $this->assertSame('cosine', $algorithm['hnswParameters']['metric']);
    $this->assertSame($algorithm['name'], $profile['algorithm']);
    $this->assertSame($vector['vectorSearchProfile'], $profile['name']);
  }

  /**
   * Finds a field definition by name.
   *
   * @param array $fields
   *   The schema field list.
   * @param string $name
   *   The field name to find.
   *
   * @return array
   *   The matching field definition, or an empty array.
   */
  protected function fieldByName(array $fields, string $name): array {
    foreach ($fields as $field) {
      if (($field['name'] ?? '') === $name) {
        return $field;
      }
    }
    return [];
  }

}
