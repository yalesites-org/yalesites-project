<?php

namespace Drupal\Tests\ys_ai\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_vdb_provider_azure_ai_search\AzureAiSearch;
use Drupal\key\Entity\Key;
use Drupal\ys_ai\Service\BeaconIndexProvisioner;
use Drupal\ys_ai\Service\BeaconIndexResult;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Integration tests for the Beacon index provisioner up to the HTTP boundary.
 *
 * Exercises the provisioner against the real config factory, key repository and
 * Azure AI Search client, with only the Guzzle transport mocked, so the exact
 * request sent to Azure (method, URL, api-key header, schema body) is verified.
 *
 * @coversDefaultClass \Drupal\ys_ai\Service\BeaconIndexProvisioner
 *
 * @group yalesites
 */
class BeaconIndexProvisionerKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'key'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Write the Beacon and Azure settings straight to the active config
    // storage. The schemas live in the AI modules (not installed here), so we
    // bypass save-time schema validation by writing storage directly rather
    // than disabling strict schema checking.
    $storage = $this->container->get('config.storage');

    // The Beacon server config carries the resolved index name and dimensions.
    $storage->write('search_api.server.beacon', [
      'backend_config' => [
        'database_settings' => ['database_name' => 'mysite-dev'],
        'embeddings_engine_configuration' => ['dimensions' => 1536],
      ],
    ]);

    // The Azure VDB provider settings the client reads.
    $storage->write('ai_vdb_provider_azure_ai_search.settings', [
      'url' => 'https://test.search.windows.net',
      'api_version' => '2023-11-01',
      'api_key' => 'azure_search_key',
    ]);

    // A Pantheon Secret is exposed to Drupal as a Key entity; use the config
    // provider so the value is self-contained in the test.
    Key::create([
      'id' => 'azure_search_key',
      'label' => 'Azure AI Search API key',
      'key_type' => 'authentication',
      'key_provider' => 'config',
      'key_provider_settings' => [
        'key_value' => 'super-secret-key',
        'base64_encoded' => FALSE,
      ],
      'key_input' => 'text_field',
    ])->save();
  }

  /**
   * Builds the provisioner backed by a mocked Guzzle transport.
   *
   * @param \GuzzleHttp\Psr7\Response[] $responses
   *   The queued HTTP responses.
   * @param array $history
   *   Captures the sent requests, by reference.
   *
   * @return \Drupal\ys_ai\Service\BeaconIndexProvisioner
   *   The provisioner under test.
   */
  protected function buildProvisioner(array $responses, array &$history): BeaconIndexProvisioner {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $http_client = new Client(['handler' => $stack]);

    $azure_client = new AzureAiSearch(
      $this->container->get('cache.default'),
      $this->container->get('messenger'),
      $this->container->get('logger.factory'),
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $http_client,
    );

    return new BeaconIndexProvisioner(
      $this->container->get('config.factory'),
      $this->container->get('key.repository'),
      $azure_client,
      $this->container->get('logger.factory')->get('ys_ai'),
    );
  }

  /**
   * Creates the remote index with the expected request when it is missing.
   *
   * @covers ::ensureIndexExists
   */
  public function testCreatesRemoteIndexWhenMissing(): void {
    $history = [];
    $provisioner = $this->buildProvisioner([
      // GET /indexes: no existing indexes.
      new Response(200, [], json_encode(['value' => []])),
      // PUT /indexes/mysite-dev: created.
      new Response(201, [], json_encode(['name' => 'mysite-dev'])),
    ], $history);

    $result = $provisioner->ensureIndexExists();

    $this->assertSame(BeaconIndexResult::CREATED, $result->getStatus());
    $this->assertCount(2, $history);

    $put = $history[1]['request'];
    $this->assertSame('PUT', $put->getMethod());
    $this->assertSame('/indexes/mysite-dev', $put->getUri()->getPath());
    $this->assertStringContainsString('api-version=2023-11-01', $put->getUri()->getQuery());
    $this->assertSame('super-secret-key', $put->getHeaderLine('api-key'));

    $body = json_decode((string) $put->getBody(), TRUE);
    $this->assertSame('mysite-dev', $body['name']);
    $vector = NULL;
    foreach ($body['fields'] as $field) {
      if ($field['name'] === 'vector') {
        $vector = $field;
      }
    }
    $this->assertNotNull($vector);
    $this->assertSame(1536, $vector['dimensions']);
  }

  /**
   * Skips creation (no PUT) when the remote index already exists.
   *
   * @covers ::ensureIndexExists
   */
  public function testSkipsCreationWhenIndexExists(): void {
    $history = [];
    $provisioner = $this->buildProvisioner([
      // GET /indexes: the index already exists.
      new Response(200, [], json_encode(['value' => [['name' => 'mysite-dev']]])),
    ], $history);

    $result = $provisioner->ensureIndexExists();

    $this->assertSame(BeaconIndexResult::EXISTS, $result->getStatus());
    $this->assertCount(1, $history);
    $this->assertSame('GET', $history[0]['request']->getMethod());
  }

}
