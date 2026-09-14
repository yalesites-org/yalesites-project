<?php

namespace Drupal\Tests\ys_embed\Unit;

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
    $this->assertSame($params['blockquote'], $build['#embedSource']['#context']['blockquote']);
    $this->assertStringContainsString('{{ blockquote|raw }}', $build['#embedSource']['#template']);
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

}
