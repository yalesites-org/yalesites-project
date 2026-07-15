<?php

namespace Drupal\Tests\ys_layouts\Unit;

use Drupal\Core\Controller\TitleResolver;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
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

    $this->routeMatch = $this->createMock(RouteMatchInterface::class);
    $this->titleResolver = $this->createMock(TitleResolver::class);
    $this->requestStack = $this->createMock(RequestStack::class);
    $this->dateFormatter = $this->createMock(DateFormatter::class);

    $this->block = new PostMetaBlock(
      [],
      'post_meta_block',
      ['provider' => 'ys_layouts'],
      $this->routeMatch,
      $this->titleResolver,
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

}
