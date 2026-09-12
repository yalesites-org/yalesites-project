<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\Tests\ys_core\Traits\LayoutBuilderEntityContextTestTrait;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\ys_layouts\Plugin\Block\PostMetaBlock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the post meta block.
 *
 * @coversDefaultClass \Drupal\ys_layouts\Plugin\Block\PostMetaBlock
 *
 * @group yalesites
 * @group ys_layouts
 */
class PostMetaBlockTest extends UnitTestCase {

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
   * The block plugin under test.
   *
   * @var \Drupal\ys_layouts\Plugin\Block\PostMetaBlock
   */
  protected $block;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->requestStack = $this->createMock(RequestStack::class);
    $this->dateFormatter = $this->createMock(DateFormatter::class);

    $this->setUpLayoutBuilderEntityContextContainer();

    $this->block = new PostMetaBlock(
      [],
      'post_meta_block',
      [
        'provider' => 'ys_layouts',
        'admin_label' => 'Post Meta Block',
        'context_definitions' => $this->layoutBuilderEntityContextDefinitions(PostMetaBlock::class),
      ],
      $this->requestStack,
      $this->dateFormatter
    );
  }

  /**
   * A request with no node attribute renders nothing.
   *
   * @covers ::build
   */
  public function testBuildReturnsEmptyWithNoNode(): void {
    $request = new Request();
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $build = $this->block->build();

    $this->assertSame([], $build);
  }

  /**
   * A node that is not a post renders nothing.
   *
   * @covers ::build
   */
  public function testBuildReturnsEmptyForNonPostBundle(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('page');
    $request = new Request();
    $request->attributes->set('node', $node);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);

    $build = $this->block->build();

    $this->assertSame([], $build);
  }

  /**
   * Author entity references render as linked title/url pairs.
   *
   * Exercises the protected getPostAuthorLinks() helper directly via
   * reflection: the full build() success path relies on Drupal's entity
   * field magic getters (e.g. $node->field_author), which cannot be
   * exercised against a NodeInterface mock -- see GAP log.
   *
   * @covers ::getPostAuthorLinks
   */
  public function testGetPostAuthorLinksBuildsTitleAndUrlPairs(): void {
    $author = $this->createMock(NodeInterface::class);
    $author->method('getTitle')->willReturn('Jane Doe');
    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn('/profiles/jane-doe');
    $author->method('toUrl')->willReturn($url);

    $reference = (object) ['entity' => $author];

    $reflection = new \ReflectionClass($this->block);
    $method = $reflection->getMethod('getPostAuthorLinks');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->block, [$reference]);

    $this->assertSame([
      ['title' => 'Jane Doe', 'url' => '/profiles/jane-doe', 'isLink' => TRUE],
    ], $result);
  }

  /**
   * An empty/falsy reference list produces no author links.
   *
   * @covers ::getPostAuthorLinks
   */
  public function testGetPostAuthorLinksWithNoReferencesReturnsEmpty(): void {
    $reflection = new \ReflectionClass($this->block);
    $method = $reflection->getMethod('getPostAuthorLinks');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->block, NULL);

    $this->assertSame([], $result);
  }

  /**
   * Builds a post node whose magic field reads resolve.
   *
   * Node is mocked as the concrete class so build()'s magic property reads
   * (e.g. $node->field_publish_date) resolve through a stubbed __get.
   *
   * @param string $title
   *   The node title.
   *
   * @return \Drupal\node\Entity\Node|\PHPUnit\Framework\MockObject\MockObject
   *   The node mock.
   */
  protected function mockPostNode(string $title) {
    $item = $this->createMock(FieldItemInterface::class);
    $item->method('getValue')->willReturn(['value' => '2024-05-01T12:00:00']);
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('first')->willReturn($item);

    $node = $this->createMock(Node::class);
    $node->method('bundle')->willReturn('post');
    $node->method('getTitle')->willReturn($title);
    // field_authors is read as a plain list and iterated, so leave it unset;
    // every other field build() touches resolves to the stubbed item.
    $node->method('__get')->willReturnCallback(
      fn (string $name) => $name === 'field_authors' ? NULL : $field
    );

    return $node;
  }

  /**
   * The entity being rendered beats a request that names no node at all.
   *
   * This is the reported bug: /node/add/post (every new post's first save) and
   * a bulk operation from /admin/content carry no node on the request, so the
   * block rendered nothing and the post's heading was missing from what Beacon
   * indexes.
   *
   * There is no route object at all when Search API indexes from cron or from
   * drush, and the block used to gate every field read -- including the title
   * -- on one, so the heading was blank on the path that does most of the
   * indexing.
   *
   * @covers ::build
   */
  public function testBuildUsesRenderedEntityWhenRequestHasNoNode(): void {
    $this->requestStack->method('getCurrentRequest')->willReturn(new Request());
    $this->dateFormatter->method('format')->willReturn('2024-05-01');

    $this->setRenderedEntity($this->block, $this->mockPostNode('My Post'));

    $build = $this->block->build();

    $this->assertSame('ys_post_meta_block', $build['#theme']);
    $this->assertSame('My Post', $build['#label']);
  }

  /**
   * The entity being rendered beats a DIFFERENT node named by the request.
   *
   * @covers ::build
   */
  public function testBuildPrefersRenderedEntityOverRequestNode(): void {
    $other = $this->createMock(NodeInterface::class);
    $other->method('bundle')->willReturn('post');
    $request = new Request();
    $request->attributes->set('node', $other);
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    $this->dateFormatter->method('format')->willReturn('2024-05-01');

    $this->setRenderedEntity($this->block, $this->mockPostNode('My Post'));

    $this->assertSame('My Post', $this->block->build()['#label']);
  }

  /**
   * A context holding something other than a node falls through to the request.
   *
   * Layout Builder hands over whatever entity the display belongs to, so the
   * context is not guaranteed to hold a node -- the defaults layout screen
   * passes a generated sample entity of the display's own type.
   *
   * @covers ::build
   *
   * @dataProvider providerNonNodeContextValues
   */
  public function testBuildIgnoresNonNodeEntityContext($value): void {
    // The request node must be a real POST, so the assertion can only pass by
    // falling through to it. Asserting an empty render array instead would
    // pass whether or not the guard works: an EntityInterface mock's bundle()
    // returns '', which fails the bundle check too.
    $request = new Request();
    $request->attributes->set('node', $this->mockPostNode('From The Request'));
    $this->requestStack->method('getCurrentRequest')->willReturn($request);
    $this->dateFormatter->method('format')->willReturn('2024-05-01');

    $this->setRenderedEntity($this->block, $value);

    $this->assertSame('From The Request', $this->block->build()['#label']);
  }

  /**
   * The ANNOTATION declares the slot Layout Builder actually publishes.
   */
  public function testAnnotationDeclaresTheLayoutBuilderEntitySlot(): void {
    $this->assertDeclaresLayoutBuilderEntitySlot(PostMetaBlock::class);
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
   * A reference whose entity is gone produces no author link.
   *
   * A reference item is left with no entity behind it when the referenced
   * profile is deleted. Reading ->getTitle() off that NULL used to take the
   * page down.
   *
   * @covers ::getPostAuthorLinks
   */
  public function testGetPostAuthorLinksSkipsMissingEntities(): void {
    $author = $this->createMock(NodeInterface::class);
    $author->method('getTitle')->willReturn('Jane Doe');
    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn('/profiles/jane-doe');
    $author->method('toUrl')->willReturn($url);

    $references = [(object) ['entity' => NULL], (object) ['entity' => $author]];

    $reflection = new \ReflectionClass($this->block);
    $method = $reflection->getMethod('getPostAuthorLinks');
    $method->setAccessible(TRUE);

    $this->assertSame([
      ['title' => 'Jane Doe', 'url' => '/profiles/jane-doe', 'isLink' => TRUE],
    ], $method->invoke($this->block, $references));
  }

  /**
   * An unsaved sample entity does not stand in for real content.
   *
   * Layout Builder's Defaults layout screen
   * (/admin/structure/types/manage/post/display/default/layout) offers a
   * generated sample entity that core never saves -- see
   * LayoutBuilderSampleEntityGenerator::get(), which calls
   * createWithSampleValues() and stashes the result in a tempstore. It has no
   * ID, so anything deriving a URL from it throws. Before this block declared
   * a context it simply rendered nothing there; it must keep doing so.
   *
   * @covers ::build
   */
  public function testBuildIgnoresUnsavedSampleEntity(): void {
    $this->requestStack->method('getCurrentRequest')->willReturn(new Request());

    $sample = $this->mockPostNode('Sample Post');
    $sample->method('isNew')->willReturn(TRUE);
    $this->setRenderedEntity($this->block, $sample);

    $this->assertSame([], $this->block->build());
  }

  /**
   * A post with no publish date still renders its heading.
   *
   * The field_publish_date field is required in config, so this does not
   * happen on content created through the form -- but it can on content
   * created programmatically, by a migration, or before the field was made
   * required.
   * Every sibling field read in build() already guards first(); this one did
   * not, and dereferencing NULL there would now take down an indexing run,
   * because removing the route gate is what lets build() reach these reads
   * with no route object at all.
   *
   * @covers ::build
   */
  public function testBuildRendersHeadingWhenPublishDateIsEmpty(): void {
    $this->requestStack->method('getCurrentRequest')->willReturn(new Request());

    $empty = $this->createMock(FieldItemListInterface::class);
    $empty->method('first')->willReturn(NULL);
    $node = $this->createMock(Node::class);
    $node->method('bundle')->willReturn('post');
    $node->method('getTitle')->willReturn('Undated Post');
    $node->method('__get')->willReturnCallback(
      fn (string $name) => $name === 'field_authors' ? NULL : $empty
    );
    $this->dateFormatter->expects($this->never())->method('format');

    $this->setRenderedEntity($this->block, $node);

    $build = $this->block->build();

    $this->assertSame('Undated Post', $build['#label']);
    $this->assertNull($build['#date_formatted']);
  }

}
