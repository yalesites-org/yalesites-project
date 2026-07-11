<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\ai_vdb_provider_azure_ai_search\AzureAiSearch;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Query\Condition;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_beacon\Plugin\VdbProvider\BeaconAzureAiSearchProvider;
use Drupal\ys_beacon\Service\BeaconIndexManager;

/**
 * Tests the Beacon Azure AI Search provider's site namespacing.
 *
 * These cover the document/filter transforms that isolate one Beacon site's
 * documents from another's in a shared Azure collection, without touching the
 * live Azure REST client: the site key is stubbed and the pure transforms are
 * exercised directly.
 *
 * @group ys_beacon
 * @coversDefaultClass \Drupal\ys_beacon\Plugin\VdbProvider\BeaconAzureAiSearchProvider
 */
class BeaconAzureAiSearchProviderTest extends UnitTestCase {

  /**
   * Namespacing makes two sites' node/1 distinct in a shared collection.
   *
   * The Azure document key is namespaced via drupal_long_id, and both the
   * drupal_entity_id and a retrievable site_id carry the site key, so the two
   * sites never share a key or a delete-lookup id.
   *
   * @covers ::namespaceDocument
   */
  public function testNamespaceDocumentPrefixesIdsAndStampsSiteId(): void {
    $provider = $this->makeProvider('sitea');

    $data = $this->invoke($provider, 'namespaceDocument', [
      [
        'drupal_long_id' => 'entity:node/1:en:0',
        'drupal_entity_id' => 'entity:node/1:en',
        'index_id' => 'ys_beacon',
        'content' => 'Hello',
      ],
    ]);

    // drupal_long_id is prefixed, so the derived Azure key
    // str_replace([':','/'],'_', ...) becomes "sitea_entity_node_1_en_0",
    // unique per site instead of clobbering.
    $this->assertSame('sitea:entity:node/1:en:0', $data['drupal_long_id']);
    // drupal_entity_id is prefixed so the delete/fetch lookup is scoped.
    $this->assertSame('sitea:entity:node/1:en', $data['drupal_entity_id']);
    // The retrievable site_id column receives the key.
    $this->assertSame('sitea', $data['site_id']);
    // Untouched fields pass through.
    $this->assertSame('Hello', $data['content']);
    $this->assertSame('ys_beacon', $data['index_id']);
  }

  /**
   * The public write entry point stores a namespaced document.
   *
   * Drives insertIntoCollection() end to end (through the parent) against a
   * stubbed client, confirming the document the client stores is namespaced.
   *
   * @covers ::insertIntoCollection
   */
  public function testInsertIntoCollectionWritesNamespacedDocument(): void {
    $captured = NULL;
    $client = $this->createMock(AzureAiSearch::class);
    $client->method('insert')->willReturnCallback(function (array $data, string $index) use (&$captured) {
      $captured = $data;
    });
    $provider = $this->makeProviderWithClient('sitea', $client);

    $provider->insertIntoCollection('', [
      'drupal_long_id' => 'entity:node/1:en:0',
      'drupal_entity_id' => 'entity:node/1:en',
      'index_id' => 'ys_beacon',
      'content' => 'Hi',
    ], 'shared-index');

    $this->assertSame('sitea:entity:node/1:en:0', $captured['drupal_long_id']);
    $this->assertSame('sitea:entity:node/1:en', $captured['drupal_entity_id']);
    $this->assertSame('sitea', $captured['site_id']);
  }

  /**
   * The delete-resolution entry point scopes its lookup to this site.
   *
   * Drives getVdbIds() against a stubbed client, confirming the lookup query is
   * site-scoped and that it returns the resolved Azure keys.
   *
   * @covers ::getVdbIds
   */
  public function testGetVdbIdsResolvesKeysWithSiteScopedFilter(): void {
    $filter = NULL;
    $client = $this->createMock(AzureAiSearch::class);
    $client->method('query')->willReturnCallback(function (string $index, string $filter_arg = '') use (&$filter) {
      $filter = $filter_arg;
      return [
        ['score' => 1, 'metadata' => ['id' => 'sitea_entity_node_1_en_0']],
        ['score' => 1, 'metadata' => ['id' => 'sitea_entity_node_1_en_1']],
      ];
    });
    $provider = $this->makeProviderWithClient('sitea', $client);

    $ids = $provider->getVdbIds('', ['entity:node/1:en'], 'shared-index');

    $this->assertSame(['sitea_entity_node_1_en_0', 'sitea_entity_node_1_en_1'], $ids);
    $this->assertSame(
      "(drupal_entity_id eq 'sitea:entity:node/1:en') and site_id eq 'sitea'",
      $filter,
    );
  }

