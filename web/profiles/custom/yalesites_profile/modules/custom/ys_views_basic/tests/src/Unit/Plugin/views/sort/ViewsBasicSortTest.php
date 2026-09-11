<?php

namespace Drupal\Tests\ys_views_basic\Unit\Plugin\views\sort;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Plugin\views\sort\ViewsBasicSort;

/**
 * Unit tests for the ViewsBasicSort views sort plugin.
 *
 * The plugin reads the "sort_by" contextual argument (view->args[3], set by
 * ViewsBasicManager::setupView()) and adds ORDER BY clauses: sticky first,
 * then the requested field, then node ID as a stable tiebreaker.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Plugin\views\sort\ViewsBasicSort
 * @group ys_views_basic
 * @group yalesites
 */
class ViewsBasicSortTest extends UnitTestCase {

  /**
   * Builds a sort plugin instance wired to a mock view and query.
   *
   * @param array $args
   *   The view's contextual arguments.
   *
   * @return array
   *   [0] => the plugin, [1] => the mock query.
   */
  protected function createSortWithArgs(array $args): array {
    $query = $this->getMockBuilder('Drupal\views\Plugin\views\query\Sql')
      ->disableOriginalConstructor()
      ->onlyMethods(['addTable', 'addOrderBy', 'ensureTable'])
      ->getMock();
    $query->method('ensureTable')->willReturn('node_field_data');
    $query->method('addTable')->willReturnArgument(0);

    $sort = new ViewsBasicSort([], 'views_basic_sort', []);
    $sort->view = (object) ['args' => $args];
    $sort->query = $query;

    return [$sort, $query];
  }

  /**
   * A field-backed sort joins the field table and orders sticky, field, nid.
   *
   * @covers ::query
   */
  public function testQueryWithFieldSortOrdersStickyFieldThenNid() {
    [$sort, $query] = $this->createSortWithArgs([NULL, NULL, NULL, 'field_event_date:DESC']);

    $query->expects($this->once())
      ->method('addTable')
      ->with('node__field_event_date')
      ->willReturn('node__field_event_date');

    $calls = [];
    $query->method('addOrderBy')->willReturnCallback(function (...$args) use (&$calls) {
      $calls[] = $args;
    });

    $sort->query();

    // addOrderBy() takes a trailing $params array that the plugin never
    // sets; only the first four (meaningful) arguments are compared.
    $this->assertSame([NULL, 'node_field_data.sticky', 'DESC', 'views_basic_sort'], array_slice($calls[0], 0, 4));
    $this->assertSame([NULL, 'node__field_event_date.field_event_date_value', 'DESC', 'views_basic_sort'], array_slice($calls[1], 0, 4));
    $this->assertSame([NULL, 'node_field_data.nid', 'DESC', 'views_basic_sort'], array_slice($calls[2], 0, 4));
  }

  /**
   * A non-field sort key (e.g. "title") is used as-is, with no table join.
   *
   * @covers ::query
   */
  public function testQueryWithNonFieldSortUsesRawFieldName() {
    [$sort, $query] = $this->createSortWithArgs([NULL, NULL, NULL, 'title:ASC']);

    $query->expects($this->never())->method('addTable');

    $calls = [];
    $query->method('addOrderBy')->willReturnCallback(function (...$args) use (&$calls) {
      $calls[] = $args;
    });

    $sort->query();

    $this->assertSame([NULL, 'title', 'ASC', 'views_basic_sort'], array_slice($calls[1], 0, 4));
  }

  /**
   * With no sort_by argument, no ORDER BY clauses are added at all.
   *
   * @covers ::query
   */
  public function testQueryWithoutSortArgumentAddsNoOrdering() {
    [$sort, $query] = $this->createSortWithArgs([]);

    $query->expects($this->never())->method('addOrderBy');

    $sort->query();
  }

}
