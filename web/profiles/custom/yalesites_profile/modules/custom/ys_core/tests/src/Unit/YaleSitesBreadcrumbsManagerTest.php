<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_core\YaleSitesBreadcrumbsManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tests YaleSitesBreadcrumbsManager's link filtering and landing page check.
 *
 * @coversDefaultClass \Drupal\ys_core\YaleSitesBreadcrumbsManager
 *
 * @group ys_core
 * @group yalesites
 */
class YaleSitesBreadcrumbsManagerTest extends UnitTestCase {

  /**
   * The breadcrumb builder mock.
   *
   * @var \Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $breadcrumbBuilder;

  /**
   * The manager under test.
   *
   * @var \Drupal\ys_core\YaleSitesBreadcrumbsManager
   */
  protected $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->breadcrumbBuilder = $this->createMock(BreadcrumbBuilderInterface::class);
    $this->manager = new YaleSitesBreadcrumbsManager($this->breadcrumbBuilder);
  }

  /**
   * @covers ::build
   */
  public function testBuildRemovesLinksWithEmptyText(): void {
    $home = $this->createMock(Link::class);
    $home->method('getText')->willReturn('Home');

    $empty = $this->createMock(Link::class);
    $empty->method('getText')->willReturn('');

    $about = $this->createMock(Link::class);
    $about->method('getText')->willReturn('About');

    $breadcrumb = $this->createMock(Breadcrumb::class);
    $breadcrumb->method('getLinks')->willReturn([$home, $empty, $about]);

    $route = $this->createMock(RouteMatchInterface::class);
    $this->breadcrumbBuilder->method('build')->with($route)->willReturn($breadcrumb);

    $links = $this->manager->build($route);

    $this->assertSame([$home, $about], array_values($links));
  }

  /**
   * @covers ::create
   */
  public function testCreateInstantiatesFromContainer(): void {
    $container = $this->createMock(ContainerInterface::class);
    $container->method('get')->with('breadcrumb')->willReturn($this->breadcrumbBuilder);

    $manager = YaleSitesBreadcrumbsManager::create($container);
    $this->assertInstanceOf(YaleSitesBreadcrumbsManager::class, $manager);
  }

  /**
   * @covers ::hasLandingPage
   *
   * @dataProvider landingPageProvider
   */
  public function testHasLandingPage(?string $bundle, bool $expected): void {
    $route = $this->createMock(RouteMatchInterface::class);

    if ($bundle === NULL) {
      $route->method('getParameter')->with('node')->willReturn(NULL);
    }
    else {
      $node = $this->createMock(NodeInterface::class);
      $node->method('bundle')->willReturn($bundle);
      $route->method('getParameter')->with('node')->willReturn($node);
    }

    $this->assertSame($expected, $this->manager->hasLandingPage($route));
  }

  /**
   * Provides node bundles and whether they count as a landing page.
   *
   * @return array
   *   Each case: [bundle or NULL, expected hasLandingPage()].
   */
  public static function landingPageProvider(): array {
    return [
      'post is landing page' => ['post', TRUE],
      'event is landing page' => ['event', TRUE],
      'page is not landing page' => ['page', FALSE],
      'no node on route' => [NULL, FALSE],
    ];
  }

  /**
   * Tests that the block-move endpoint never reaches the breadcrumb builder.
   *
   * The breadcrumb builder must not even be consulted: core resolves a title
   * for every ancestor of the request path, and the eight-segment ancestor of
   * a move URL matches layout_builder.move_block_form, whose title callback
   * throws on what is really a region name. Returning early is the fix, so
   * "never called" is the assertion that proves the guard sits in front of it.
   *
   * @covers ::build
   */
  public function testBuildSkipsTheBlockMoveEndpoint(): void {
    $this->breadcrumbBuilder->expects($this->never())->method('build');

    $this->assertSame([], $this->manager->build($this->routeMatchFor('layout_builder.move_block')));
  }

  /**
   * Tests that every other route still builds breadcrumbs.
   *
   * The guard is deliberately scoped to the one route whose ancestor collides.
   * Its Layout Builder siblings are eight segments or fewer, so nothing of
   * theirs matches a throwing title callback, and blanking the breadcrumb
   * component in their AJAX rebuild would be a regression on working paths.
   * layout_builder.overrides.node.view is the Layout Builder editing page -
   * a real page an editor looks at - and must keep its breadcrumbs too.
   *
   * @covers ::build
   *
   * @dataProvider breadcrumbBuildingRouteProvider
   */
  public function testBuildStillRunsOnEveryOtherRoute(string $route_name): void {
    $link = $this->createMock(Link::class);
    $link->method('getText')->willReturn('Home');
    $breadcrumb = $this->createMock(Breadcrumb::class);
    $breadcrumb->method('getLinks')->willReturn([$link]);

    $route = $this->routeMatchFor($route_name);
    $this->breadcrumbBuilder->expects($this->once())
      ->method('build')
      ->with($route)
      ->willReturn($breadcrumb);

    $this->assertSame([$link], array_values($this->manager->build($route)));
  }

  /**
   * Provides routes that must still get breadcrumbs.
   *
   * @return array
   *   Each case: [route name].
   */
  public static function breadcrumbBuildingRouteProvider(): array {
    return [
      'the move CONFIRMATION FORM, not the move itself' => ['layout_builder.move_block_form'],
      'add block' => ['layout_builder.add_block'],
      'update block' => ['layout_builder.update_block'],
      'remove block' => ['layout_builder.remove_block'],
      'ys_layouts clone block' => ['ys_layouts.clone_block'],
      'the Layout Builder editing page' => ['layout_builder.overrides.node.view'],
      'an ordinary node page' => ['entity.node.canonical'],
    ];
  }

  /**
   * Builds a route match reporting the given route name.
   *
   * @param string $route_name
   *   The route name.
   *
   * @return \Drupal\Core\Routing\RouteMatchInterface|\PHPUnit\Framework\MockObject\MockObject
   *   The route match.
   */
  protected function routeMatchFor(string $route_name) {
    $route = $this->createMock(RouteMatchInterface::class);
    $route->method('getRouteName')->willReturn($route_name);
    return $route;
  }

}
