<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\Instagram;

/**
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSource\Instagram
 *
 * @group yalesites
 */
class InstagramTest extends UnitTestCase {

  /**
   * The Instagram plugin instance.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSource\Instagram
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
    $this->plugin = new Instagram([], 'instagram', [], $configFactory);
  }

  /**
   * @covers ::build
   */
  public function testBuildFallsBackToDefaultTitleWhenBlank(): void {
    $params = ['title' => ''];
    $build = $this->plugin->build($params);
    $this->assertSame('Instagram post embed', $build['#title']);
  }

  /**
   * @covers ::build
   */
  public function testBuildUsesProvidedTitleOverDefault(): void {
    $params = ['title' => 'Custom Instagram title'];
    $build = $this->plugin->build($params);
    $this->assertSame('Custom Instagram title', $build['#title']);
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
    $this->assertSame('Instagram post embed', $build['#title']);
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsPluginExample(): void {
    $this->assertTrue(Instagram::isValid(Instagram::getExample()));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsEventHandlerInBlockquote(): void {
    $this->assertFalse(Instagram::isValid('<blockquote class="instagram-media" data-instgrm-permalink="https://www.instagram.com/p/abc/"><a href="https://www.instagram.com/p/abc/" onmouseover="alert(1)">x</a></blockquote> <script async src="//www.instagram.com/embed.js"></script>'));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsScriptInBlockquote(): void {
    $this->assertFalse(Instagram::isValid('<blockquote class="instagram-media" data-instgrm-permalink="https://www.instagram.com/p/abc/"><a href="https://www.instagram.com/p/abc/">x</a><script>alert(1)</script></blockquote> <script async src="//www.instagram.com/embed.js"></script>'));
  }

  /**
   * An on* attribute after a quoted value containing ">" is still rejected.
   *
   * @covers ::isValid
   */
  public function testIsValidRejectsEventHandlerAfterGreaterThanInValue(): void {
    $this->assertFalse(Instagram::isValid('<blockquote class="instagram-media" data-instgrm-permalink="https://www.instagram.com/p/abc/"><a title="x>y" onmouseover="alert(1)" href="https://www.instagram.com/p/abc/">x</a></blockquote> <script async src="//www.instagram.com/embed.js"></script>'));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsUppercaseEventHandler(): void {
    $this->assertFalse(Instagram::isValid('<blockquote class="instagram-media" data-instgrm-permalink="https://www.instagram.com/p/abc/"><a href="https://www.instagram.com/p/abc/" ONCLICK="alert(1)">x</a></blockquote> <script async src="//www.instagram.com/embed.js"></script>'));
  }

  /**
   * Stored markup that predates validation is sanitized at output.
   *
   * @covers ::build
   */
  public function testBuildSanitizesStoredMarkup(): void {
    $params = ['embed_code' => ' class="instagram-media"><a href="https://www.instagram.com/p/abc/"><img src="x" onerror="alert(1)"></a><script>alert(1)</script>'];
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $markup = $build['#embedSource']['#context']['blockquote'];
    $this->assertInstanceOf(MarkupInterface::class, $markup);
    $this->assertStringNotContainsStringIgnoringCase('onerror', (string) $markup);
    $this->assertStringNotContainsStringIgnoringCase('<script', (string) $markup);
  }

  /**
   * The provider's own example keeps the markup its embed script needs.
   *
   * @covers ::build
   */
  public function testBuildKeepsExampleMarkup(): void {
    $params = $this->plugin->getParams(Instagram::getExample());
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $markup = (string) $build['#embedSource']['#context']['blockquote'];
    $this->assertStringNotContainsString('|raw', $build['#embedSource']['#template']);
    $this->assertStringContainsString('<blockquote class="instagram-media"', $markup);
    $this->assertStringContainsString('data-instgrm-captioned', $markup);
    $this->assertStringContainsString('data-instgrm-permalink="https://www.instagram.com/p/CjtF3h0Ohhe/?utm_source=ig_embed&amp;utm_campaign=loading"', $markup);
    $this->assertStringContainsString('data-instgrm-version="14"', $markup);
    $this->assertStringContainsString('href="https://www.instagram.com/p/CjtF3h0Ohhe/', $markup);
    $this->assertStringContainsString('A post shared by Yale (@yale)', $markup);
    $this->assertStringContainsString('</blockquote>', $markup);
  }

}
