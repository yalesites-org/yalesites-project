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

}
