<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\JWPlayer;

/**
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSource\JWPlayer
 *
 * @group yalesites
 * @group ys_embed
 */
class JWPlayerTest extends UnitTestCase {

  /**
   * A single-line JW Player iframe embed, matching the documented pattern.
   *
   * @var string
   */
  const VALID_EMBED = '<iframe allowfullscreen="" frameborder="0" src="https://content.jwplatform.com/players/2sVMfwDJ-XjbdEvEx.html" width="980"></iframe>';

  /**
   * The JWPlayer plugin instance.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSource\JWPlayer
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
    $this->plugin = new JWPlayer([], 'jwplayer', [], $configFactory);
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsSingleLineIframe(): void {
    $this->assertTrue(JWPlayer::isValid(self::VALID_EMBED));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsCdnJwplayerDomain(): void {
    $this->assertTrue(JWPlayer::isValid('<iframe src="https://cdn.jwplayer.com/players/xyz789.html"></iframe>'));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsNonJwplayerDomain(): void {
    $this->assertFalse(JWPlayer::isValid('<iframe src="https://evil.com/players/abc.html"></iframe>'));
  }

  /**
   * Tests is valid rejects own documented example.
   *
   * CHARACTERIZATION: the plugin's own getExample() embed code does not
   * actually validate, because the example wraps the "src" attribute onto a
   * new line while $pattern has no "s" (DOTALL) modifier -- "." cannot cross
   * the newline between "<iframe ...\n" and "src=...". An editor who copies
   * the documented example verbatim gets a validation failure.
   *
   * Paired with testMatchShouldAcceptDocumentedExample() -- delete once the
   * GAP is fixed.
   *
   * @covers ::isValid
   * @covers ::getExample
   */
  public function testIsValidRejectsOwnDocumentedExample(): void {
    $this->assertFalse(JWPlayer::isValid(JWPlayer::getExample()));
  }

  /**
   * Tests match should accept documented example.
   *
   * GAP: JWPlayer::getExample() should produce an embed code that
   * JWPlayer::isValid() accepts, since it is shown to editors as the
   * canonical example. Either add the "s" modifier to $pattern (matching how
   * GoogleCalendar handles multi-line iframes) or reflow the example onto a
   * single line. See the module's test log for details.
   */
  public function testMatchShouldAcceptDocumentedExample(): void {
    $this->markTestSkipped('GAP: JWPlayer::getExample() embed code fails JWPlayer::isValid() because $pattern lacks the "s" (DOTALL) modifier needed for its multi-line src attribute -- see ~/Documents/Claude/not_dave/module-tests-20260710/ys_embed.md');
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsCapturesUrl(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $this->assertSame('https://content.jwplatform.com/players/2sVMfwDJ-XjbdEvEx.html', $params['url']);
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
  public function testBuildDisplayAttributesMarkIframeVideo(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $this->assertTrue($build['#displayAttributes']['isIframe']);
    $this->assertSame('video', $build['#displayAttributes']['embedType']);
    $this->assertTrue($build['#displayAttributes']['allowfullscreen']);
  }

  /**
   * @covers ::build
   */
  public function testBuildContextIncludesUrl(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $this->assertSame('https://content.jwplatform.com/players/2sVMfwDJ-XjbdEvEx.html', $build['#embedSource']['#context']['url']);
  }

}
