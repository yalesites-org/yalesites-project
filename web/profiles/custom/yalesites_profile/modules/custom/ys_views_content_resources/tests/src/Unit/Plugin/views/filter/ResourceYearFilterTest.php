<?php

namespace Drupal\Tests\ys_views_content_resources\Unit\Plugin\views\filter;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_content_resources\Plugin\views\filter\ResourceYearFilter;

/**
 * Unit tests for the ResourceYearFilter views filter plugin's query().
 *
 * GenerateYearOptions() (the DB-backed options callback) needs a real
 * database and node data to exercise meaningfully; see the Kernel test for
 * that method.
 *
 * @coversDefaultClass \Drupal\ys_views_content_resources\Plugin\views\filter\ResourceYearFilter
 * @group ys_views_content_resources
 * @group yalesites
 */
class ResourceYearFilterTest extends UnitTestCase {

  /**
   * Builds a filter plugin instance wired to a mock view query.
   *
   * @param array $value
   *   The filter's selected value(s).
   * @param bool $multiple
   *   Whether the exposed filter allows multiple values.
   *
   * @return array
   *   [0] => the filter, [1] => the mock query.
   */
  protected function createFilter(array $value, bool $multiple = FALSE): array {
    $query = $this->getMockBuilder('Drupal\views\Plugin\views\query\Sql')
      ->disableOriginalConstructor()
      ->onlyMethods(['addTable', 'addWhereExpression', 'ensureTable'])
      ->getMock();
    $query->method('ensureTable')->willReturn('node__field_publish_date');
    $query->method('addTable')->willReturn('node__field_publish_date');

    $connection = $this->createMock(Connection::class);
    $cache = $this->createMock(CacheBackendInterface::class);

    $filter = new ResourceYearFilter([], 'resource_year_filter', [], $connection, $cache);
    $filter->value = $value;
    $filter->query = $query;
    $filter->options = ['group' => 0, 'expose' => ['multiple' => $multiple]];

    return [$filter, $query];
  }

  /**
   * No query condition is added when no year value is selected.
   *
   * @covers ::query
   */
  public function testQueryDoesNothingWithEmptyValue() {
    [$filter, $query] = $this->createFilter([]);

    $query->expects($this->never())->method('addTable');
    $query->expects($this->never())->method('addWhereExpression');

    $filter->query();
  }

  /**
   * A single value adds one LIKE condition against the publish date field.
   *
   * @covers ::query
   */
  public function testQueryWithSingleValueAddsLikeCondition() {
    [$filter, $query] = $this->createFilter(['2023']);

    $query->expects($this->once())
      ->method('addTable')
      ->with('node__field_publish_date')
      ->willReturn('node__field_publish_date');
    $query->expects($this->once())
      ->method('addWhereExpression')
      ->with(
        0,
        'node__field_publish_date.field_publish_date_value LIKE :year',
        [':year' => '2023%']
      );

    $filter->query();
  }

  /**
   * Multiple values are OR'd together, each with its own placeholder.
   *
   * @covers ::query
   */
  public function testQueryWithMultipleValuesOrsLikeConditions() {
    [$filter, $query] = $this->createFilter(['2023', '2021'], TRUE);

    $query->expects($this->once())
      ->method('addWhereExpression')
      ->with(
        0,
        'node__field_publish_date.field_publish_date_value LIKE :year_0 OR node__field_publish_date.field_publish_date_value LIKE :year_1',
        [':year_0' => '2023%', ':year_1' => '2021%']
      );

    $filter->query();
  }

}
