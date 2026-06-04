<?php

namespace Drupal\ys_ai\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ai_vdb_provider_azure_ai_search\AzureAiSearch;
use Drupal\key\KeyRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Provisions the Beacon chatbot's Azure AI Search index.
 *
 * The Azure AI Search VDB provider does not create indexes (its
 * createCollection() is a no-op for Azure); operators are otherwise expected to
 * hand-create the index in the Azure portal. This service automates that step:
 * given the already-configured Beacon search server, it creates the remote
 * Azure index from a shipped schema if — and only if — it does not already
 * exist. It is safe to call any number of times ("create if not exists").
 *
 * The Azure URL, API key and per-environment index name are resolved from the
 * Beacon configuration (which BeaconSearchConfigOverride populates from
 * Pantheon Secrets / the environment); this service never derives or stores
 * them.
 */
class BeaconIndexProvisioner {

  /**
   * The Beacon search_api server config name.
   */
  const SERVER_CONFIG = 'search_api.server.beacon';

  /**
   * The Azure VDB provider global settings config name.
   */
  const VDB_SETTINGS = 'ai_vdb_provider_azure_ai_search.settings';

  /**
   * Constructs a BeaconIndexProvisioner.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory (overrides applied, so it sees the resolved URL and
   *   index name).
   * @param \Drupal\key\KeyRepositoryInterface $keyRepository
   *   The key repository, used to resolve the Azure API key value.
   * @param \Drupal\ai_vdb_provider_azure_ai_search\AzureAiSearch $azureClient
   *   The Azure AI Search API client.
   * @param \Psr\Log\LoggerInterface $logger
   *   The ys_ai logger channel.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected KeyRepositoryInterface $keyRepository,
    protected AzureAiSearch $azureClient,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Creates the Beacon Azure AI Search index if it does not already exist.
   *
   * @param bool $force
   *   When TRUE, skip the existence check and create-or-update the index even
   *   if it already exists (Azure adds any new fields). Used by the
   *   ys-ai:create-index --force option to roll out schema changes to existing
   *   indexes; the default (FALSE) keeps the operation idempotent.
   *
   * @return \Drupal\ys_ai\Service\BeaconIndexResult
   *   The outcome: created, updated, already-exists, or failed (with a reason).
   */
  public function ensureIndexExists(bool $force = FALSE): BeaconIndexResult {
    $backend_config = $this->configFactory->get(self::SERVER_CONFIG)->get('backend_config');
    if (empty($backend_config)) {
      return BeaconIndexResult::failed('Beacon search server is not configured.');
    }

    $index_name = trim((string) ($backend_config['database_settings']['database_name'] ?? ''));
    if ($index_name === '') {
      return BeaconIndexResult::failed('Could not resolve the Azure index name. Ensure the environment or Beacon server config provides the index (database) name.');
    }

    $dimensions = (int) ($backend_config['embeddings_engine_configuration']['dimensions'] ?? 0);
    if ($dimensions <= 0) {
      return BeaconIndexResult::failed(sprintf('Could not resolve the embedding dimensions for index "%s". Check the Beacon server embeddings configuration.', $index_name), $index_name);
    }

    $vdb_settings = $this->configFactory->get(self::VDB_SETTINGS);
    $url = trim((string) $vdb_settings->get('url'));
    if ($url === '') {
      return BeaconIndexResult::failed('Azure AI Search URL is not configured.', $index_name);
    }

    $api_key = $this->resolveApiKey((string) $vdb_settings->get('api_key'));
    if ($api_key === '') {
      return BeaconIndexResult::failed('Azure AI Search API key is not available.', $index_name);
    }

    try {
      $client = $this->azureClient->getClient($api_key);

      // Idempotency: skip entirely when the index already exists, unless
      // forced. Azure's PUT /indexes/{name} is an upsert, so without --force we
      // avoid touching an existing index (a PUT can fail on immutable field
      // changes); with --force we apply the current schema (e.g. new fields).
      if (!$force && !empty($client->describeIndex($index_name))) {
        $this->logger->info('Beacon index "@name" already exists; skipping creation.', ['@name' => $index_name]);
        return BeaconIndexResult::alreadyExists($index_name);
      }

      $schema = $this->buildSchema($index_name, $dimensions);
      $response = $client->request('/indexes/' . $index_name, 'PUT', [
        'json' => $schema,
        'headers' => ['Content-Type' => 'application/json'],
      ]);

      $status = $response->getStatusCode();
      if ($status >= 200 && $status < 300) {
        $client->clearIndexesCache();
        // Azure returns 201 when the index is created and 204 when an existing
        // index is updated (new fields added under --force).
        if ($status === 201) {
          $this->logger->info('Created Beacon Azure AI Search index "@name".', ['@name' => $index_name]);
          return BeaconIndexResult::created($index_name);
        }
        $this->logger->info('Updated Beacon Azure AI Search index "@name".', ['@name' => $index_name]);
        return BeaconIndexResult::updated($index_name);
      }

      $this->logger->error('Azure AI Search returned HTTP @status creating index "@name".', [
        '@status' => $status,
        '@name' => $index_name,
      ]);
      return BeaconIndexResult::failed(sprintf('Azure AI Search returned HTTP %d while creating index "%s". See the AI Search logs for details.', $status, $index_name), $index_name);
    }
    catch (\Throwable $e) {
      // Log the underlying reason, but keep the user-facing message generic so
      // exception detail (which can include the Azure endpoint host) is not
      // surfaced in the admin UI.
      $this->logger->error('Failed to create Beacon index "@name": @message', [
        '@name' => $index_name,
        '@message' => $e->getMessage(),
      ]);
      return BeaconIndexResult::failed(sprintf('Failed to create Azure AI Search index "%s". See the logs for details.', $index_name), $index_name);
    }
  }

  /**
   * Resolves the Azure API key value from the configured Key entity.
   *
   * @param string $key_id
   *   The Key entity id stored in the VDB provider settings.
   *
   * @return string
   *   The key value, or an empty string when no Key or value is available.
   */
  protected function resolveApiKey(string $key_id): string {
    if ($key_id === '') {
      return '';
    }
    $key = $this->keyRepository->getKey($key_id);
    if ($key === NULL) {
      return '';
    }
    $value = $key->getKeyValue();
    return is_string($value) ? trim($value) : '';
  }

  /**
   * Builds the Azure index schema, injecting the index name and dimensions.
   *
   * @param string $index_name
   *   The Azure index name.
   * @param int $dimensions
   *   The embedding vector dimensions.
   *
   * @return array
   *   The Azure index definition to send to the create endpoint.
   *
   * @throws \RuntimeException
   *   When the shipped schema asset cannot be read or parsed.
   */
  protected function buildSchema(string $index_name, int $dimensions): array {
    $contents = @file_get_contents($this->getSchemaPath());
    $schema = $contents === FALSE ? NULL : Json::decode($contents);
    if (!is_array($schema) || empty($schema['fields'])) {
      throw new \RuntimeException('The Beacon index schema asset is missing or invalid.');
    }

    $schema['name'] = $index_name;
    foreach ($schema['fields'] as &$field) {
      if (($field['name'] ?? '') === 'vector') {
        $field['dimensions'] = $dimensions;
      }
    }

    return $schema;
  }

  /**
   * Returns the absolute path to the shipped Azure index schema asset.
   *
   * @return string
   *   The schema file path.
   */
  protected function getSchemaPath(): string {
    return __DIR__ . '/../../data/azure_beacon_index.schema.json';
  }

}
