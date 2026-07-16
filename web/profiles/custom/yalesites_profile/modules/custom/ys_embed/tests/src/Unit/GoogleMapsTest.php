<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\GoogleMaps;

/**
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSource\GoogleMaps
 *
 * @group yalesites
 * @group ys_embed
 */
class GoogleMapsTest extends UnitTestCase {

  /**
   * A real Google Maps embed code, taken from the plugin's own example.
   *
   * @var string
   */
  const VALID_EMBED = '<iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d5993.31404257508!2d-72.92491802386455!3d41.316324371308916!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x89e7d9b6cd624945%3A0xae34a2c4b4d30427!2sYale%20University!5e0!3m2!1sen!2sca!4v1746124034200!5m2!1sen!2sca" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>';

  /**
   * The GoogleMaps plugin instance.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSource\GoogleMaps
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
    $this->plugin = new GoogleMaps([], 'google_maps', [], $configFactory);
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsRealEmbedCode(): void {
    $this->assertTrue(GoogleMaps::isValid(self::VALID_EMBED));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsNonGoogleDomain(): void {
    $this->assertFalse(GoogleMaps::isValid('<iframe src="https://maps.evil.com/maps/embed?pb=x"></iframe>'));
  }

  /**
   * CHARACTERIZATION: a multi-line Google Maps iframe fails to validate.
   *
   * The pattern omits the "s" (DOTALL) modifier that the sibling
   * GoogleCalendar plugin uses for the same shape of iframe match, so any
   * embed code copied from a source that wraps attributes onto separate
   * lines is silently rejected.
   *
   * Paired with testMatchShouldAcceptMultilineIframe() -- delete once the
   * GAP is fixed.
   *
   * @covers ::isValid
   */
  public function testIsValidRejectsIframeWithNewlineBeforeSrc(): void {
    $multiline = "<iframe\nsrc=\"https://www.google.com/maps/embed?pb=abc\" width=\"600\"></iframe>";
    $this->assertFalse(GoogleMaps::isValid($multiline));
  }

  /**
   * GAP: GoogleMaps::isValid() should accept a multi-line iframe.
   *
   * It should match the same way GoogleCalendar::isValid() does, by adding
   * the "s" (DOTALL) modifier to $pattern so "." matches across newlines --
   * see ~/Documents/Claude/not_dave/module-tests-20260710/ys_embed.md.
   */
  public function testMatchShouldAcceptMultilineIframe(): void {
    $this->markTestSkipped('GAP: GoogleMaps::isValid() rejects a multi-line iframe because $pattern is missing the "s" (DOTALL) modifier that GoogleCalendar uses -- see ~/Documents/Claude/not_dave/module-tests-20260710/ys_embed.md');
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsCapturesMapParams(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $this->assertStringStartsWith('?pb=!1m18!1m12', $params['map_params']);
  }

  /**
   * @covers ::getUrl
   */
  public function testGetUrlBuildsFromMapParams(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $this->assertSame(
      'https://www.google.com/maps/embed' . $params['map_params'],
      $this->plugin->getUrl($params)
    );
  }

  /**
   * @covers ::build
   */
  public function testBuildTitleIsEmptyWhenBlank(): void {
    // No PHP-level $defaultTitle override; the template's own {{ title }}
    // placeholder has no default filter at all, so a blank title stays
    // blank both here and at render time.
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $this->assertSame('', $build['#title']);
  }

  /**
   * @covers ::build
   */
  public function testBuildUsesProvidedTitle(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $params['title'] = 'Campus Map';
    $build = $this->plugin->build($params);
    $this->assertSame('Campus Map', $build['#title']);
  }

  /**
   * @covers ::build
   */
  public function testBuildDisplayAttributesMarkIframe(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $this->assertTrue($build['#displayAttributes']['isIframe']);
    $this->assertSame('map', $build['#displayAttributes']['embedType']);
  }

}
