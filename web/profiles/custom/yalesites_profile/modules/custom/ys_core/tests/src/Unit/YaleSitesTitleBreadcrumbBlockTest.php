<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Controller\TitleResolver;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\ys_core\Traits\LayoutBuilderEntityContextTestTrait;
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

  use LayoutBuilderEntityContextTestTrait;

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

    $this->setUpLayoutBuilderEntityContextContainer();
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
    $definition = [
      'provider' => 'ys_core',
      'admin_label' => 'YaleSites Page Title and Breadcrumb Block',
      'context_definitions' => $this->layoutBuilderEntityContextDefinitions(YaleSitesTitleBreadcrumbBlock::class),
    ];

    return new YaleSitesTitleBreadcrumbBlock($configuration, 'ys_title_breadcrumb_block', $definition, $this->routeMatch, $this->titleResolver, $this->requestStack);
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

  /**
   * The entity being rendered beats a route that names no node at all.
   *
   * These are the routes a page is saved on when it is created
   * (/node/add/{type}) or changed by a bulk operation (/admin/content). The
   * route carries no node, so before this the interface text was indexed as
   * the page's heading.
   *
   * @covers ::build
   *
   * @dataProvider providerNodelessRoutes
   */
  public function testBuildPrefersRenderedEntityOnNodelessRoutes(string $path, string $route_title): void {
    $route = new Route($path);
    $request = new Request();
    $this->routeMatch->method('getRouteObject')->willReturn($route);
    $this->routeMatch->method('getParameter')->willReturn(NULL);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    $this->titleResolver->method('getTitle')->willReturn($route_title);

    $block = $this->buildBlock();
    $this->setRenderedEntity($block, $this->mockNode('My Page'));

    $this->assertSame('My Page', $block->build()['#page_title']);
  }

  /**
   * Data provider of routes that carry no node parameter.
   *
   * @return array<string, array{string, string}>
   *   Test cases of a route path and the title that route resolves to.
   */
  public static function providerNodelessRoutes(): array {
    return [
      'new page' => ['/node/add/page', 'Create Page'],
      'bulk operation' => ['/admin/content', 'Content'],
    ];
  }

  /**
   * The entity being rendered beats a DIFFERENT node named by the route.
   *
   * Rendering page A while the request is on page B's layout route used to
   * index B's title into A. Because the index also tracks referenced content,
   * a wrong-but-plausible title is harder to spot than an obviously wrong one.
   *
   * @covers ::build
   */
  public function testBuildPrefersRenderedEntityOverDifferentRoutesNode(): void {
    $this->routeMatch->method('getRouteObject')->willReturn(new Route('/node/{node}/layout'));
    $this->routeMatch->method('getParameter')->willReturnMap([
      ['node_revision', NULL],
      ['node', $this->mockNode('Hello World')],
    ]);
    $this->titleResolver->expects($this->never())->method('getTitle');

    $block = $this->buildBlock();
    $this->setRenderedEntity($block, $this->mockNode('My Page'));

    $this->assertSame('My Page', $block->build()['#page_title']);
  }

  /**
   * A context holding something other than a node is not used as the title.
   *
   * Layout Builder hands over whatever entity the display belongs to, so the
   * context is not guaranteed to hold a node -- the defaults layout screen
   * passes a generated sample entity of the display's own type. The slot is
   * also declared as a generic entity, so the guard is what keeps a non-node
   * out of the heading.
   *
   * @covers ::build
   *
   * @dataProvider providerNonNodeContextValues
   */
  public function testBuildIgnoresNonNodeEntityContext($value): void {
    $route = new Route('/about');
    $request = new Request();
    $this->routeMatch->method('getRouteObject')->willReturn($route);
    $this->routeMatch->method('getParameter')->willReturn(NULL);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    $this->titleResolver->method('getTitle')->with($request, $route)->willReturn('About Us');

    $block = $this->buildBlock();
    $this->setRenderedEntity($block, $value);

    $this->assertSame('About Us', $block->build()['#page_title']);
  }

  /**
   * The ANNOTATION declares the slot Layout Builder actually publishes.
   *
   * This is the guard for the whole approach, so it deliberately reads the
   * real @Block annotation through the same annotation reader plugin
   * discovery uses, rather than the hand-built definition buildBlock() passes
   * in -- asserting the fixture would only prove the fixture. Renaming the
   * annotation's slot to "entity" would still resolve while rendering but NOT
   * during Layout Builder preview, where OverridesSectionStorage unsets
   * "entity", and would put back the need to rewrite the stored
   * context_mapping of every node with an overridden layout. Optional is
   * asserted too: a required slot would throw where no entity is offered
   * instead of degrading to the route.
   */
  public function testAnnotationDeclaresTheLayoutBuilderEntitySlot(): void {
    $this->assertDeclaresLayoutBuilderEntitySlot(YaleSitesTitleBreadcrumbBlock::class);
  }

  /**
   * The context-assignment select is kept off the editor's block form.
   *
   * @covers ::buildConfigurationForm
   */
  public function testConfigurationFormHasNoContextAssignmentSelect(): void {
    $this->assertNoContextAssignmentSelect($this->buildBlock());
  }

}
