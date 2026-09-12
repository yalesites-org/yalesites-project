<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\ys_core\Traits\LayoutBuilderEntityContextTestTrait;
use Drupal\link\LinkItemInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ys_layouts\Plugin\Block\ResourceMetaBlock;
use Drupal\ys_layouts\Service\MediaAltResolver;
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

  use LayoutBuilderEntityContextTestTrait;

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
   * The media alt resolver mock.
   *
   * @var \Drupal\ys_layouts\Service\MediaAltResolver|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $mediaAltResolver;

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

    $this->requestStack = $this->createMock(RequestStack::class);
    $this->dateFormatter = $this->createMock(DateFormatter::class);
    $this->entityTypeManager = $this->createMock(EntityTypeManager::class);
    $this->resourceAuthorBuilder = $this->createMock(ResourceAuthorBuilder::class);
    $this->mediaAltResolver = $this->createMock(MediaAltResolver::class);

    $this->setUpLayoutBuilderEntityContextContainer();

    $this->block = new ResourceMetaBlock(
      [],
      'resource_meta_block',
      [
        'provider' => 'ys_layouts',
        'admin_label' => 'Resource Meta Block',
        'context_definitions' => $this->layoutBuilderEntityContextDefinitions(ResourceMetaBlock::class),
      ],
      $this->requestStack,
      $this->dateFormatter,
      $this->entityTypeManager,
      $this->resourceAuthorBuilder,
      $this->mediaAltResolver
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
   * The external source CTA is labelled, never the raw URL.
   *
   * The link field's title sub-field is disabled in config
   * (field.field.node.resource.field_external_source: settings.title = 0), so
   * the label always comes from here.
   *
   * @covers ::build
   */
  public function testExternalSourceUsesFixedLabelNotTheUrl(): void {
    $url = 'https://example.com/a/very/long/path/that/should/not/be/a/button/label';
    $build = $this->buildForResourceNodeWithExternalSource($url);

    $this->assertSame($url, $build['#resource_meta__external_source']['url']);
    $this->assertSame('Visit Source', (string) $build['#resource_meta__external_source']['title']);
  }

  /**
   * The sibling Download CTA keeps its own fixed label.
   *
   * @covers ::build
   */
  public function testDownloadLabelIsUnchanged(): void {
    $build = $this->buildForResourceNodeWithExternalSource('https://example.com/');

    $this->assertSame('Download', (string) $build['#resource_meta__download_label']);
  }

  /**
   * Builds the block for a resource node carrying an external source link.
   *
   * @param string $url
   *   The external source URL.
   *
   * @return array
   *   The block's render array.
   */
  protected function buildForResourceNodeWithExternalSource(string $url): array {
    $this->block->setStringTranslation($this->getStringTranslationStub());

    $urlObject = $this->createMock(Url::class);
    $urlObject->method('toString')->willReturn($url);

    $linkItem = $this->createMock(LinkItemInterface::class);
    $linkItem->method('getUrl')->willReturn($urlObject);

    $externalSourceField = $this->createMock(FieldItemListInterface::class);
    $externalSourceField->method('isEmpty')->willReturn(FALSE);
    $externalSourceField->method('first')->willReturn($linkItem);

    // Every other field build() reads is left empty.
    $emptyField = $this->createMock(FieldItemListInterface::class);
    $emptyField->method('getValue')->willReturn([]);
    $emptyField->method('first')->willReturn(NULL);

    // Node is mocked as the concrete class so build()'s magic property reads
    // (e.g. $node?->field_publish_date) resolve through a stubbed __get.
    $node = $this->createMock(Node::class);
    $node->method('bundle')->willReturn('resource');
    $node->method('getTitle')->willReturn('A resource');
    $node->method('__get')->willReturn($emptyField);
    $node->method('hasField')->willReturnCallback(
      fn (string $name): bool => $name === 'field_external_source'
    );
    $node->method('get')->with('field_external_source')->willReturn($externalSourceField);

    $this->resourceAuthorBuilder->method('build')->willReturn([]);

    $request = new Request();
    $request->attributes->set('node', $node);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    return $this->block->build();
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

  /**
   * The entity being rendered beats a request that names no node at all.
   *
   * This is the reported bug: /node/add/resource (every new resource's first
   * save) and a bulk operation from /admin/content carry no node on the
   * request, so getCurrentNode() found nothing, build() returned an empty
   * render array and the resource's heading was missing from what Beacon
   * indexes.
   *
   * getCacheTags() is the observable seam: it merges in whatever node
   * getCurrentNode() resolves, without needing the whole field-heavy build()
   * path.
   *
   * @covers ::getCurrentNode
   */
  public function testGetCurrentNodeUsesRenderedEntityWhenRequestHasNoNode(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('getCacheTags')->willReturn(['node:99']);
    $this->requestStack->method('getCurrentRequest')->willReturn(Request::create('/node/add/resource'));
    $this->entityTypeManager->expects($this->never())->method('getStorage');

    $this->setRenderedEntity($this->block, $node);

    $this->assertContains('node:99', $this->block->getCacheTags());
  }

  /**
   * The entity being rendered beats a DIFFERENT node named by the request.
   *
   * @covers ::getCurrentNode
   */
  public function testGetCurrentNodePrefersRenderedEntityOverRequestNode(): void {
    $rendered = $this->createMock(NodeInterface::class);
    $rendered->method('getCacheTags')->willReturn(['node:99']);
    $other = $this->createMock(NodeInterface::class);
    $other->method('getCacheTags')->willReturn(['node:11']);

    $request = new Request();
    $request->attributes->set('node', $other);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $this->setRenderedEntity($this->block, $rendered);
    $tags = $this->block->getCacheTags();

    $this->assertContains('node:99', $tags);
    $this->assertNotContains('node:11', $tags);
  }

  /**
   * A context holding something other than a node falls through to the request.
   *
   * Layout Builder hands over whatever entity the display belongs to, so the
   * context is not guaranteed to hold a node -- the defaults layout screen
   * passes a generated sample entity of the display's own type.
   *
   * @covers ::getCurrentNode
   *
   * @dataProvider providerNonNodeContextValues
   */
  public function testGetCurrentNodeIgnoresNonNodeEntityContext($value): void {
    $other = $this->createMock(NodeInterface::class);
    $other->method('getCacheTags')->willReturn(['node:11']);
    $request = new Request();
    $request->attributes->set('node', $other);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $this->setRenderedEntity($this->block, $value);

    $this->assertContains('node:11', $this->block->getCacheTags());
  }

  /**
   * The ANNOTATION declares the slot Layout Builder actually publishes.
   */
  public function testAnnotationDeclaresTheLayoutBuilderEntitySlot(): void {
    $this->assertDeclaresLayoutBuilderEntitySlot(ResourceMetaBlock::class);
  }

  /**
   * The context-assignment select is kept off the editor's block form.
   *
   * @covers ::buildConfigurationForm
   */
  public function testConfigurationFormHasNoContextAssignmentSelect(): void {
    $this->assertNoContextAssignmentSelect($this->block);
  }

  /**
   * A resource node's title renders as the block's heading.
   *
   * There is no route object at all when Search API indexes from cron or from
   * drush, and the block used to gate every field read -- including the title
   * -- on one, so the heading was blank on the path that does most of the
   * indexing.
   *
   * @covers ::build
   */
  public function testBuildRendersHeadingForResourceNode(): void {
    $build = $this->buildForResourceNodeWithExternalSource('https://example.com/a');

    $this->assertSame('A resource', $build['#resource_meta__heading']);
  }

  /**
   * An unsaved sample entity does not stand in for real content.
   *
   * Layout Builder's Defaults layout screen offers a generated sample entity
   * that core never saves (LayoutBuilderSampleEntityGenerator::get() calls
   * createWithSampleValues() and stashes it in a tempstore). Before this block
   * declared a context it rendered nothing there.
   *
   * @covers ::getCurrentNode
   */
  public function testGetCurrentNodeIgnoresUnsavedSampleEntity(): void {
    $sample = $this->createMock(NodeInterface::class);
    $sample->method('isNew')->willReturn(TRUE);
    $sample->method('getCacheTags')->willReturn(['node:99']);
    $this->requestStack->method('getCurrentRequest')->willReturn(new Request());

    $this->setRenderedEntity($this->block, $sample);

    $this->assertNotContains('node:99', $this->block->getCacheTags());
  }

}
