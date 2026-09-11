<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Component\Annotation\Doctrine\SimpleAnnotationReader;
use Drupal\Core\Block\Annotation\Block;
use Drupal\Core\Controller\TitleResolver;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\ContextHandler;
use Drupal\Core\Plugin\Context\ContextInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
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

    // The @Translation inside the plugin annotation renders through
    // string_translation, and BlockBase's configuration form reaches for
    // context.handler. Both are needed by the annotation and form tests below.
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('context.handler', new ContextHandler());
    \Drupal::setContainer($container);
  }

  /**
   * Builds the block plugin under test.
   *
   * @return \Drupal\ys_layouts\Plugin\Block\PageMetaBlock
   *   The block plugin.
   */
  protected function buildBlock(array $configuration = []): PageMetaBlock {
    $definition = [
      'provider' => 'ys_layouts',
      'admin_label' => 'Page Meta Block',
      'context_definitions' => [
        PageMetaBlock::ENTITY_CONTEXT => new ContextDefinition('entity', NULL, FALSE),
      ],
    ];

    return new PageMetaBlock($configuration, 'page_meta_block', $definition, $this->routeMatch, $this->titleResolver, $this->requestStack);
  }

  /**
   * Puts an entity into the block's Layout Builder entity context.
   *
   * @param \Drupal\ys_layouts\Plugin\Block\PageMetaBlock $block
   *   The block plugin.
   * @param mixed $value
   *   The context value, normally a node.
   */
  protected function setRenderedEntity(PageMetaBlock $block, $value): void {
    $context = $this->createMock(ContextInterface::class);
    $context->method('getContextValue')->willReturn($value);
    $block->setContext(PageMetaBlock::ENTITY_CONTEXT, $context);
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
   * On the Layout Builder route the node title wins over "Edit layout for ...".
   *
   * Search API renders the node in the same request that saves it, so a route
   * title leaks into the indexed content of the page.
   *
   * @covers ::build
   */
  public function testBuildIgnoresLayoutBuilderRouteTitle(): void {
    $route = new Route('/node/{node}/layout');
    $request = new Request();
    $this->routeMatch->method('getRouteObject')->willReturn($route);
    $this->routeMatch->method('getParameter')->willReturnMap([
      ['node_revision', NULL],
      ['node', $this->mockNode('My Page')],
    ]);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    $this->titleResolver->expects($this->never())->method('getTitle');

    $build = $this->buildBlock()->build();

    $this->assertSame('My Page', $build['#page_title']);
  }

  /**
   * A non-node route parameter does not stand in for the entity.
   *
   * @covers ::build
   */
  public function testBuildFallsBackWhenRouteParameterIsNotNode(): void {
    $route = new Route('/node/{node}');
    $request = new Request();
    $this->routeMatch->method('getRouteObject')->willReturn($route);
    $this->routeMatch->method('getParameter')->willReturnMap([
      ['node_revision', NULL],
      ['node', '12'],
    ]);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    $this->titleResolver->method('getTitle')->with($request, $route)->willReturn('Some Route Title');

    $build = $this->buildBlock()->build();

    $this->assertSame('Some Route Title', $build['#page_title']);
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
   * A revision route renders the revision, so its title wins.
   *
   * @covers ::build
   */
  public function testBuildPrefersTheRevisionBeingRendered(): void {
    $this->routeMatch->method('getRouteObject')->willReturn(new Route('/node/{node}/revisions/{node_revision}/view'));
    $this->routeMatch->method('getParameter')->willReturnMap([
      ['node_revision', $this->mockNode('My Page, as it was')],
      ['node', $this->mockNode('My Page')],
    ]);
    $this->titleResolver->expects($this->never())->method('getTitle');

    $build = $this->buildBlock()->build();

    $this->assertSame('My Page, as it was', $build['#page_title']);
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
   * Data provider of context values that must not become the title.
   *
   * @return array<string, array{mixed}>
   *   Test cases of a context value.
   */
  public function providerNonNodeContextValues(): array {
    return [
      'an entity that is not a node' => [$this->createMock(EntityInterface::class)],
      'no entity at all' => ['not-an-entity'],
    ];
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
    $reader = new SimpleAnnotationReader();
    $reader->addNamespace('Drupal\Core\Block\Annotation');
    $reader->addNamespace('Drupal\Core\Annotation');

    $annotation = $reader->getClassAnnotation(
      new \ReflectionClass(PageMetaBlock::class),
      Block::class
    );
    $definition = $annotation->get();

    $this->assertSame('layout_builder.entity', PageMetaBlock::ENTITY_CONTEXT);
    $this->assertArrayHasKey(PageMetaBlock::ENTITY_CONTEXT, $definition['context_definitions']);
    $this->assertFalse($definition['context_definitions'][PageMetaBlock::ENTITY_CONTEXT]->isRequired());
  }

  /**
   * The context-assignment select is kept off the editor's block form.
   *
   * BlockBase::buildConfigurationForm() sets $form['context_mapping'] for any
   * plugin declaring a context -- always, even when it resolves to an empty
   * element -- so this assertion fails if the override stops removing it.
   * Editors open this form to set Title Display, and choosing a route-derived
   * entity context there would store a context_mapping that reinstates the
   * indexed-wrong-title bug on that page alone.
   *
   * @covers ::buildConfigurationForm
   */
  public function testConfigurationFormHasNoContextAssignmentSelect(): void {
    $block = $this->buildBlock();
    $block->setStringTranslation($this->getStringTranslationStub());

    $form = $block->buildConfigurationForm([], new FormState());

    $this->assertArrayNotHasKey('context_mapping', $form);
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