  /**
   * The delete/fetch lookup filter scopes to this site.
   *
   * It matches on both the prefixed drupal_entity_id and site_id, so it can
   * never resolve another site's documents.
   *
   * @covers ::buildSiteScopedFilter
   */
  public function testBuildSiteScopedFilterScopesToSite(): void {
    $provider = $this->makeProvider('sitea');

    $filter = $this->invoke($provider, 'buildSiteScopedFilter', [
      ['entity:node/1:en', 'entity:node/2:en'],
    ]);

    $this->assertSame(
      "(drupal_entity_id eq 'sitea:entity:node/1:en' or drupal_entity_id eq 'sitea:entity:node/2:en') and site_id eq 'sitea'",
      $filter,
    );
  }

  /**
   * On read, only this site's own prefix is stripped from drupal_entity_id.
   *
   * Result mapping then resolves the real Drupal item id, and a foreign row
   * (which should never appear once site scoping is in place) is left intact.
   *
   * @covers ::stripSitePrefix
   */
  public function testStripSitePrefixOnlyStripsOwnPrefix(): void {
    $provider = $this->makeProvider('sitea');

    $results = $this->invoke($provider, 'stripSitePrefix', [
      [
        [
          'id' => 'sitea_entity_node_1_en_0',
          'drupal_entity_id' => 'sitea:entity:node/1:en',
          'content' => 'A',
          'distance' => 0.9,
        ],
        [
          'id' => 'siteb_entity_node_9_en_0',
          'drupal_entity_id' => 'siteb:entity:node/9:en',
          'content' => 'B',
          'distance' => 0.1,
        ],
      ],
    ]);

    // Own rows are de-namespaced on both the entity id and the Azure key, so
    // the composed chunked-result id matches the non-namespaced form.
    $this->assertSame('entity:node/1:en', $results[0]['drupal_entity_id']);
    $this->assertSame('entity_node_1_en_0', $results[0]['id']);
    // A foreign row (which site scoping should already exclude) is left intact.
    $this->assertSame('siteb:entity:node/9:en', $results[1]['drupal_entity_id']);
    $this->assertSame('siteb_entity_node_9_en_0', $results[1]['id']);
    // Non-id fields are preserved.
    $this->assertSame('A', $results[0]['content']);
    $this->assertSame(0.9, $results[0]['distance']);
  }

  /**
   * A read-only borrow queries the collection as-is, without a site scope.
   *
   * Scoping by this site's key would match none of the owner's documents in
   * the borrowed collection (yalesites-org/YaleSites-Internal#1387).
   *
   * @covers ::prepareFilters
   */
  public function testPrepareFiltersSkipsSiteScopeForReadOnlyBorrow(): void {
    $provider = $this->makeProvider('sitea');

    // No conditions: no filter at all, so the whole borrowed index matches.
    $this->assertSame('', $provider->prepareFilters($this->makeQuery('AND', [], [], TRUE)));

    // With a condition: the condition is emitted, but still no site scope.
    $condition = new Condition('type', 'page', '=');
    $this->assertSame(
      "type eq 'page'",
      $provider->prepareFilters($this->makeQuery('AND', [$condition], ['type' => FALSE], TRUE)),
    );
  }

  /**
   * A single-quote in a value is OData-escaped by doubling it.
   *
   * @covers ::escapeOdataLiteral
   */
  public function testEscapeOdataLiteral(): void {
    $this->assertSame("O''Brien", BeaconAzureAiSearchProvider::escapeOdataLiteral("O'Brien"));
  }

  /**
   * With no conditions the query filter is just the site scope.
   *
   * This is the Beacon RAG case: a valid OData string, not the broken
   * Mongo-style array the contrib provider emits.
   *
   * @covers ::prepareFilters
   */
  public function testPrepareFiltersInjectsSiteScopeWhenNoConditions(): void {
    $provider = $this->makeProvider('sitea');
    $query = $this->makeQuery('AND', []);

    $this->assertSame("site_id eq 'sitea'", $provider->prepareFilters($query));
  }

