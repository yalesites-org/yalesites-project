<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use Psr\Log\LoggerInterface;

/**
 * Provisions per-site Azure AI Search indexes for Beacon.
 *
 * Index names default to the Pantheon site name and environment, so every
 * site gets a unique index without manual coordination. Creation is strictly
 * conditional: an existing index is adopted as-is and never modified. The
 * create call uses POST /indexes, which Azure rejects when the index already
 * exists, so even a race cannot overwrite an existing index definition.
 *
 * The connection settings come from the Azure VDB provider module config,
 * where the endpoint URL is layered in from a key entity at runtime by
 * YsBeaconConfigOverrides. Document writes already require an Azure admin
 * key, so the same configured key is authorized to create indexes.
 */
class BeaconIndexManager {

  /**
   * Maximum length of an Azure AI Search index name.
   */
  protected const MAX_NAME_LENGTH = 128;

  /**
   * Default maximum number of indexes one Azure AI Search service may hold.
   *
   * A single Azure AI Search service caps how many indexes it can contain, and
   * creating another once it is full fails. Beacon refuses to create past this
   * limit and logs an actionable error instead of hitting an opaque Azure
   * rejection. This constant is the single source of the default; a site can
   * override it live via ys_beacon.settings:max_indexes without a deploy
   * (yalesites-org/YaleSites-Internal#1440).
   */
  public const DEFAULT_MAX_INDEXES = 50;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected KeyRepositoryInterface $keyRepository,
    protected ClientInterface $httpClient,
    protected LoggerInterface $logger,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Provisions the site's index end to end.
   *
   * Ensures the Azure index exists (creating it only when missing), persists
   * its name to the Beacon settings, and rebuilds Search API tracking so all
   * existing content is queued for indexing. Nothing is persisted when the
   * Azure call fails, giving every caller the same failure semantic: a failed
   * provisioning never leaves the site pointing at a nonexistent index.
   *
   * @param string|null $name
   *   The index name, or NULL to use the per-site default.
   * @param bool $enforce_capacity
   *   Whether to refuse creation when the target service is at its index limit.
   *   TRUE for a genuinely new index; FALSE when recreating an index this site
   *   already owns (reprovision), which is net-zero against the limit.
   *
   * @return string
   *   The provisioned index name.
   *
   * @throws \RuntimeException
   *   When the index cannot be verified or created.
   */
  public function provision(?string $name = NULL, bool $enforce_capacity = TRUE): string {
    $name = $this->ensureIndex($name, $enforce_capacity);

    // A provisioned index is owned and written by this site, so persist the
    // connection into the real Search API config as writable (read-only off).
    $this->propagateConnection($name, FALSE);

    // Pin the endpoint now that this site owns an index on it, so a later
    // change to the shared Pantheon secret only moves sites that have not
    // created an index yet (yalesites-org/YaleSites-Internal#1440). A failed
    // ensureIndex() throws before this line, so a failed provision never pins.
    $this->pinSearchUrl();

    // The Beacon search index stays disabled at runtime until an index name
    // exists, so Search API never initialized its tracker. Rebuild it now
    // that the index is available.
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($this->searchIndexId());
    if ($index && $index->status()) {
      $index->rebuildTracker();
    }

    return $name;
  }

  /**
   * Recreates the site's index with the current schema and re-queues indexing.
   *
   * An existing Azure index is never modified in place (createIndex() uses
   * POST /indexes, which fails when the index exists), so when the schema
   * changes - for example the site_id field added for shared-collection write
   * isolation (yalesites-org/YaleSites-Internal#1392) - a provisioned index
   * must be dropped and recreated. provision() then rebuilds Search API
   * tracking, queuing a full reindex that rewrites every document in the
   * current format.
   *
   * The capacity guard is deliberately skipped here: this recreates an index
   * the site already owns (and just deleted), so it is net-zero against the
   * service index limit. Enforcing the limit after the delete could refuse the
   * recreate on a full service and strand the site with no index at all
   * (yalesites-org/YaleSites-Internal#1440).
   *
   * @param string|null $name
   *   The index name, or NULL to use the configured (or default) one.
   *
   * @return string
   *   The recreated index name.
   *
   * @throws \RuntimeException
   *   When the index cannot be dropped or recreated.
   */
  public function reprovision(?string $name = NULL): string {
    $name = $name
      ?: ($this->configFactory->get('ys_beacon.settings')->get('azure_index_name') ?: $this->getDefaultIndexName());
    if ($this->indexExists($name)) {
      $this->deleteIndex($name);
    }
    return $this->provision($name, FALSE);
  }

