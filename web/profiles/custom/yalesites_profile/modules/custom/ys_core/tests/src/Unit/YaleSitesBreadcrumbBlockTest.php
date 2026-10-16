<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Link;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Plugin\Context\ContextInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\node\NodeInterface;
use Drupal\ys_core\Plugin\Block\YaleSitesBreadcrumbBlock;
use Drupal\ys_core\YaleSitesBreadcrumbsManager;

/**
 * Tests the YaleSites breadcrumb block.
 *
 * @coversDefaultClass \Drupal\ys_core\Plugin\Block\YaleSitesBreadcrumbBlock
 *
 * @group yalesites
 * @group ys_core
 */
class YaleSitesBreadcrumbBlockTest extends UnitTestCase {

  /**
   * The route match mock.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $routeMatch;

  /**
   * The breadcrumbs manager mock.
   *
   * @var \Drupal\ys_core\YaleSitesBreadcrumbsManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $breadcrumbsManager;

  /**
   * The URL generator mock.
   *
   * @var \Drupal\Core\Routing\UrlGeneratorInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $urlGenerator;

  /**
   * The menu link manager mock.
   *
   * @var \Drupal\Core\Menu\MenuLinkManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $menuLinkManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->routeMatch = $this->createMock(RouteMatchInterface::class);
    $this->breadcrumbsManager = $this->createMock(YaleSitesBreadcrumbsManager::class);
    $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    $this->urlGenerator->method('generateFromRoute')->willReturn('/');
    $this->menuLinkManager = $this->createMock(MenuLinkManagerInterface::class);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Builds the block plugin under test.
   */
  protected function buildBlock(): YaleSitesBreadcrumbBlock {
    return new YaleSitesBreadcrumbBlock([], 'ys_breadcrumb_block', ['provider' => 'ys_core'], $this->breadcrumbsManager, $this->urlGenerator, $this->routeMatch, $this->menuLinkManager, $this->getConfigFactoryStub([
      'menu_breadcrumb.settings' => [
        'menu_breadcrumb_menus' => [
          'footer' => ['enabled' => 0, 'weight' => -20],
          'main' => ['enabled' => 1, 'weight' => -10],
        ],
      ],
    ]));
  }

  /**
   * Puts a node on a Layout Builder route, as the entity being laid out.
   */
  protected function onLayoutBuilderRouteFor(string $label): void {
    $url = $this->createMock(Url::class);
    $url->method('getRouteName')->willReturn('entity.node.canonical');
    $url->method('getRouteParameters')->willReturn(['node' => 5]);
    $node = $this->createMock(NodeInterface::class);
    $node->method('label')->willReturn($label);
    $node->method('isNew')->willReturn(FALSE);
    $node->method('toUrl')->willReturn($url);
    $node->method('getCacheTags')->willReturn(['node:5']);
    $node->method('getCacheContexts')->willReturn([]);
    $node->method('getCacheMaxAge')->willReturn(-1);
    $context = $this->createMock(ContextInterface::class);
    $context->method('getContextValue')->willReturn($node);
    $storage = $this->createMock(SectionStorageInterface::class);
    $storage->method('getContextsDuringPreview')->willReturn(['layout_builder.entity' => $context]);

    $this->routeMatch->method('getParameter')->willReturnMap([
      ['section_storage', $storage],
    ]);
    $this->breadcrumbsManager->expects($this->never())->method('build');
  }

  /**
   * Mocks a main menu link with the given title and URL.
   */
  protected function menuLink(string $title, string $path): MenuLinkInterface {
    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn($path);
    $link = $this->createMock(MenuLinkInterface::class);
    $link->method('getTitle')->willReturn($title);
    $link->method('getUrlObject')->willReturn($url);
    $link->method('getPluginId')->willReturn("menu_link_content:$title");
    return $link;
  }

