<?php

namespace Drupal\Tests\ys_views_basic\Unit\Plugin\views\pager;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Plugin\views\pager\ViewsBasicFullPager;

/**
 * Unit tests for the ViewsBasicFullPager views pager plugin.
 *
 * The pager reads its items-per-page (view->args[5]) and offset
 * (view->args[7]) from contextual arguments set by
 * ViewsBasicManager::setupView(), and clamps the query to the view's
 * "total_pages" option once the requested page runs past it.
 *
 * @coversDefaultClass \Drupal\ys_views_basic\Plugin\views\pager\ViewsBasicFullPager
 * @group ys_views_basic
 * @group yalesites
 */
class ViewsBasicFullPagerTest extends UnitTestCase {

  /**
   * Builds a pager plugin instance wired to a mock view and query.
   *
   * @param array $args
   *   The view's contextual arguments.
   * @param array $options
   *   The pager's options (e.g. offset, total_pages).
   * @param int $currentPage
   *   The pager's current_page value.
   *
   * @return array
   *   [0] => the plugin, [1] => the mock view query.
   */
  protected function createPager(array $args, array $options = [], int $currentPage = 0): array {
    $query = $this->getMockBuilder('Drupal\views\Plugin\views\query\Sql')
      ->disableOriginalConstructor()
      ->onlyMethods(['setLimit', 'setOffset'])
      ->getMock();

    $pager = new ViewsBasicFullPager(
      [],
      'views_basic_full_pager',
      [],
      $this->createMock('Drupal\Core\Pager\PagerManagerInterface'),
      $this->createMock('Drupal\Core\Pager\PagerParametersInterface')
    );
    $pager->view = (object) ['args' => $args, 'query' => $query];
    $pager->options = $options + ['items_per_page' => 10, 'offset' => 0];
    $pager->current_page = $currentPage;

    return [$pager, $query];
  }

  /**
   * Without an items-per-page argument, query() does nothing.
   *
   * @covers ::query
   * @covers ::hasItemsPerPage
   */
  public function testQueryDoesNothingWithoutItemsPerPageArgument() {
    [$pager, $query] = $this->createPager([]);

    $query->expects($this->never())->method('setLimit');
    $query->expects($this->never())->method('setOffset');

    $pager->query();
  }

  /**
   * Query() sets the limit/offset from the items-per-page argument.
   *
   * @covers ::query
   */
  public function testQuerySetsLimitAndOffsetFromArguments() {
    // args[5] = items per page, args[7] = offset.
    $args = [NULL, NULL, NULL, NULL, NULL, '20', NULL, '5'];
    [$pager, $query] = $this->createPager($args, ['items_per_page' => 20, 'offset' => 5]);

    $query->expects($this->once())->method('setLimit')->with(20);
    $query->expects($this->once())->method('setOffset')->with(5);

    $pager->query();
  }

  /**
   * Once past the last page, the query is clamped to the final page's bounds.
   *
   * @covers ::query
   * @covers ::pastLastPage
   * @covers ::hasTotalPages
   */
  public function testQueryClampsToTotalPagesOncePastTheLastPage() {
    $args = [NULL, NULL, NULL, NULL, NULL, '10', NULL, '0'];
    [$pager, $query] = $this->createPager(
      $args,
      ['items_per_page' => 10, 'offset' => 0, 'total_pages' => 3],
      // current_page (3) >= total_pages (3): past the last page.
      3
    );

    // Limit stays at items_per_page; offset becomes total_pages *
    // items_per_page.
    $query->expects($this->once())->method('setLimit')->with(10);
    $query->expects($this->once())->method('setOffset')->with(30);

    $pager->query();
  }

  /**
   * ItemsPerPage()/offset() cast their view arguments to integers.
   *
   * @covers ::itemsPerPage
   * @covers ::offset
   */
  public function testItemsPerPageAndOffsetCastArgumentsToInt() {
    $args = [NULL, NULL, NULL, NULL, NULL, '15', NULL, '3'];
    [$pager] = $this->createPager($args);

    $reflection = new \ReflectionClass($pager);
    $itemsPerPage = $reflection->getMethod('itemsPerPage');
    $itemsPerPage->setAccessible(TRUE);
    $offset = $reflection->getMethod('offset');
    $offset->setAccessible(TRUE);

    $this->assertSame(15, $itemsPerPage->invoke($pager));
    $this->assertSame(3, $offset->invoke($pager));
  }

  /**
   * HasTotalPages() is FALSE when the option is unset or empty.
   *
   * @covers ::hasTotalPages
   */
  public function testHasTotalPagesFalseWhenUnset() {
    [$pager] = $this->createPager([]);

    $reflection = new \ReflectionClass($pager);
    $method = $reflection->getMethod('hasTotalPages');
    $method->setAccessible(TRUE);

    $this->assertFalse($method->invoke($pager));
  }

}