  /**
   * Persists the per-site index connection into the real Search API config.
   *
   * Writes the per-site Azure index name onto the search server's database name
   * and the read-only flag onto the search index entity, both on an
   * override-free load so the runtime overrides layered on by
   * YsBeaconConfigOverrides are never baked into the synced config. Persisting
   * (rather than only overriding at runtime) makes the real search_api config
   * authoritative: the Search API admin UI reflects the read-only state, and
   * the chat query targets the configured collection instead of an empty one.
   * The values also live in the config-ignored ys_beacon.settings as the site
   * preference; the search_api copies survive config import because the
   * database-name and read-only keys are config-ignored.
   *
   * @param string $index_name
   *   The Azure AI Search index (collection) name this site connects to. An
   *   empty string clears the connection (Beacon indexing disabled).
   * @param bool $read_only
   *   TRUE when this site borrows the collection and must never write to it.
   */
  public function propagateConnection(string $index_name, bool $read_only): void {
    // Each store is written only when its value actually changes: repeated
    // no-op saves would needlessly invalidate config caches and re-fire the
    // search server's save subscriber. Comparing the wanted value against the
    // persisted one also self-heals drift (for example a manual edit through
    // Search API's own admin UI) on the next save.
    $settings = $this->configFactory->getEditable('ys_beacon.settings');
    if ($settings->get('azure_index_name') !== $index_name
      || (bool) $settings->get('read_only') !== $read_only) {
      $settings->set('azure_index_name', $index_name)
        ->set('read_only', $read_only)
        ->save();
    }

    $server = $this->entityTypeManager->getStorage('search_api_server')->loadOverrideFree($this->searchServerId());
    if ($server) {
      $backend_config = $server->get('backend_config');
      $current = $backend_config['database_settings']['database_name'] ?? NULL;
      if ($current !== $index_name) {
        $backend_config['database_settings']['database_name'] = $index_name;
        $server->set('backend_config', $backend_config)->save();
      }
    }

    $index = $this->entityTypeManager->getStorage('search_api_index')->loadOverrideFree($this->searchIndexId());
    if ($index && $index->isReadOnly() !== $read_only) {
      $index->set('read_only', $read_only)->save();
    }
  }

  /**
   * Pins the resolved Azure AI Search endpoint into the real connection config.
   *
   * Once a site has created an index it should keep talking to that service
   * even if the shared Pantheon secret is later repointed at a new one. The
   * resolved endpoint (the secret-backed key when unpinned) is written onto the
   * real VDB connection config's url key, which is config-ignored so the pin
   * survives config import; YsBeaconConfigOverrides then stops layering the
   * secret over it, so the pinned value stands. When nothing resolves (no
   * secret configured) the site is left unpinned so it keeps resolving from the
   * secret on the next attempt (yalesites-org/YaleSites-Internal#1440).
   *
   * @return bool
   *   TRUE when an endpoint resolved and is now pinned; FALSE when nothing
   *   resolved and the site was left unpinned. Callers that expect a resolvable
   *   endpoint (e.g. the backfill of an already-provisioned site) can surface
   *   the FALSE case rather than skip it silently.
   */
  public function pinSearchUrl(): bool {
    // The immutable read is override-applied: the secret-backed key when
    // unpinned, or the already-pinned value once set.
    $resolved = (string) $this->configFactory->get('ai_vdb_provider_azure_ai_search.settings')->get('url');
    if ($resolved === '') {
      return FALSE;
    }
    // The editable read is override-free: the raw persisted value, empty until
    // this site pins one.
    $connection = $this->configFactory->getEditable('ai_vdb_provider_azure_ai_search.settings');
    if ((string) $connection->get('url') !== $resolved) {
      $connection->set('url', $resolved)->save();
    }
    return TRUE;
  }