  /**
   * A page in no menu previews no breadcrumb, as on the published page.
   *
   * Layout Builder re-renders the preview from its own form routes ("Configure
   * section", "Add section"), so a trail built from the current route showed
   * "Home / Configure section" or nothing at all
   * (yalesites-org/YaleSites-Internal#1826). A published page with no menu
   * link shows no breadcrumb (yalesites-org/YaleSites-Internal#1406), so the
   * preview must not invent a "Home / (page title)" trail for it.
   *
   * @covers ::build
   */
  public function testLayoutBuilderRouteShowsNoBreadcrumbForPageInNoMenu(): void {
    $this->onLayoutBuilderRouteFor('My Page');
    $this->menuLinkManager->method('loadLinksByRoute')->willReturn([]);

    $build = $this->buildBlock()->build();

    $this->assertSame([], $build['#items']);
    $this->assertFalse($build['#always_show_breadcrumbs']);
  }

  /**
   * A page in the main menu previews the same trail the published page shows.
   *
   * @covers ::build
   */
  public function testLayoutBuilderRouteShowsMenuTrail(): void {
    $this->onLayoutBuilderRouteFor('Text page');
    $blocks = $this->menuLink('Blocks', '/blocks');
    $text = $this->menuLink('Text', '/blocks/text');
    $this->menuLinkManager->method('loadLinksByRoute')
      ->with('entity.node.canonical', ['node' => 5], 'main')
      ->willReturn([$text]);
    // Ordered from the link up to the menu root, as core returns them.
    $this->menuLinkManager->method('getParentIds')
      ->willReturn([
        'menu_link_content:Text' => 'menu_link_content:Text',
        'menu_link_content:Blocks' => 'menu_link_content:Blocks',
      ]);
    $this->menuLinkManager->method('createInstance')->willReturnMap([
      ['menu_link_content:Blocks', [], $blocks],
      ['menu_link_content:Text', [], $text],
    ]);

    $items = $this->buildBlock()->build()['#items'];

    $this->assertCount(3, $items);
    $this->assertSame('Home', (string) $items[0]['title']);
    $this->assertSame(['title' => 'Blocks', 'url' => '/blocks', 'is_active' => FALSE], $items[1]);
    $this->assertSame(['title' => 'Text', 'url' => '', 'is_active' => TRUE], $items[2]);
  }

  /**
   * The preview trail is invalidated when the page being laid out changes.
   *
   * The block's render cache varies only by route, so without the entity's
   * cache tag a renamed page kept its old title in the trail.
   *
   * @covers ::build
   */
  public function testLayoutBuilderTrailCarriesEntityCacheTag(): void {
    $this->onLayoutBuilderRouteFor('Renamed Page');
    $this->menuLinkManager->method('loadLinksByRoute')->willReturn([]);

    $build = $this->buildBlock()->build();

    $this->assertContains('node:5', $build['#cache']['tags']);
    $this->assertContains('config:system.menu.main', $build['#cache']['tags']);
  }

  /**
   * Outside Layout Builder the trail still comes from the breadcrumb manager.
   *
   * @covers ::build
   */
  public function testOtherRoutesUseBreadcrumbManager(): void {
    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn('/about');
    $link = $this->createMock(Link::class);
    $link->method('getText')->willReturn('About');
    $link->method('getUrl')->willReturn($url);

    $this->routeMatch->method('getParameter')->willReturn(NULL);
    $this->breadcrumbsManager->method('build')->willReturn([$link]);
    $this->breadcrumbsManager->method('hasLandingPage')->willReturn(FALSE);

    $items = $this->buildBlock()->build()['#items'];

    $this->assertSame([['title' => 'About', 'url' => '/about', 'is_active' => FALSE]], $items);
  }

  /**
   * Outside Layout Builder the manager's cacheability reaches the render array.
   *
   * The manager drops crumbs the user cannot access and reports what that
   * depended on (yalesites-org/YaleSites-Internal#1406); the block must apply
   * it, or one user's trail is served to another.
   *
   * @covers ::build
   */
  public function testOtherRoutesApplyBreadcrumbManagerCacheability(): void {
    $this->routeMatch->method('getParameter')->willReturn(NULL);
    $this->breadcrumbsManager->method('build')
      ->willReturnCallback(function ($route, CacheableMetadata $cacheability) {
        $cacheability->addCacheTags(['node:79']);
        return [];
      });
    $this->breadcrumbsManager->method('hasLandingPage')->willReturn(FALSE);

    $build = $this->buildBlock()->build();

    $this->assertContains('node:79', $build['#cache']['tags']);
  }

}