  /**
   * An equality condition becomes OData, AND-combined with the site scope.
   *
   * This replaces the contrib Mongo-operator output the Azure API rejects.
   *
   * @covers ::prepareFilters
   * @covers ::buildConditionFilter
   */
  public function testPrepareFiltersTranslatesEqualityToOdata(): void {
    $provider = $this->makeProvider('sitea');
    $condition = new Condition('type', 'page', '=');
    $query = $this->makeQuery('AND', [$condition], ['type' => FALSE]);

    $this->assertSame(
      "type eq 'page' and site_id eq 'sitea'",
      $provider->prepareFilters($query),
    );
  }

  /**
   * An IN condition on a single-valued field expands to OR-ed equalities.
   *
   * @covers ::buildConditionFilter
   */
  public function testPrepareFiltersTranslatesInToOdata(): void {
    $provider = $this->makeProvider('sitea');
    $condition = new Condition('bundle', ['page', 'post'], 'IN');
    $query = $this->makeQuery('AND', [$condition], ['bundle' => FALSE]);

    $this->assertSame(
      "(bundle eq 'page' or bundle eq 'post') and site_id eq 'sitea'",
      $provider->prepareFilters($query),
    );
  }

  /**
   * Builds a provider whose getSiteId() is stubbed to a fixed site key.
   */
  private function makeProvider(string $site_id): BeaconAzureAiSearchProvider {
    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->method('getSiteId')->willReturn($site_id);

    $provider = (new \ReflectionClass(BeaconAzureAiSearchProvider::class))->newInstanceWithoutConstructor();
    $property = new \ReflectionProperty($provider, 'beaconIndexManager');
    $property->setAccessible(TRUE);
    $property->setValue($provider, $index_manager);

    return $provider;
  }

  /**
   * Builds a provider with a stubbed site key and a stubbed Azure client.
   *
   * @param string $site_id
   *   The site key getSiteId() should return.
   * @param \Drupal\ai_vdb_provider_azure_ai_search\AzureAiSearch $client
   *   The Azure client getClient() should return.
   *
   * @return \Drupal\ys_beacon\Plugin\VdbProvider\BeaconAzureAiSearchProvider
   *   The provider under test, with only getClient() stubbed.
   */
  private function makeProviderWithClient(string $site_id, AzureAiSearch $client): BeaconAzureAiSearchProvider {
    $index_manager = $this->createMock(BeaconIndexManager::class);
    $index_manager->method('getSiteId')->willReturn($site_id);

    $provider = $this->getMockBuilder(BeaconAzureAiSearchProvider::class)
      ->disableOriginalConstructor()
      ->onlyMethods(['getClient'])
      ->getMock();
    $provider->method('getClient')->willReturn($client);

    $property = new \ReflectionProperty($provider, 'beaconIndexManager');
    $property->setAccessible(TRUE);
    $property->setValue($provider, $index_manager);

    return $provider;
  }

  /**
   * Builds a QueryInterface mock with a condition group and optional fields.
   *
   * @param string $conjunction
   *   The condition group conjunction (AND/OR).
   * @param array $conditions
   *   The conditions (Condition objects or nested ConditionGroupInterface).
   * @param array $multiple
   *   Map of field name => isMultiple bool for fields referenced by conditions;
   *   a field absent here is treated as single-valued.
   * @param bool $read_only
   *   Whether the queried index is a read-only borrow.
   */
  private function makeQuery(string $conjunction, array $conditions, array $multiple = [], bool $read_only = FALSE): QueryInterface {
    $group = $this->createMock(ConditionGroupInterface::class);
    $group->method('getConjunction')->willReturn($conjunction);
    $group->method('getConditions')->willReturn($conditions);

    $index = $this->createMock(IndexInterface::class);
    $index->method('isReadOnly')->willReturn($read_only);
    $index->method('getField')->willReturnCallback(function (string $name) use ($multiple) {
      if (!array_key_exists($name, $multiple)) {
        return NULL;
      }
      $definition = $this->createMock(DataDefinitionInterface::class);
      $definition->method('isList')->willReturn($multiple[$name]);
      $field = $this->createMock(FieldInterface::class);
      $field->method('getPropertyPath')->willReturn($name);
      $field->method('getDatasourceId')->willReturn(NULL);
      $field->method('getDataDefinition')->willReturn($definition);
      return $field;
    });

    $query = $this->createMock(QueryInterface::class);
    $query->method('getIndex')->willReturn($index);
    $query->method('getConditionGroup')->willReturn($group);

    return $query;
  }

  /**
   * Invokes a protected/private method via reflection.
   */
  private function invoke(object $object, string $method, array $args = []) {
    $ref = new \ReflectionMethod($object, $method);
    $ref->setAccessible(TRUE);
    return $ref->invokeArgs($object, $args);
  }

}
