<?php

namespace Drupal\ys_beacon\Plugin\VdbProvider;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\AiVdbProvider;
use Drupal\ai\Base\AiVdbProviderClientBase;
use Drupal\ai_vdb_provider_azure_ai_search\AzureAiSearch;
use Drupal\ai_vdb_provider_azure_ai_search\Plugin\VdbProvider\AzureAiSearchProvider;
use Drupal\key\KeyRepositoryInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\Condition;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\ys_beacon\Service\BeaconIndexManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Site-isolating Azure AI Search provider for a shared Beacon collection.
 *
 * The contrib Azure provider derives one document key per Search API item id
 * and looks documents up by an un-namespaced drupal_entity_id, so two Beacon
 * sites writing node/123 into one Azure collection would clobber each other's
 * documents on write and cross-delete on unpublish. There is no hook in the
 * insert/delete chain to inject a per-site prefix, so Beacon owns this thin
 * subclass instead (a sanctioned AiVdbProvider plugin, not a fork) and keeps
 * the whole Search API + ai_search pipeline.
 *
 * It changes four things and reuses everything else from the parent:
 * - insert: prefixes drupal_long_id (which namespaces the derived Azure key)
 *   and drupal_entity_id with the site key, and stamps a retrievable site_id.
 * - delete/fetch lookup: scopes the id resolution to this site (prefixed
 *   drupal_entity_id AND site_id), so a delete can never resolve another
 *   site's documents.
 * - query filter: emits a valid OData string (the parent emits Mongo-style
 *   operators the Azure REST API rejects) and always AND-scopes it by site_id,
 *   so reads only ever see this site's documents.
 * - read mapping: strips the site prefix off drupal_entity_id on the way back
 *   so Search API resolves the real Drupal entity for access checks/citations.
 *
 * This depends only on the parent's public method signatures; re-verify on any
 * ai / ai_search / ai_vdb_provider_azure_ai_search bump (the lando phpunit
 * --group ys_beacon gate covers the transforms).
 */
#[AiVdbProvider(
  id: 'beacon_azure_ai_search',
  label: new TranslatableMarkup('Beacon Azure AI Search DB'),
)]
class BeaconAzureAiSearchProvider extends AzureAiSearchProvider {

  /**
   * Separates the site key from the original id in a namespaced value.
   *
   * A colon is already present throughout drupal_long_id / drupal_entity_id
   * and is collapsed to an underscore in the derived Azure key, so it keeps
   * the per-site key visibly distinct without introducing a new character.
   */
  protected const SITE_KEY_SEPARATOR = ':';

