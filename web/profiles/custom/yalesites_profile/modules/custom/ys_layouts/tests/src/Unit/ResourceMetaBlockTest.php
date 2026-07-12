<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Controller\TitleResolver;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_layouts\Plugin\Block\ResourceMetaBlock;
use Drupal\ys_layouts\Service\ResourceAuthorBuilder;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the resource meta block.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Plugin\Block\ResourceMetaBlock
 *
 * @group yalesites
 * @group ys_layouts
 */
class ResourceMetaBlockTest extends UnitTestCase {

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
   * The date formatter mock.
   *
   * @var \Drupal\Core\Datetime\DateFormatter|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $dateFormatter;

  /**
   * The entity type manager mock.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The resource author builder mock.
   *
   * @var \Drupal\ys_layouts\Service\ResourceAuthorBuilder|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $resourceAuthorBuilder;

  /**
   * The block plugin under test.
   *
   * @var \Drupal\ys_layouts\Plugin\Block\ResourceMetaBlock
   */
  protected $block;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->routeMatch = $this->createMock(RouteMatchInterface::class);
    $this->titleResolver = $this->createMock(TitleResolver::class);
    $this->requestStack = $this->createMock(RequestStack::class);
    $this->dateFormatter = $this->createMock(DateFormatter::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManager::class);
    $this->resourceAuthorBuilder = $this->createMock(ResourceAuthorBuilder::class);

    $this->block = new ResourceMetaBlock(
      [],
      'resource_meta_block',
      ['provider' => 'ys_layouts'],
      $this->routeMatch,
      $this->titleResolver,
      $this->requestStack,
      $this->dateFormatter,
      $this->entityTypeManager,
      $this->resourceAuthorBuilder
    );
  }

  /**
   * With no node on the request, build() renders nothing.
   *
   * @covers ::build
   */
  public function testBuildReturnsEmptyWithNoNode(): void {
    $this->requestStack->method('getCurrentRequest')->willReturn(new Request());

    $build = $this->block->build();

    $this->assertSame([], $build);
  }

  /**
   * A node that is not a resource renders nothing.
   *
   * @covers ::build
   */
  public function testBuildReturnsEmptyForNonResourceBundle(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('page');
    $request = new Request();
    $request->attributes->set('node', $node);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $build = $this->block->build();

    $this->assertSame([], $build);
  }

  /**
   * With no node attribute, the Layout Builder ajax path is used to load one.
   *
   * The full success path of build() reads many entity fields via magic
   * property access (e.g. $node?->field_media), which cannot be exercised
   * against a NodeInterface mock -- see GAP log. This test only confirms
   * the ajax-path node lookup bails cleanly for a non-resource bundle.
   *
   * @covers ::getCurrentNode
   */
  public function testBuildFallsBackToLoadingNodeFromAjaxPath(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('page');

    $nodeStorage = $this->createMock(EntityStorageInterface::class);
    $nodeStorage->method('load')->with('42')->willReturn($node);
    $this->entityTypeManager->method('getStorage')->with('node')->willReturn($nodeStorage);

    $path = '/admin/config/content/layout_builder/update/overrides/node.42.default.en/0/content';
    $this->requestStack->method('getCurrentRequest')->willReturn(Request::create($path));

    $build = $this->block->build();

    $this->assertSame([], $build);
  }

  /**
   * Cache tags merge in the current node's cache tags.
   *
   * @covers ::getCacheTags
   */
  public function testGetCacheTagsMergesNodeCacheTags(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('getCacheTags')->willReturn(['node:11']);
    $request = new Request();
    $request->attributes->set('node', $node);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $tags = $this->block->getCacheTags();

    $this->assertContains('node:11', $tags);
  }

  /**
   * Cache contexts include the route and user permissions.
   *
   * @covers ::getCacheContexts
   */
  public function testGetCacheContextsIncludesRouteAndPermissions(): void {
    $this->requestStack->method('getCurrentRequest')->willReturn(new Request());

    $cacheContextsManager = $this->getMockBuilder('Drupal\Core\Cache\Context\CacheContextsManager')
      ->disableOriginalConstructor()
      ->getMock();
    $cacheContextsManager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContextsManager);
    \Drupal::setContainer($container);

    $contexts = $this->block->getCacheContexts();

    $this->assertContains('route', $contexts);
    $this->assertContains('user.permissions', $contexts);
  }

}
