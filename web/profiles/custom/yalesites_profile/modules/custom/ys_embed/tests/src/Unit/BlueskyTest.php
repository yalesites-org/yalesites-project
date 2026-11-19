<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\Bluesky;

/**
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSource\Bluesky
 *
 * @group yalesites
 */
class BlueskyTest extends UnitTestCase {

  /**
   * The Bluesky plugin instance.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSource\Bluesky
   */
  protected $plugin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $configFactory = $this->getConfigFactoryStub([
      'media.settings' => ['icon_base_uri' => 'public://media-icons'],
    ]);
    $this->plugin = new Bluesky([], 'bluesky', [], $configFactory);
  }

  /**
   * @covers ::build
   */
  public function testBuildFallsBackToDefaultTitleWhenBlank(): void {
    $params = ['title' => ''];
    $build = $this->plugin->build($params);
    $this->assertSame('Bluesky post embed', $build['#title']);
  }

  /**
   * @covers ::build
   */
  public function testBuildUsesProvidedTitleOverDefault(): void {
    $params = ['title' => 'Custom Bluesky title'];
    $build = $this->plugin->build($params);
    $this->assertSame('Custom Bluesky title', $build['#title']);
  }

  /**
   * @covers ::build
   */
  public function testBuildPreservesLiteralZeroTitle(): void {
    $params = ['title' => '0'];
    $build = $this->plugin->build($params);
    $this->assertSame('0', $build['#title']);
  }

  /**
   * @covers ::build
   */
  public function testBuildFallsBackToDefaultTitleWhenWhitespaceOnly(): void {
    $params = ['title' => '   '];
    $build = $this->plugin->build($params);
    $this->assertSame('Bluesky post embed', $build['#title']);
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsPluginExample(): void {
    $this->assertTrue(Bluesky::isValid(Bluesky::getExample()));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsEventHandlerInBlockquote(): void {
    $this->assertFalse(Bluesky::isValid('<blockquote class="bluesky-embed" data-bluesky-uri="at://did:plc:abc/app.bsky.feed.post/xyz"><p>hi<img src="x" onerror="alert(1)"></p></blockquote><script async src="https://embed.bsky.app/static/embed.js" charset="utf-8"></script>'));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsScriptInBlockquote(): void {
    $this->assertFalse(Bluesky::isValid('<blockquote class="bluesky-embed" data-bluesky-uri="at://did:plc:abc/app.bsky.feed.post/xyz"><p>hi</p><script>alert(1)</script></blockquote><script async src="https://embed.bsky.app/static/embed.js" charset="utf-8"></script>'));
  }

  /**
   * An on* attribute after a quoted value containing ">" is still rejected.
   *
   * @covers ::isValid
   */
  public function testIsValidRejectsEventHandlerAfterGreaterThanInValue(): void {
    $this->assertFalse(Bluesky::isValid('<blockquote class="bluesky-embed" data-bluesky-uri="at://did:plc:abc/app.bsky.feed.post/xyz"><a title="x>y" onmouseover="alert(1)" href="https://bsky.app/profile/a">t</a></blockquote><script async src="https://embed.bsky.app/static/embed.js" charset="utf-8"></script>'));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsUppercaseEventHandler(): void {
    $this->assertFalse(Bluesky::isValid('<blockquote class="bluesky-embed" data-bluesky-uri="at://did:plc:abc/app.bsky.feed.post/xyz"><p>hi<img src="x" ONCLICK="alert(1)"></p></blockquote><script async src="https://embed.bsky.app/static/embed.js" charset="utf-8"></script>'));
  }

  /**
   * Stored markup that predates validation is sanitized at output.
   *
   * @covers ::build
   */
  public function testBuildSanitizesStoredMarkup(): void {
    $params = ['blockquote' => '<blockquote class="bluesky-embed"><p>hi<img src="x" onerror="alert(1)"></p><script>alert(1)</script></blockquote>'];
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $markup = $build['#embedSource']['#context']['blockquote'];
    $this->assertInstanceOf(MarkupInterface::class, $markup);
    $this->assertStringNotContainsStringIgnoringCase('onerror', (string) $markup);
    $this->assertStringNotContainsStringIgnoringCase('<script', (string) $markup);
  }

  /**
   * Unclosed tags in stored markup are closed inside the blockquote.
   *
   * @covers ::build
   */
  public function testBuildClosesUnclosedTags(): void {
    $params = [
      'blockquote' => '<blockquote class="bluesky-embed"><p><a href="https://evil.example">click</blockquote>',
      'title' => '',
    ];
    $build = $this->plugin->build($params);
    $markup = (string) $build['#embedSource']['#context']['blockquote'];
    $this->assertStringContainsString('click</a></p></blockquote>', $markup);
  }

  /**
   * The provider's own example keeps the markup its embed script needs.
   *
   * @covers ::build
   */
  public function testBuildKeepsExampleMarkup(): void {
    $params = $this->plugin->getParams(Bluesky::getExample());
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $markup = (string) $build['#embedSource']['#context']['blockquote'];
    $this->assertStringNotContainsString('|raw', $build['#embedSource']['#template']);
    $this->assertStringContainsString('<blockquote class="bluesky-embed"', $markup);
    $this->assertStringContainsString('data-bluesky-uri="at://did:plc:hptrw4dge4l5q4h5pl7wpw74/app.bsky.feed.post/3ltporonlrt2z"', $markup);
    $this->assertStringContainsString('data-bluesky-cid="bafyreiera75ojf45nkakh3piwhu3rldjzobppl7tq3nlgsmxx5zq2msiga"', $markup);
    $this->assertStringContainsString('data-bluesky-embed-color-mode="system"', $markup);
    $this->assertStringContainsString('href="https://bsky.app/profile/did:plc:hptrw4dge4l5q4h5pl7wpw74/post/3ltporonlrt2z?ref_src=embed"', $markup);
    $this->assertStringContainsString('<br', $markup);
    $this->assertStringContainsString('</blockquote>', $markup);
  }

}
