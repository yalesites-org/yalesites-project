<?php

namespace Drupal\Tests\ys_beacon\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Server;

/**
 * Tests that importing the Beacon search server on a fresh site is safe.
 *
 * The Beacon search server ships in synced config with an empty per-site index
 * name (`database_name: ''`); the real name is layered in at read time by
 * YsBeaconConfigOverrides. Saving a `search_api.server.*` config fires
 * ai_search's NewServerEventSubscriber, which reaches into the configured
 * vector-database backend. On a brand-new site that has no Azure index yet,
 * that path must not throw an uncaught error and abort the whole config
 * import - the failure the old ys_ai BeaconServerConfigSubscriber guarded
 * against. This test locks the safe behavior so a contrib bump that
 * reintroduces the abort fails CI instead of breaking a deploy.
 *
 * @group ys_beacon
 */
class BeaconServerImportTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'search_api',
    'ai',
    'ai_search',
    'ai_vdb_provider_azure_ai_search',
    'ys_beacon_portkey',
  ];

  /**
   * The Beacon server backend config as shipped in synced configuration.
   *
   * Mirrors config/sync/search_api.server.ys_beacon.yml, with the per-site
   * index name left as the empty default.
   */
  private const BACKEND_CONFIG = [
    'chat_model' => 'gpt-3.5-turbo',
    'database' => 'azure_ai_search',
    'database_settings' => [
      'database_name' => '',
      'collection' => '',
    ],
    'embeddings_engine' => 'portkey__text-embedding-3-small',
    'embeddings_engine_configuration' => [
      'set_dimensions' => TRUE,
      'dimensions' => 1536,
    ],
    'embedding_strategy' => 'contextual_chunks',
    'embedding_strategy_configuration' => [
      'chunk_size' => '1024',
      'chunk_min_overlap' => '128',
      'contextual_content_max_percentage' => '30',
      'skip_moderation' => TRUE,
    ],
    'embedding_strategy_details' => '',
    'include_raw_embedding_vector' => FALSE,
  ];

  /**
   * Saving the Beacon server with an empty index name must not abort.
   */
  public function testFreshImportOfBeaconServerSucceeds(): void {
    $server = Server::create([
      'id' => 'ys_beacon',
      'name' => 'Beacon AI Search',
      'backend' => 'search_api_ai_search',
      'backend_config' => self::BACKEND_CONFIG,
    ]);

    // The save triggers ai_search's NewServerEventSubscriber. With no Azure
    // connection configured and an empty index name, it must degrade quietly
    // instead of throwing and aborting the import.
    $server->save();

    $loaded = Server::load('ys_beacon');
    $this->assertNotNull($loaded, 'The Beacon search server was imported.');
    $this->assertSame(
      '',
      $loaded->getBackendConfig()['database_settings']['database_name'],
      'The stored index name stays empty; the real value is supplied at read time by YsBeaconConfigOverrides.',
    );
  }

}
