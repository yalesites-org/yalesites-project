<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;

require_once __DIR__ . '/../../../ys_core.deploy.php';

/**
 * Tests which indexes the deploy-time reindex repair picks up.
 *
 * Selecting one index too many costs every site a full reindex, so each case
 * here is shaped to fail for a different reason. Fixtures deliberately carry
 * several fields with the qualifying one last, because that is the shape of the
 * real config - `field_settings` is alphabetical, so `rendered_item` is the
 * third key on node_index - and a single-field fixture cannot tell a scan from
 * a test of the first field only. .deploy.php is not autoloaded, hence the
 * require_once above.
 *
 * @group ys_core
 * @group yalesites
 *
 * @see yalesites-org/YaleSites-Internal#1727
 */
class SearchIndexRerenderSelectionTest extends UnitTestCase {

  /**
   * Builds an index mock with the given status and fields.
   *
   * @param bool $status
   *   Whether the index is enabled.
   * @param array $fields
   *   Fields keyed by field ID. Each value is either a property path (with no
   *   datasource, as a processor-provided field has) or a
   *   [property path, datasource ID] pair.
   * @param bool $read_only
   *   Whether the index is read-only.
   *
   * @return \Drupal\search_api\IndexInterface
   *   The mocked index.
   */
  protected function index(bool $status, array $fields, bool $read_only = FALSE): IndexInterface {
    $instances = [];
    foreach ($fields as $field_id => $spec) {
      [$property_path, $datasource_id] = is_array($spec) ? $spec : [$spec, NULL];
      $field = $this->createMock(FieldInterface::class);
      $field->method('getPropertyPath')->willReturn($property_path);
      $field->method('getDatasourceId')->willReturn($datasource_id);
      $instances[$field_id] = $field;
    }

    $index = $this->createMock(IndexInterface::class);
    $index->method('status')->willReturn($status);
    $index->method('isReadOnly')->willReturn($read_only);
    $index->method('getFields')->willReturn($instances);

    return $index;
  }

  /**
   * A read-only index is skipped, because nothing would ever clear the mark.
   *
   * Index::reindex() does not check read_only, but cron tracks read-only
   * indexes without indexing them, so marking one would queue every item
   * permanently and still log the index as repaired.
   */
  public function testReadOnlyIndexIsSkipped(): void {
    $indexes = [
      'frozen_index' => $this->index(TRUE, [
        'title' => 'title',
        'rendered_item' => 'rendered_item',
      ], TRUE),
    ];

    $this->assertSame([], ys_core_search_indexes_needing_rerender($indexes));
  }

  /**
   * Every field is scanned, and the match is on path rather than field ID.
   *
   * Fails if the selection stops at the first field, or if it matches the
   * array key instead of the property path.
   */
  public function testRenamedFieldIsStillMatchedByPropertyPath(): void {
    $indexes = [
      'node_index' => $this->index(TRUE, [
        'field_teaser_text' => 'field_teaser_text',
        'node_grants' => 'search_api_node_grants',
        'whole_page' => 'rendered_item',
      ]),
    ];

    $this->assertSame(
      ['node_index'],
      array_keys(ys_core_search_indexes_needing_rerender($indexes))
    );
  }

  /**
   * A datasource-scoped field is a different field, despite the shared path.
   *
   * RenderedItem only defines the rendered_item property when there is no
   * datasource, so this index renders no whole pages and needs no repair.
   */
  public function testDatasourceScopedFieldIsNotTheRenderedItemProperty(): void {
    $indexes = [
      'odd_index' => $this->index(TRUE, [
        'title' => 'title',
        'rendered_item' => ['rendered_item', 'entity:node'],
      ]),
    ];

    $this->assertSame([], ys_core_search_indexes_needing_rerender($indexes));
  }

  /**
   * Only enabled, page-rendering indexes come back, keys and order preserved.
   *
   * Fails if the status guard goes, or if an index with no rendered_item field
   * is selected.
   */
  public function testOnlyQualifyingIndexesAreReturned(): void {
    $indexes = [
      'node_index' => $this->index(TRUE, [
        'field_teaser_text' => 'field_teaser_text',
        'rendered_item' => 'rendered_item',
        'type' => 'type',
      ]),
      'title_only' => $this->index(TRUE, [
        'title' => 'title',
        'type' => 'type',
      ]),
      'ys_beacon' => $this->index(TRUE, [
        'ai_tags' => 'ys_beacon_ai_tags',
        'rendered_item' => 'rendered_item',
      ]),
      'disabled_index' => $this->index(FALSE, [
        'rendered_item' => 'rendered_item',
      ]),
    ];

    $this->assertSame(
      ['node_index', 'ys_beacon'],
      array_keys(ys_core_search_indexes_needing_rerender($indexes))
    );
  }

}