  /**
   * Ensures an index exists, creating it only when missing.
   *
   * @param string|null $name
   *   The index name, or NULL to use the per-site default.
   * @param bool $enforce_capacity
   *   Whether to check the service index limit before creating a missing index.
   *
   * @return string
   *   The (existing or newly created) index name.
   *
   * @throws \RuntimeException
   *   When the connection is unconfigured or the index cannot be created.
   */
  public function ensureIndex(?string $name = NULL, bool $enforce_capacity = TRUE): string {
    $name = $name ?: $this->getDefaultIndexName();
    if ($this->indexExists($name)) {
      // Adopt an index that already exists on the target service rather than
      // recreating it (createIndex() would fail on it anyway). Logged so the
      // adoption is traceable: for a first-time provision it can mean the
      // endpoint now resolves to a service that already holds this index; for a
      // borrow-to-writable switch it is the expected, already-present
      // collection. Kept at notice level so the expected case is not alarming
      // (yalesites-org/YaleSites-Internal#1440).
      $this->logger->notice('Azure AI Search index @name already exists on the target service; adopting it as-is.', ['@name' => $name]);
      return $name;
    }
    // Refuse to create once the shared service is full so the failure is a
    // clear, actionable log entry rather than an opaque Azure rejection.
    if ($enforce_capacity) {
      $this->assertCapacity();
    }
    $this->createIndex($name);
    $this->logger->notice('Created Azure AI Search index @name.', ['@name' => $name]);
    return $name;
  }

  /**
   * Builds the default per-site index name.
   *
   * Uses the Pantheon site name and environment when available, falling back
   * to a site-UUID-based name elsewhere (local development). In the single
   * index-per-site model this is also the site's identity, so it delegates to
   * getSiteId().
   *
   * @return string
   *   A valid Azure AI Search index name, unique per site and environment.
   */
  public function getDefaultIndexName(): string {
    return $this->getSiteId();
  }

  /**
   * Returns the stable per-site key that isolates this site's documents.
   *
   * This is the single source of truth for "which Beacon site is this",
   * derived from the Pantheon site name and environment (falling back to a
   * site-UUID slug in local development). The Beacon Azure VDB provider stamps
   * it onto every document and scopes reads/deletes by it, so multiple sites
   * can safely share one Azure collection. It is distinct from the collection
   * name (which may be shared): a site's identity never changes when it starts
   * writing into a shared index.
   *
   * @return string
   *   The per-site key, sanitized to the Azure name charset (lowercase
   *   letters, digits and dashes) so it is safe to embed in OData filters.
   */
  public function getSiteId(): string {
    $site = getenv('PANTHEON_SITE_NAME') ?: '';
    $env = getenv('PANTHEON_ENVIRONMENT') ?: '';
    $name = trim($site . '-' . $env, '-');
    if ($name === '') {
      $uuid = (string) $this->configFactory->get('system.site')->get('uuid');
      $name = 'beacon-' . substr($uuid, 0, 8);
    }
    return static::sanitizeIndexName($name);
  }

  /**
   * Sanitizes a string into a valid Azure AI Search index name.
   *
   * Azure index names may only contain lowercase letters, digits and dashes,
   * cannot start or end with dashes, and are limited to 128 characters.
   *
   * @param string $name
   *   The raw name.
   *
   * @return string
   *   The sanitized name.
   */
  public static function sanitizeIndexName(string $name): string {
    $name = strtolower($name);
    $name = preg_replace('/[^a-z0-9-]+/', '-', $name);
    $name = preg_replace('/-{2,}/', '-', $name);
    $name = trim($name, '-');
    return rtrim(substr($name, 0, self::MAX_NAME_LENGTH), '-');
  }

