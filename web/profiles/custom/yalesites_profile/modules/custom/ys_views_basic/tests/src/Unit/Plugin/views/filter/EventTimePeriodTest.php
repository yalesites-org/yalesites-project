<?php

namespace Drupal\Tests\ys_views_basic\Unit\Plugin\views\filter;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Plugin\views\filter\EventTimePeriod;

/**
 * Unit tests for the EventTimePeriod views filter plugin.
 *
 * The plugin reads the "event time period" contextual argument
 * (view->args[6], set by ViewsBasicManager::setupView()) and adds a query
 * condition comparing the event end date against the current time.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Plugin\views\filter\EventTimePeriod
 * @group ys_views_basic
 * @group yalesites
 */
class EventTimePeriodTest extends UnitTestCase {

  /**
   * Builds a filter plugin instance wired to a mock view and query.
   *
   * @param array $args
   *   The view's contextual arguments.
   *
   * @return array
   *   [0] => the plugin, [1] => the mock query.
   */
  protected function createFilterWithArgs(array $args): array {
    $query = $this->getMockBuilder('Drupal\views\Plugin\views\query\Sql')
      ->disableOriginalConstructor()
      ->onlyMethods(['addTable', 'addWhere', 'ensureTable'])
      ->getMock();
    $query->method('ensureTable')->willReturn('node__field_event_date');

    $filter = new EventTimePeriod([], 'event_time_period', []);
    $filter->view = (object) ['args' => $args];
    $filter->query = $query;
    $filter->options = ['group' => 0];

    return [$filter, $query];
  }

  /**
   * No query condition is added when the time period argument is absent.
   *
   * @covers ::query
   */
  public function testQueryDoesNothingWithoutTimePeriodArgument() {
    [$filter, $query] = $this->createFilterWithArgs([]);

    $query->expects($this->never())->method('addTable');
    $query->expects($this->never())->method('addWhere');

    $filter->query();
  }

  /**
   * The 'future' value compares the event end date against now with '>='.
   *
   * @covers ::query
   */
  public function testQueryFutureAddsGreaterThanOrEqualCondition() {
    $args = [NULL, NULL, NULL, NULL, NULL, NULL, 'future'];
    [$filter, $query] = $this->createFilterWithArgs($args);

    $query->expects($this->once())
      ->method('addTable')
      ->with('node__field_event_date')
      ->willReturn('node__field_event_date');
    $query->expects($this->once())
      ->method('addWhere')
      ->with(
        0,
        'node__field_event_date.field_event_date_end_value',
        $this->callback(fn($timestamp) => is_int($timestamp) && abs($timestamp - time()) < 10),
        '>='
      );

    $filter->query();
  }

  /**
   * The 'past' value compares the event end date against now with '<'.
   *
   * @covers ::query
   */
  public function testQueryPastAddsLessThanCondition() {
    $args = [NULL, NULL, NULL, NULL, NULL, NULL, 'past'];
    [$filter, $query] = $this->createFilterWithArgs($args);

    $query->expects($this->once())
      ->method('addWhere')
      ->with(
        0,
        $this->anything(),
        $this->anything(),
        '<'
      );

    $filter->query();
  }

  /**
   * Any other value (e.g. 'all') adds no condition.
   *
   * @covers ::query
   */
  public function testQueryUnrecognizedValueAddsNoCondition() {
    $args = [NULL, NULL, NULL, NULL, NULL, NULL, 'all'];
    [$filter, $query] = $this->createFilterWithArgs($args);

    $query->expects($this->never())->method('addWhere');

    $filter->query();
  }

}