  /**
   * {@inheritdoc}
   */
  public function __construct(
    string $pluginId,
    mixed $pluginDefinition,
    ConfigFactoryInterface $configFactory,
    KeyRepositoryInterface $keyRepository,
    EventDispatcherInterface $eventDispatcher,
    EntityFieldManagerInterface $entityFieldManager,
    MessengerInterface $messenger,
    AzureAiSearch $azure_ai_search,
    protected BeaconIndexManager $beaconIndexManager,
    protected TimeInterface $time,
  ) {
    parent::__construct(
      $pluginId,
      $pluginDefinition,
      $configFactory,
      $keyRepository,
      $eventDispatcher,
      $entityFieldManager,
      $messenger,
      $azure_ai_search,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): AiVdbProviderClientBase|static {
    return new static(
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('key.repository'),
      $container->get('event_dispatcher'),
      $container->get('entity_field.manager'),
      $container->get('messenger'),
      $container->get('azure_ai_search.api'),
      $container->get('ys_beacon.index_manager'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function insertIntoCollection(
    string $collection_name,
    array $data,
    string $database = 'default',
  ): void {
    parent::insertIntoCollection($collection_name, $this->namespaceDocument($data), $database);
  }

  /**
   * {@inheritdoc}
   */
  public function getVdbIds(
    string $collection_name,
    array $drupalIds,
    string $database = 'default',
  ): array {
    if (!$drupalIds) {
      return [];
    }

    // Resolve the Azure document keys through a site-scoped OData query rather
    // than the parent's un-scoped fetch(), so the delete that follows can only
    // ever touch this site's documents. (The client caps a filter-only query
    // at one Azure result page, exactly like the parent's fetch(); a batch
    // delete spanning more chunks than fit one page would need pagination.)
    $matches = $this->getClient()->query(
      index_name: $database,
      filter: $this->buildSiteScopedFilter($drupalIds),
    );

    $ids = [];
    foreach ($matches as $match) {
      if (!empty($match['metadata']['id'])) {
        $ids[] = $match['metadata']['id'];
      }
    }
    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareFilters(QueryInterface $query): mixed {
    $index = $query->getIndex();
    $conditions = $this->buildConditionFilter($index, $query->getConditionGroup());

    // Skip the per-site read scope when this site is meant to see the whole
    // collection:
    // - a read-only borrow (yalesites-org/YaleSites-Internal#1387): the
    //   collection holds the owner site's key, so scoping by THIS site's key
    //   would match none of it (writers never reach this: read-only sites do
    //   not index);
    // - a site configured to answer from every site's content in a shared
    //   collection (query_entire_index).
    // Writes stay isolated regardless, via the per-site document key.
    if ($index->isReadOnly() || $this->queryEntireIndex()) {
      return $conditions;
    }

    $site_scope = "site_id eq '" . self::escapeOdataLiteral($this->getSiteId()) . "'";
    return $conditions === '' ? $site_scope : $conditions . ' and ' . $site_scope;
  }

  /**
   * {@inheritdoc}
   */
  public function querySearch(
    string $collection_name,
    array $output_fields,
    mixed $filters = [],
    int $limit = 10,
    int $offset = 0,
    string $database = 'default',
  ): array {
    return $this->stripSitePrefix(
      parent::querySearch($collection_name, $output_fields, $filters, $limit, $offset, $database),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function vectorSearch(
    string $collection_name,
    array $vector_input,
    array $output_fields,
    QueryInterface $query,
    string $filters = '',
    int $limit = 10,
    int $offset = 0,
    string $database = 'default',
  ): array {
    return $this->stripSitePrefix(
      parent::vectorSearch($collection_name, $vector_input, $output_fields, $query, $filters, $limit, $offset, $database),
    );
  }

  /**
   * The stable per-site key that isolates this site's documents.
   *
   * @return string
   *   The site key, from BeaconIndexManager (the single source of truth).
   */
  protected function getSiteId(): string {
    return $this->beaconIndexManager->getSiteId();
  }

  /**
   * Whether this site reads the whole shared collection, not just its slice.
   *
   * @return bool
   *   TRUE when the query_entire_index setting is on, so reads are not scoped
   *   to this site's own documents.
   */
  protected function queryEntireIndex(): bool {
    return (bool) $this->configFactory
      ->get('ys_beacon.settings')
      ->get('query_entire_index');
  }

  /**
   * Namespaces a document for this site before it is written.
   *
   * Prefixing drupal_long_id namespaces the Azure key the client derives from
   * it, so two sites' node/123 no longer share a key; prefixing
   * drupal_entity_id scopes the delete/fetch lookup; site_id records the key in
   * a retrievable, filterable column; updated_at records the index-write time
   * in a filterable/sortable date column.
   *
   * @param array $data
   *   The document the ai_search backend assembled.
   *
   * @return array
   *   The namespaced document.
   */
  protected function namespaceDocument(array $data): array {
    $site_id = $this->getSiteId();
    $prefix = $site_id . self::SITE_KEY_SEPARATOR;
    if (isset($data['drupal_long_id'])) {
      $data['drupal_long_id'] = $prefix . $data['drupal_long_id'];
    }
    if (isset($data['drupal_entity_id'])) {
      $data['drupal_entity_id'] = $prefix . $data['drupal_entity_id'];
    }
    $data['site_id'] = $site_id;
    // The single insert path, so updated_at refreshes on every (re)index of
    // the chunk, including cron reindex and reprovision.
    $data['updated_at'] = gmdate('c', $this->time->getCurrentTime());
    return $data;
  }

  /**
   * Builds the OData filter that resolves this site's documents for deletion.
   *
   * @param string[] $drupal_ids
   *   The raw Search API item ids passed to the delete.
   *
   * @return string
   *   An OData filter matching only this site's rows for those ids.
   */
  protected function buildSiteScopedFilter(array $drupal_ids): string {
    $site_id = $this->getSiteId();
    $prefix = $site_id . self::SITE_KEY_SEPARATOR;
    $clauses = [];
    foreach ($drupal_ids as $id) {
      $clauses[] = "drupal_entity_id eq '" . self::escapeOdataLiteral($prefix . $id) . "'";
    }
    return '(' . implode(' or ', $clauses) . ") and site_id eq '" . self::escapeOdataLiteral($site_id) . "'";
  }

  /**
   * Removes this site's prefix from drupal_entity_id in search results.
   *
   * Only this site's own prefix is stripped, so Search API resolves the real
   * Drupal item id for access checks and entity loading.
   *
   * @param array $results
   *   Normalized matches from the parent query.
   *
   * @return array
   *   The results with drupal_entity_id restored to its raw value.
   */
  protected function stripSitePrefix(array $results): array {
    $site_id = $this->getSiteId();
    $entity_prefix = $site_id . self::SITE_KEY_SEPARATOR;
    // The Azure key is derived from the prefixed drupal_long_id with ':' and
    // '/' collapsed to '_', so its site prefix is "<siteId>_". Strip it too so
    // the backend's chunked-result id (drupal_entity_id . ':' . id) matches the
    // non-namespaced form other code expects.
    $key_prefix = $site_id . '_';
    foreach ($results as &$result) {
      if (isset($result['drupal_entity_id']) && str_starts_with($result['drupal_entity_id'], $entity_prefix)) {
        $result['drupal_entity_id'] = substr($result['drupal_entity_id'], strlen($entity_prefix));
      }
      if (isset($result['id']) && str_starts_with($result['id'], $key_prefix)) {
        $result['id'] = substr($result['id'], strlen($key_prefix));
      }
    }
    return $results;
  }

  /**
   * Translates a Search API condition group into an OData filter string.
   *
   * Reimplements the parent's private processConditionGroup() walk to emit the
   * OData string the Azure REST API expects instead of the Mongo-style array
   * it produces. Equality and set membership (the operators meaningful on the
   * string fields Beacon indexes) are translated; comparison operators, which
   * were never valid in the parent's output, are skipped.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The queried index.
   * @param \Drupal\search_api\Query\ConditionGroupInterface $condition_group
   *   The condition group to translate.
   *
   * @return string
   *   An OData filter, or an empty string when there is nothing to filter.
   */
  protected function buildConditionFilter(IndexInterface $index, ConditionGroupInterface $condition_group): string {
    $clauses = [];
    foreach ($condition_group->getConditions() as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        $nested = $this->buildConditionFilter($index, $condition);
        if ($nested !== '') {
          $clauses[] = '(' . $nested . ')';
        }
        continue;
      }
      $clause = $this->buildConditionClause($index, $condition);
      if ($clause !== '') {
        $clauses[] = $clause;
      }
    }

    if (!$clauses) {
      return '';
    }

    $conjunction = strtoupper($condition_group->getConjunction()) === 'OR' ? ' or ' : ' and ';
    $filter = implode($conjunction, $clauses);
    // A disjunction of several clauses must be grouped so it stays correct when
    // AND-combined with the site scope; a conjunction is safe unwrapped.
    return (count($clauses) > 1 && $conjunction === ' or ') ? '(' . $filter . ')' : $filter;
  }

  /**
   * Translates a single Search API condition into an OData clause.
   *
   * @param \Drupal\search_api\IndexInterface $index
   *   The queried index.
   * @param \Drupal\search_api\Query\Condition $condition
   *   The condition to translate.
   *
   * @return string
   *   An OData clause, self-grouped when it is a disjunction, or an empty
   *   string when the operator is not supported.
   */
  protected function buildConditionClause(IndexInterface $index, Condition $condition): string {
    $field = $condition->getField();
    $operator = $condition->getOperator();
    $value = $condition->getValue();
    $values = is_array($value) ? $value : [$value];

    $field_data = $index->getField($field);
    $is_multiple = $field_data ? $this->isMultiple($field_data) : FALSE;

    if ($is_multiple) {
      if (in_array($operator, ['=', 'IN'], TRUE)) {
        return $this->comparison($field, 'eq', $values, 'or');
      }
      // Azure cannot negate across a multi-valued field. Match the contrib
      // provider and warn rather than silently dropping the exclusion.
      $this->messenger->addWarning($this->t('Azure AI Search does not support a negative operator on the multiple-valued field @field.', ['@field' => $field]));
      return '';
    }

    switch ($operator) {
      case '=':
        return $this->comparison($field, 'eq', [$values[0]], 'or');

      case '!=':
        return $this->comparison($field, 'ne', [$values[0]], 'and');

      case 'IN':
        return $this->comparison($field, 'eq', $values, 'or');

      case 'NOT IN':
        return $this->comparison($field, 'ne', $values, 'and');

      default:
        // An operator the OData filter cannot express on the string fields
        // Beacon indexes (e.g. a range). Warn instead of silently dropping it.
        $this->messenger->addWarning($this->t('Operator @operator is not supported by Azure AI Search.', ['@operator' => $operator]));
        return '';
    }
  }

  /**
   * Builds an OData clause matching a field against one or more values.
   *
   * The per-value comparisons are joined by the conjunction and grouped in
   * parentheses when there is more than one, so the clause stays correct when
   * AND-combined with the site scope.
   *
   * @param string $field
   *   The field name.
   * @param string $operator
   *   The OData comparison operator ("eq" or "ne").
   * @param array $values
   *   The values to match.
   * @param string $conjunction
   *   The OData conjunction joining the per-value comparisons ("or" or "and").
   *
   * @return string
   *   The OData clause.
   */
  protected function comparison(string $field, string $operator, array $values, string $conjunction): string {
    $parts = [];
    foreach ($values as $value) {
      $parts[] = $field . ' ' . $operator . " '" . self::escapeOdataLiteral((string) $value) . "'";
    }
    return count($parts) > 1 ? '(' . implode(' ' . $conjunction . ' ', $parts) . ')' : $parts[0];
  }

  /**
   * Escapes a value for embedding in an OData string literal.
   *
   * @param string $value
   *   The raw value.
   *
   * @return string
   *   The value with single quotes doubled per the OData grammar.
   */
  public static function escapeOdataLiteral(string $value): string {
    return str_replace("'", "''", $value);
  }

}
