<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
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
    // Adding cache contexts asserts they are valid via this service.
    $cache_contexts_manager = $this->createMock(CacheContextsManager::class);
    $cache_contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cache_contexts_manager);
    \Drupal::setContainer($container);

    $this->breadcrumbBuilder = $this->createMock(BreadcrumbBuilderInterface::class);
    $this->manager = new YaleSitesBreadcrumbsManager($this->breadcrumbBuilder);
  }

  /**
   * Builds a link mock with the given text and URL access result.
   */
  protected function link(string $text, ?AccessResult $access = NULL): Link {
    $url = $this->createMock(Url::class);
    $url->method('access')->with(NULL, TRUE)->willReturn($access ?? AccessResult::allowed());
    $link = $this->createMock(Link::class);
    $link->method('getText')->willReturn($text);
    $link->method('getUrl')->willReturn($url);
    return $link;
  }

  /**
   * Returns a real breadcrumb holding the given links for the builder mock.
   */
  protected function mockBreadcrumb(array $links): RouteMatchInterface {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->setLinks($links);
    $breadcrumb->addCacheContexts(['route.menu_active_trails:main']);
    $breadcrumb->addCacheTags(['config:system.menu.main']);
    $route = $this->createMock(RouteMatchInterface::class);
    $this->breadcrumbBuilder->method('build')->with($route)->willReturn($breadcrumb);
    return $route;
  }

  /**
   * @covers ::build
   */
  public function testBuildRemovesLinksWithEmptyText(): void {
    $home = $this->link('Home');
    $empty = $this->link('');
    $about = $this->link('About');
    $route = $this->mockBreadcrumb([$home, $empty, $about]);

    $this->assertSame([$home, $about], array_values($this->manager->build($route)));
  }

  /**
   * Inaccessible crumbs are dropped and every cacheability input is kept.
   *
   * @covers ::build
   */
  public function testBuildRemovesInaccessibleLinksAndCarriesCacheability(): void {
    $home = $this->link('Home', AccessResult::allowed()->addCacheContexts(['user.permissions']));
    $parent = $this->link('Parent', AccessResult::forbidden()->addCacheTags(['node:79']));
    $child = $this->link('Child', AccessResult::allowed()->addCacheTags(['node:80']));
    $route = $this->mockBreadcrumb([$home, $parent, $child]);

    $cacheability = new CacheableMetadata();
    $links = $this->manager->build($route, $cacheability);

    $this->assertSame([$home, $child], array_values($links));
    $this->assertEqualsCanonicalizing(
      ['config:system.menu.main', 'node:79', 'node:80'],
      $cacheability->getCacheTags(),
    );
    $this->assertEqualsCanonicalizing(
      ['route.menu_active_trails:main', 'user.permissions'],
      $cacheability->getCacheContexts(),
    );
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
