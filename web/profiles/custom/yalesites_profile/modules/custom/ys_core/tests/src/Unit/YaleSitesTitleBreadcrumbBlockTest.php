<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Controller\TitleResolver;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_core\Plugin\Block\YaleSitesTitleBreadcrumbBlock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * Tests the page title and breadcrumb block.
 *
 * @coversDefaultClass \Drupal\ys_core\Plugin\Block\YaleSitesTitleBreadcrumbBlock
 *
 * @group yalesites
 * @group ys_core
 */
class YaleSitesTitleBreadcrumbBlockTest extends UnitTestCase {

  /**
   * The route match mock.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $routeMatch;

  /**
   * The title resolver mock.
   *
   * @var \Drupal\Core\Controller\TitleResolver|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $titleResolver;

  /**
   * The request stack mock.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $requestStack;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->routeMatch = $this->createMock(RouteMatchInterface::class);
    $this->titleResolver = $this->createMock(TitleResolver::class);
    $this->requestStack = $this->createMock(RequestStack::class);
  }

  /**
   * Builds the block plugin under test.
   *
   * @param array $configuration
   *   The block configuration.
   *
   * @return \Drupal\ys_core\Plugin\Block\YaleSitesTitleBreadcrumbBlock
   *   The block plugin.
   */
  protected function buildBlock(array $configuration = []): YaleSitesTitleBreadcrumbBlock {
    return new YaleSitesTitleBreadcrumbBlock($configuration, 'ys_title_breadcrumb_block', ['provider' => 'ys_core'], $this->routeMatch, $this->titleResolver, $this->requestStack);
  }

  /**
   * Builds a node mock that reports the given title.
   *
   * @param string $title
   *   The node title.
   *
   * @return \Drupal\node\NodeInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The node mock.
   */
  protected function mockNode(string $title) {
    $node = $this->createMock(NodeInterface::class);
    $node->method('label')->willReturn($title);

    return $node;
  }

  /**
   * The node's title is used rather than the route title.
   *
   * @covers ::build
   */
  public function testBuildUsesNodeTitle(): void {
    $this->routeMatch->method('getRouteObject')->willReturn(new Route('/node/{node}'));
    $this->routeMatch->method('getParameter')->willReturnMap([
      ['node_revision', NULL],
      ['node', $this->mockNode('My Page')],
    ]);
    $this->titleResolver->expects($this->never())->method('getTitle');

    $build = $this->buildBlock()->build();

    $this->assertSame('ys_title_breadcrumb', $build['#theme']);
    $this->assertSame('My Page', $build['#page_title']);
    $this->assertSame([], $build['#breadcrumbs_placeholder']);
  }

  /**
   * On the Layout Builder route the title is the node's, not the route's.
   *
   * @covers ::build
   */
  public function testBuildIgnoresLayoutBuilderRouteTitle(): void {
    $this->routeMatch->method('getRouteObject')->willReturn(new Route('/node/{node}/layout'));
    $this->routeMatch->method('getParameter')->willReturnMap([
      ['node_revision', NULL],
      ['node', $this->mockNode('My Page')],
    ]);
    $this->titleResolver->expects($this->never())->method('getTitle');

    $build = $this->buildBlock()->build();

    $this->assertSame('My Page', $build['#page_title']);
  }

  /**
   * The example breadcrumb trail only appears on a layout route.
   *
   * @covers ::build
   */
  public function testBuildAddsPlaceholderBreadcrumbsOnLayoutRoute(): void {
    $this->routeMatch->method('getRouteObject')->willReturn(new Route('/node/{node}/layout'));
    $this->routeMatch->method('getParameter')->willReturnMap([
      ['node_revision', NULL],
      ['node', $this->mockNode('My Page')],
    ]);

    $build = $this->buildBlock()->build();

    $this->assertCount(4, $build['#breadcrumbs_placeholder']);
    $this->assertSame('Home', $build['#breadcrumbs_placeholder'][0]['title']);
    $this->assertTrue($build['#breadcrumbs_placeholder'][3]['is_active']);
  }

  /**
   * Without a node in context, the block falls back to the route title.
   *
   * @covers ::build
   */
  public function testBuildFallsBackToRouteTitleWithoutNode(): void {
    $route = new Route('/about');
    $request = new Request();
    $this->routeMatch->method('getRouteObject')->willReturn($route);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    $this->titleResolver->method('getTitle')->with($request, $route)->willReturn('About Us');

    $build = $this->buildBlock(['page_title_display' => 'visible'])->build();

    $this->assertSame('About Us', $build['#page_title']);
    $this->assertSame('visible', $build['#page_title_display']);
  }

  /**
   * Without a current request there is no route title to fall back to.
   *
   * @covers ::build
   */
  public function testBuildReturnsEmptyTitleWithoutRequest(): void {
    $this->routeMatch->method('getRouteObject')->willReturn(new Route('/about'));
    $this->requestStack->method('getCurrentRequest')->willReturn(NULL);
    $this->titleResolver->expects($this->never())->method('getTitle');

    $build = $this->buildBlock()->build();

    $this->assertSame('', $build['#page_title']);
  }

  /**
   * With no route object there is no title and no example breadcrumbs.
   *
   * @covers ::build
   */
  public function testBuildWithNoRouteReturnsEmptyTitle(): void {
    $this->routeMatch->method('getRouteObject')->willReturn(NULL);

    $build = $this->buildBlock()->build();

    $this->assertSame('', $build['#page_title']);
    $this->assertSame([], $build['#breadcrumbs_placeholder']);
  }

}
