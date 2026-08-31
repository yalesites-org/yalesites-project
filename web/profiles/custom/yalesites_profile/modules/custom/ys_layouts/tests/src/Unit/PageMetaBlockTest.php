<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Controller\TitleResolver;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_layouts\Plugin\Block\PageMetaBlock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * Tests the page meta block.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Plugin\Block\PageMetaBlock
 *
 * @group yalesites
 * @group ys_layouts
 */
class PageMetaBlockTest extends UnitTestCase {

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
   * @return \Drupal\ys_layouts\Plugin\Block\PageMetaBlock
   *   The block plugin.
   */
  protected function buildBlock(array $configuration = []): PageMetaBlock {
    return new PageMetaBlock($configuration, 'page_meta_block', ['provider' => 'ys_layouts'], $this->routeMatch, $this->titleResolver, $this->requestStack);
  }

  /**
   * With no route object, the page title stays empty.
   *
   * @covers ::build
   */
  public function testBuildWithNoRouteReturnsEmptyTitle(): void {
    $this->routeMatch->method('getRouteObject')->willReturn(NULL);

    $build = $this->buildBlock()->build();

    $this->assertSame('ys_page_meta_block', $build['#theme']);
    $this->assertSame('', $build['#page_title']);
  }

  /**
   * The block resolves and renders the current route's title.
   *
   * @covers ::build
   */
  public function testBuildResolvesTitleFromRoute(): void {
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
   * The title display select carries the configured default value.
   *
   * @covers ::blockForm
   */
  public function testBlockFormUsesConfiguredTitleDisplay(): void {
    $block = $this->buildBlock(['page_title_display' => 'hidden']);
    $block->setStringTranslation($this->getStringTranslationStub());
    $form_state = new FormState();

    $form = $block->blockForm([], $form_state);

    $this->assertSame('select', $form['page_title_display']['#type']);
    $this->assertSame('hidden', $form['page_title_display']['#default_value']);
  }

  /**
   * Submitting the form stores the selected title display in configuration.
   *
   * @covers ::blockSubmit
   */
  public function testBlockSubmitStoresTitleDisplay(): void {
    $block = $this->buildBlock();
    $form_state = new FormState();
    $form_state->setValue('page_title_display', 'visually-hidden');

    $block->blockSubmit([], $form_state);

    $configuration = $block->getConfiguration();
    $this->assertSame('visually-hidden', $configuration['page_title_display']);
  }

}