  /**
   * Checks whether an index exists.
   *
   * @param string $name
   *   The index name.
   *
   * @return bool
   *   TRUE when the index exists.
   *
   * @throws \RuntimeException
   *   When the connection is unconfigured or the service is unreachable.
   */
  public function indexExists(string $name): bool {
    try {
      $this->request('GET', "/indexes('$name')");
      return TRUE;
    }
    catch (ClientException $e) {
      if ($e->getResponse()->getStatusCode() === 404) {
        return FALSE;
      }
      throw new \RuntimeException('Azure AI Search returned an error checking for the index: ' . $e->getMessage(), 0, $e);
    }
    catch (\Throwable $e) {
      throw new \RuntimeException('Azure AI Search is unreachable: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Fails when the target service has no room for another index.
   *
   * A single Azure AI Search service caps how many indexes it can hold, so
   * before creating one Beacon checks the current index count against the
   * configured limit. When the service is full it logs a clear, referenceable
   * error - pointing at the fix (provision a new service and update the
   * Pantheon secret) - and throws, so the caller persists nothing
   * (yalesites-org/YaleSites-Internal#1440).
   *
   * @throws \RuntimeException
   *   When the service is at or over the configured index limit, or the index
   *   count cannot be read.
   */
  protected function assertCapacity(): void {
    $count = $this->countIndexes();
    $limit = $this->maxIndexes();
    if ($count >= $limit) {
      $this->logger->error('Azure AI Search service is at capacity: @count of @limit indexes exist, so a new Beacon index cannot be created. Provision a new Azure AI Search service and update the "azure_ai_search_url" Pantheon secret so sites without an index create it on the new service.', [
        '@count' => $count,
        '@limit' => $limit,
      ]);
      throw new \RuntimeException(sprintf('Azure AI Search service is at capacity (%d of %d indexes); cannot create a new index.', $count, $limit));
    }
  }

  /**
   * Counts the indexes that currently exist on the target service.
   *
   * @return int
   *   The number of indexes on the service.
   *
   * @throws \RuntimeException
   *   When the connection is unconfigured or the service cannot be listed.
   */
  public function countIndexes(): int {
    try {
      $response = $this->request('GET', '/indexes');
    }
    catch (\Throwable $e) {
      throw new \RuntimeException('Azure AI Search returned an error listing indexes: ' . $e->getMessage(), 0, $e);
    }
    // Azure AI Search returns the index list under a top-level "value" array. A
    // 2xx that lacks it is not a real index listing (e.g. a proxy/SSO HTML page
    // from a mis-repointed endpoint), so fail closed rather than treat it as an
    // empty service - the latter would bypass the capacity guard entirely.
    if (!is_array($response['value'] ?? NULL)) {
      throw new \RuntimeException('Azure AI Search returned an unexpected response listing indexes; refusing to assume the service is empty.');
    }
    return count($response['value']);
  }

  /**
   * The maximum number of indexes allowed on the target service.
   *
   * Reads the site override (ys_beacon.settings:max_indexes) when set, falling
   * back to the shipped default so the limit lives in a single, easily updated
   * place (yalesites-org/YaleSites-Internal#1440).
   *
   * @return int
   *   The index limit.
   */
  protected function maxIndexes(): int {
    $configured = $this->configFactory->get('ys_beacon.settings')->get('max_indexes');
    return is_numeric($configured) ? (int) $configured : self::DEFAULT_MAX_INDEXES;
  }

  /**
   * Creates an index with the Beacon field schema.
   *
   * Uses POST /indexes, which fails when the index already exists, so an
   * existing index can never be modified by this call.
   *
   * @param string $name
   *   The index name.
   *
   * @throws \RuntimeException
   *   When creation fails.
   */
  protected function createIndex(string $name): void {
    try {
      $this->request('POST', '/indexes', $this->buildIndexSchema($name));
    }
    catch (\Throwable $e) {
      // 409 means the index appeared between the existence check and the
      // create call: treat as success, the index exists.
      if ($e instanceof ClientException && $e->getResponse()->getStatusCode() === 409) {
        return;
      }
      throw new \RuntimeException('Azure AI Search index creation failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Deletes an index.
   *
   * @param string $name
   *   The index name.
   *
   * @throws \RuntimeException
   *   When deletion fails.
   */
  protected function deleteIndex(string $name): void {
    try {
      $this->request('DELETE', "/indexes('$name')");
      $this->logger->notice('Deleted Azure AI Search index @name.', ['@name' => $name]);
    }
    catch (\Throwable $e) {
      throw new \RuntimeException('Azure AI Search index deletion failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Builds the index schema expected by the AI Search backend.
   *
   * Field set matches the Azure VDB provider documentation template plus the
   * "vector" field the AI Search backend writes embeddings to. This contract
   * is pinned against drupal/ai 1.4.2 and ai_vdb_provider_azure_ai_search
   * 1.1.0-beta2: AiVdbProviderClientBase::insertIntoCollection() writes these
   * document keys and AzureAiSearch::query() searches the field named
   * "vector". Re-verify when either module is updated.
   *
   * @param string $name
   *   The index name.
   *
   * @return array
   *   The index definition.
   */
  protected function buildIndexSchema(string $name): array {
    $string_field = static fn (string $field_name): array => [
      'name' => $field_name,
      'type' => 'Edm.String',
      'key' => FALSE,
      'retrievable' => TRUE,
      'searchable' => TRUE,
      'filterable' => TRUE,
      'sortable' => TRUE,
      'facetable' => TRUE,
    ];

    // Retrievable-only string field base: returned but not searched, filtered,
    // sorted, or faceted on. content overrides searchable to TRUE (below) for
    // portal keyword debugging.
    $retrievable_field = static fn (string $field_name): array => [
      'name' => $field_name,
      'type' => 'Edm.String',
      'key' => FALSE,
      'retrievable' => TRUE,
      'searchable' => FALSE,
      'filterable' => FALSE,
      'sortable' => FALSE,
      'facetable' => FALSE,
    ];

    return [
      'name' => $name,
      'fields' => [
        ['key' => TRUE] + $string_field('id'),
        $string_field('drupal_entity_id'),
        $string_field('drupal_long_id'),
        $string_field('index_id'),
        $string_field('server_id'),
        // Beacon-owned per-site key (not a contrib document field). The
        // beacon_azure_ai_search provider writes it on insert and scopes
        // reads/deletes by it so multiple sites can share one collection.
        $string_field('site_id'),
        // Chunk text: retrievable, and searchable so it can be keyword-searched
        // in the Azure portal for debugging. Not filtered, sorted, or faceted;
        // matching full chunk text is not useful.
        // See yalesites-org/YaleSites-Internal#1439.
        ['searchable' => TRUE] + $retrievable_field('content'),
        // Title and absolute URL stored per document so a site querying
        // a shared collection can cite content whose Drupal entity does
        // not exist locally. Fully queryable (search/filter/sort/facet)
        // so a page's chunks can be found and grouped in the Azure portal
        // (yalesites-org/YaleSites-Internal#1439).
        $string_field('citation_title'),
        $string_field('citation_url'),
        // Last-indexed timestamp, stamped on every insert (ISO 8601 UTC)
        // by the beacon_azure_ai_search provider so chunks can be sorted
        // and filtered by index freshness in the Azure portal
        // (yalesites-org/YaleSites-Internal#1434).
        [
          'name' => 'updated_at',
          'type' => 'Edm.DateTimeOffset',
          'key' => FALSE,
          'retrievable' => TRUE,
          'searchable' => FALSE,
          'filterable' => TRUE,
          'sortable' => TRUE,
          'facetable' => FALSE,
        ],
        [
          'name' => 'vector',
          'type' => 'Collection(Edm.Single)',
          'retrievable' => TRUE,
          'searchable' => TRUE,
          'filterable' => FALSE,
          'sortable' => FALSE,
          'facetable' => FALSE,
          'dimensions' => $this->getEmbeddingDimensions(),
          'vectorSearchProfile' => 'beacon-vector-profile',
        ],
      ],
      'vectorSearch' => [
        'algorithms' => [
          ['name' => 'beacon-hnsw', 'kind' => 'hnsw'],
        ],
        'profiles' => [
          ['name' => 'beacon-vector-profile', 'algorithm' => 'beacon-hnsw'],
        ],
      ],
    ];
  }

  /**
   * Reads the embedding dimensions from the Beacon search server config.
   *
   * @return int
   *   The vector dimensions; must match the embedding model output.
   */
  protected function getEmbeddingDimensions(): int {
    $dimensions = $this->configFactory
      ->get('search_api.server.' . $this->searchServerId())
      ->get('backend_config.embeddings_engine_configuration.dimensions');
    return (int) ($dimensions ?: 1536);
  }

  /**
   * The Search API index machine name backing the chatbot.
   */
  protected function searchIndexId(): string {
    return $this->configFactory->get('ys_beacon.settings')->get('search_index_id') ?: 'ys_beacon';
  }

  /**
   * The Search API server machine name backing the chatbot.
   */
  protected function searchServerId(): string {
    return $this->configFactory->get('ys_beacon.settings')->get('search_server_id') ?: 'ys_beacon';
  }

  /**
   * Performs a request against the Azure AI Search management API.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $path
   *   The path relative to the service endpoint.
   * @param array|null $json
   *   An optional JSON body.
   *
   * @return array
   *   The decoded response body.
   *
   * @throws \RuntimeException
   *   When the connection settings are incomplete.
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   On request failure.
   */
  protected function request(string $method, string $path, ?array $json = NULL): array {
    $settings = $this->configFactory->get('ai_vdb_provider_azure_ai_search.settings');
    $url = rtrim((string) $settings->get('url'), '/');
    $api_version = (string) $settings->get('api_version');
    $key_id = (string) $settings->get('api_key');
    $api_key = $key_id ? $this->keyRepository->getKey($key_id)?->getKeyValue() : NULL;

    if (!$url || !$api_key) {
      throw new \RuntimeException('The Azure AI Search connection is not configured: endpoint URL or API key is missing.');
    }

    $options = [
      'headers' => [
        'Content-Type' => 'application/json',
        'api-key' => $api_key,
      ],
      'query' => ['api-version' => $api_version ?: '2023-11-01'],
      'timeout' => 15,
    ];
    if ($json !== NULL) {
      $options['json'] = $json;
    }

    $response = $this->httpClient->request($method, $url . $path, $options);
    return json_decode((string) $response->getBody(), TRUE) ?? [];
  }

}
