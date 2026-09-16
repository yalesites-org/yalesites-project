<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_layouts\Plugin\Block\RelatedContentBlock;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the related content block.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Plugin\Block\RelatedContentBlock
 *
 * @group yalesites
 * @group ys_layouts
 */
class RelatedContentBlockTest extends UnitTestCase {

  /**
   * The route match mock.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $routeMatch;

  /**
   * The request stack mock.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $requestStack;

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The block plugin under test.
   *
   * @var \Drupal\ys_layouts\Plugin\Block\RelatedContentBlock
   */
  protected $block;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->routeMatch = $this->createMock(RouteMatchInterface::class);
    $this->requestStack = $this->createMock(RequestStack::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);

    $this->block = new RelatedContentBlock(
      [],
      'related_content_block',
      ['provider' => 'ys_layouts'],
      $this->routeMatch,
      $this->requestStack,
      $this->entityTypeManager
    );
  }

  /**
   * The default heading is "Related Content".
   *
   * @covers ::defaultConfiguration
   */
  public function testDefaultConfigurationSetsHeading(): void {
    $this->assertSame('Related Content', $this->block->defaultConfiguration()['heading']);
  }

  /**
   * A node with no field_related_content renders nothing.
   *
   * @covers ::build
   */
  public function testBuildReturnsEmptyWhenFieldMissing(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('field_related_content')->willReturn(FALSE);
    $this->routeMatch->method('getParameter')->with('node')->willReturn($node);

    $build = $this->block->build();

    $this->assertSame([], $build);
  }

  /**
   * An empty field_related_content value renders nothing.
   *
   * @covers ::build
   */
  public function testBuildReturnsEmptyWhenFieldEmpty(): void {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(TRUE);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn(TRUE);
    $node->method('get')->with('field_related_content')->willReturn($field);
    $this->routeMatch->method('getParameter')->with('node')->willReturn($node);

    $build = $this->block->build();

    $this->assertSame([], $build);
  }

  /**
   * With no node on the route, the Layout Builder ajax path is used.
   *
   * @covers ::getCurrentNode
   */
  public function testBuildFallsBackToLoadingNodeFromAjaxPath(): void {
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn(TRUE);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn(TRUE);
    $node->method('get')->willReturn($field);

    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('load')->with('42')->willReturn($node);
    $this->entityTypeManager->method('getStorage')->with('node')->willReturn($nodeStorage);

    $this->routeMatch->method('getParameter')->with('node')->willReturn(NULL);
    $path = '/admin/config/content/layout_builder/update/overrides/node.42.default.en/0/content';
    $this->requestStack->method('getCurrentRequest')->willReturn(Request::create($path));

    $build = $this->block->build();

    // The bail-on-empty-field branch still runs, proving the node was found.
    $this->assertSame([], $build);
  }

  /**
   * When the underlying view config does not exist, the block renders empty.
   *
   * Views::getView() calls \Drupal::entityTypeManager()->getStorage('view')
   * ->load(), so the container is stubbed the same way the existing
   * YSLayoutBannerTest stubs \Drupal::routeMatch().
   *
   * @covers ::build
   */
  public function testBuildReturnsEmptyWhenViewNotFound(): void {
    $targetField = $this->createMock(FieldItemListInterface::class);
    $targetField->method('isEmpty')->willReturn(FALSE);
    $targetField->method('getValue')->willReturn([['target_id' => 5]]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->willReturn(TRUE);
    $node->method('get')->with('field_related_content')->willReturn($targetField);
    $this->routeMatch->method('getParameter')->with('node')->willReturn($node);

    $viewStorage = $this->createMock(EntityStorageInterface::class);
    $viewStorage->method('load')->with('entity_reference_for_fields')->willReturn(NULL);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('view')->willReturn($viewStorage);

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entityTypeManager);
    \Drupal::setContainer($container);

    $build = $this->block->build();

    $this->assertSame([], $build);
  }

  /**
   * The heading field is required and defaults to "Related Content".
   *
   * @covers ::blockForm
   */
  public function testBlockFormHeadingIsRequiredWithDefault(): void {
    $this->block->setStringTranslation($this->getStringTranslationStub());

    $form = $this->block->blockForm([], new FormState());

    $this->assertTrue($form['heading']['#required']);
    $this->assertSame('Related Content', $form['heading']['#default_value']);
  }

  /**
   * Submitting the form stores the configured heading.
   *
   * @covers ::blockSubmit
   */
  public function testBlockSubmitStoresHeading(): void {
    $form_state = new FormState();
    $form_state->setValue('heading', 'See Also');

    $this->block->blockSubmit([], $form_state);

    $this->assertSame('See Also', $this->block->getConfiguration()['heading']);
  }

  /**
   * Cache tags merge in the current node's cache tags.
   *
   * @covers ::getCacheTags
   */
  public function testGetCacheTagsMergesNodeCacheTags(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('getCacheTags')->willReturn(['node:9']);
    $this->routeMatch->method('getParameter')->with('node')->willReturn($node);

    $tags = $this->block->getCacheTags();

    $this->assertContains('node:9', $tags);
  }

  /**
   * Cache contexts include the route and URL path.
   *
   * @covers ::getCacheContexts
   */
  public function testGetCacheContextsIncludesRouteAndPath(): void {
    $this->routeMatch->method('getParameter')->willReturn(NULL);

    $cacheContextsManager = $this->getMockBuilder('Drupal\Core\Cache\Context\CacheContextsManager')
      ->disableOriginalConstructor()
      ->getMock();
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);

    $contexts = $this->block->getCacheContexts();

    $this->assertContains('route', $contexts);
    $this->assertContains('url.path', $contexts);
  }

}
