<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\Twitter;

/**
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSource\Twitter
 *
 * @group yalesites
 * @group ys_embed
 */
class TwitterTest extends UnitTestCase {

  /**
   * A real X/Twitter blockquote embed, taken from the plugin's own example.
   *
   * @var string
   */
  const VALID_EMBED = '<blockquote class="twitter-tweet"><p lang="en" dir="ltr">Yale news.</p>&mdash; Yale University (@Yale) <a href="https://twitter.com/Yale/status/1586724355089776640">October 30, 2022</a></blockquote> <script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>';

  /**
   * The Twitter plugin instance.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSource\Twitter
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
    $this->plugin = new Twitter([], 'twitter', [], $configFactory);
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsRealEmbedCode(): void {
    $this->assertTrue(Twitter::isValid(self::VALID_EMBED));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsMissingWidgetsScript(): void {
    $this->assertFalse(Twitter::isValid('<blockquote class="twitter-tweet"><p>test</p></blockquote>'));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsContentBeforeBlockquote(): void {
    $this->assertFalse(Twitter::isValid('<p>intro</p><blockquote class="twitter-tweet"><p>test</p></blockquote><script src="https://platform.twitter.com/widgets.js"></script>'));
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsCapturesBlockquote(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $this->assertStringStartsWith('<blockquote class="twitter-tweet">', $params['blockquote']);
    $this->assertStringEndsWith('</blockquote>', $params['blockquote']);
  }

  /**
   * @covers ::build
   */
  public function testBuildTitleIsEmptyWhenBlank(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $this->assertSame('', $build['#title']);
  }

  /**
   * @covers ::build
   */
  public function testBuildContextIncludesBlockquote(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $params['title'] = '';
    $build = $this->plugin->build($params);
    // The blockquote reaches the template as filtered markup, so the template
    // prints it without the raw filter.
    $this->assertInstanceOf(MarkupInterface::class, $build['#embedSource']['#context']['blockquote']);
    $this->assertStringStartsWith('<blockquote class="twitter-tweet">', (string) $build['#embedSource']['#context']['blockquote']);
    $this->assertStringContainsString('{{ blockquote }}', $build['#embedSource']['#template']);
    $this->assertStringNotContainsString('|raw', $build['#embedSource']['#template']);
  }

  /**
   * @covers ::build
   */
  public function testBuildDisplayAttributesAreNotIframe(): void {
    // Twitter does not override $displayAttributes or use an "iframe" tag in
    // its template, so the base class isIframe() computation resolves FALSE.
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $this->assertFalse($build['#displayAttributes']['isIframe']);
    $this->assertSame('form', $build['#displayAttributes']['embed_type']);
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsPluginExample(): void {
    $this->assertTrue(Twitter::isValid(Twitter::getExample()));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsEventHandlerInBlockquote(): void {
    $this->assertFalse(Twitter::isValid('<blockquote class="twitter-tweet"><p>hi<img src="x" onerror="alert(1)"></p></blockquote> <script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>'));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsScriptInBlockquote(): void {
    $this->assertFalse(Twitter::isValid('<blockquote class="twitter-tweet"><p>hi</p><script>alert(1)</script></blockquote> <script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>'));
  }

  /**
   * An on* attribute after a quoted value containing ">" is still rejected.
   *
   * @covers ::isValid
   */
  public function testIsValidRejectsEventHandlerAfterGreaterThanInValue(): void {
    $this->assertFalse(Twitter::isValid('<blockquote class="twitter-tweet"><a title="x>y" onmouseover="alert(1)" href="https://twitter.com/a">t</a></blockquote><script async src="https://platform.twitter.com/widgets.js"></script>'));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsUppercaseEventHandler(): void {
    $this->assertFalse(Twitter::isValid('<blockquote class="twitter-tweet"><p>hi<img src="x" ONCLICK="alert(1)"></p></blockquote> <script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>'));
  }

  /**
   * Stored markup that predates validation is sanitized at output.
   *
   * @covers ::build
   */
  public function testBuildSanitizesStoredMarkup(): void {
    $params = ['blockquote' => '<blockquote class="twitter-tweet"><p>hi<img src="x" onerror="alert(1)"></p><script>alert(1)</script></blockquote>'];
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
      'blockquote' => '<blockquote class="twitter-tweet"><p><a href="https://evil.example">click</blockquote>',
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
    $params = $this->plugin->getParams(Twitter::getExample());
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $markup = (string) $build['#embedSource']['#context']['blockquote'];
    $this->assertStringNotContainsString('|raw', $build['#embedSource']['#template']);
    $this->assertStringContainsString('<blockquote class="twitter-tweet">', $markup);
    $this->assertStringContainsString('<p lang="en" dir="ltr">', $markup);
    $this->assertStringContainsString('href="https://twitter.com/Yale/status/1586724355089776640?ref_src=twsrc%5Etfw"', $markup);
    $this->assertStringContainsString('</blockquote>', $markup);
  }

}
