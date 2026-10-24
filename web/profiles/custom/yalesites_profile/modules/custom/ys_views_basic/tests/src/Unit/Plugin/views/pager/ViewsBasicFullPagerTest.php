<?php

namespace Drupal\Tests\ys_views_basic\Unit\Plugin\views\pager;

use Drupal\Core\Pager\Pager;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Pager\PagerParametersInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_views_basic\Plugin\views\pager\ViewsBasicFullPager;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Unit tests for the ViewsBasicFullPager views pager plugin.
 *
 * The pager reads its items-per-page from view->args[5], set by both
 * ViewsBasicManager::setupView() and ViewsContentResourcesManager::setupView().
 * The offset comes from the pager's own "offset" option, which the managers
 * set through ViewExecutable::setOffset(). The query is clamped to the view's
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
   * @param \Drupal\Core\Pager\PagerManagerInterface|null $pagerManager
   *   The pager manager, or NULL for a plain mock.
   * @param \Drupal\Core\Pager\PagerParametersInterface|null $pagerParameters
   *   The pager parameters service, or NULL for a plain mock.
   *
   * @return array
   *   [0] => the plugin, [1] => the mock view query.
   */
  protected function createPager(array $args, array $options = [], int $currentPage = 0, ?PagerManagerInterface $pagerManager = NULL, ?PagerParametersInterface $pagerParameters = NULL): array {
    $query = $this->getMockBuilder('Drupal\views\Plugin\views\query\Sql')
      ->disableOriginalConstructor()
      ->onlyMethods(['setLimit', 'setOffset'])
      ->getMock();

    $pager = new ViewsBasicFullPager(
      [],
      'views_basic_full_pager',
      [],
      $pagerManager ?? $this->createMock(PagerManagerInterface::class),
      $pagerParameters ?? $this->createMock(PagerParametersInterface::class)
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
    // args[5] = items per page; the offset comes from the pager option.
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
   * Content Resources listings keep their offset.
   *
   * ViewsContentResourcesManager has no event_time_period argument, so its
   * args[6] is the offset and args[7] is a JSON string. The pager must not
   * read the offset by position, or a resource listing's offset becomes 0.
   *
   * @covers ::query
   */
  public function testQueryKeepsOffsetOptionForContentResourcesArguments() {
    $args = [
      'resource', 'all', NULL, 'field_publish_date:DESC', 'card', '10', '3',
      '{"show_category":0}',
    ];
    [$pager, $query] = $this->createPager($args, ['items_per_page' => 10, 'offset' => 3]);

    $query->expects($this->once())->method('setLimit')->with(10);
    $query->expects($this->once())->method('setOffset')->with(3);

    $pager->query();
  }

  /**
   * ItemsPerPage() casts its view argument to an integer.
   *
   * @covers ::itemsPerPage
   */
  public function testItemsPerPageCastsArgumentToInt() {
    $args = [NULL, NULL, NULL, NULL, NULL, '15'];
    [$pager] = $this->createPager($args);

    $reflection = new \ReflectionClass($pager);
    $itemsPerPage = $reflection->getMethod('itemsPerPage');
    $itemsPerPage->setAccessible(TRUE);

    $this->assertSame(15, $itemsPerPage->invoke($pager));
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

  /**
   * Builds scaffold view arguments whose stored params use a display mode.
   *
   * @param string $display
   *   The listing's display setting: "pager", "limit" or "all".
   *
   * @return array
   *   The view arguments.
   */
  protected function listingArgs(string $display): array {
    $args = array_fill(0, count(ViewsBasicManager::VIEW_ARGUMENT_ORDER), '');
    $args[ViewsBasicManager::viewArgumentIndex('items')] = '2';
    $args[ViewsBasicManager::viewArgumentIndex('original_settings')] = json_encode([
      'filters' => ['types' => ['post']],
      'sort_by' => 'field_publish_date:DESC',
      'display' => $display,
      'limit' => 2,
    ]);
    return $args;
  }

  /**
   * A listing that does not page leaves the global pager element alone.
   *
   * Every listing runs on pager element 0 unless it pages. If a non-paged
   * listing registered its totals there, a paged listing sharing element 0
   * would render that listing's page count instead of its own: the extra,
   * empty pages reported in yalesites-org/YaleSites-Internal#1612.
   *
   * @covers ::updatePageInfo
   * @dataProvider nonPagedDisplayProvider
   */
  public function testNonPagedListingDoesNotRegisterGlobalPager(string $display): void {
    $pagerManager = $this->createMock(PagerManagerInterface::class);
    $pagerManager->expects($this->never())->method('createPager');
    [$pager] = $this->createPager($this->listingArgs($display), ['items_per_page' => 2, 'id' => 0], 0, $pagerManager);
    $pager->total_items = 19;

    $pager->updatePageInfo();
  }

  /**
   * A paged listing still registers its pager with its own totals.
   *
   * @covers ::updatePageInfo
   */
  public function testPagedListingRegistersGlobalPager(): void {
    $pagerManager = $this->createMock(PagerManagerInterface::class);
    $pagerManager->expects($this->once())
      ->method('createPager')
      ->with(16, 6, 0)
      ->willReturn(new Pager(16, 6, 0));
    [$pager] = $this->createPager($this->listingArgs('pager'), ['items_per_page' => 6, 'id' => 0], 0, $pagerManager);
    $pager->total_items = 16;

    $pager->updatePageInfo();
  }

  /**
   * A listing that does not page always shows its first items.
   *
   * It must not read ?page for element 0, which belongs to another listing
   * when a paged one sits on that element.
   *
   * @covers ::setCurrentPage
   * @dataProvider nonPagedDisplayProvider
   */
  public function testNonPagedListingIgnoresPageQueryArgument(string $display): void {
    $pagerParameters = $this->createMock(PagerParametersInterface::class);
    $pagerParameters->method('findPage')->willReturn(2);
    [$pager] = $this->createPager($this->listingArgs($display), ['id' => 0], 0, NULL, $pagerParameters);

    $pager->setCurrentPage();

    $this->assertSame(0, $pager->getCurrentPage());
  }

  /**
   * A paged listing reads its current page from the query string.
   *
   * @covers ::setCurrentPage
   */
  public function testPagedListingReadsPageQueryArgument(): void {
    $pagerParameters = $this->createMock(PagerParametersInterface::class);
    $pagerParameters->method('findPage')->willReturn(2);
    [$pager] = $this->createPager($this->listingArgs('pager'), ['id' => 0], 0, NULL, $pagerParameters);

    $pager->setCurrentPage();

    $this->assertSame(2, $pager->getCurrentPage());
  }

  /**
   * Without usable stored params, the pager keeps core's behavior.
   *
   * Content Resources listings put other values at these argument positions.
   *
   * @covers ::setCurrentPage
   */
  public function testListingWithoutStoredParamsReadsPageQueryArgument(): void {
    $pagerParameters = $this->createMock(PagerParametersInterface::class);
    $pagerParameters->method('findPage')->willReturn(2);
    [$pager] = $this->createPager(['resource', 'all'], ['id' => 0], 0, NULL, $pagerParameters);

    $pager->setCurrentPage();

    $this->assertSame(2, $pager->getCurrentPage());
  }

  /**
   * Display settings for listings that do not page.
   */
  public static function nonPagedDisplayProvider(): array {
    return [
      'limit' => ['limit'],
      'all' => ['all'],
    ];
  }

}
