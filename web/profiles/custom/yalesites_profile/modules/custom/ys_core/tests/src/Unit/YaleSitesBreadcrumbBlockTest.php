<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Link;
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->routeMatch = $this->createMock(RouteMatchInterface::class);
    $this->breadcrumbsManager = $this->createMock(YaleSitesBreadcrumbsManager::class);
    $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    $this->urlGenerator->method('generateFromRoute')->willReturn('/');

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Builds the block plugin under test.
   */
  protected function buildBlock(): YaleSitesBreadcrumbBlock {
    return new YaleSitesBreadcrumbBlock([], 'ys_breadcrumb_block', ['provider' => 'ys_core'], $this->breadcrumbsManager, $this->urlGenerator, $this->routeMatch);
  }

  /**
   * On a Layout Builder route the trail names the page, not the form.
   *
   * Layout Builder re-renders the preview from its own form routes ("Configure
   * section", "Add section"), so a trail built from the current route showed
   * "Home / Configure section" or nothing at all
   * (yalesites-org/YaleSites-Internal#1826).
   *
   * @covers ::build
   */
  public function testLayoutBuilderRouteShowsPageBeingLaidOut(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('label')->willReturn('My Page');
    $context = $this->createMock(ContextInterface::class);
    $context->method('getContextValue')->willReturn($node);
    $storage = $this->createMock(SectionStorageInterface::class);
    $storage->method('getContextsDuringPreview')->willReturn(['layout_builder.entity' => $context]);

    $this->routeMatch->method('getParameter')->willReturnMap([
      ['section_storage', $storage],
    ]);
    $this->breadcrumbsManager->expects($this->never())->method('build');

    $items = $this->buildBlock()->build()['#items'];

    $this->assertCount(2, $items);
    $this->assertSame('Home', (string) $items[0]['title']);
    $this->assertSame('/', $items[0]['url']);
    $this->assertFalse($items[0]['is_active']);
    $this->assertSame(['title' => 'My Page', 'url' => '', 'is_active' => TRUE], $items[1]);
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

}
