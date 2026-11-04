<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\GoogleMaps;
use Drupal\ys_embed\Plugin\EmbedSource\GoogleMyMaps;

/**
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSource\GoogleMyMaps
 *
 * @group yalesites
 * @group ys_embed
 */
class GoogleMyMapsTest extends UnitTestCase {

  /**
   * The My Maps embed code from the issue's requester.
   *
   * @var string
   */
  const VALID_EMBED = '<iframe src="https://www.google.com/maps/d/embed?mid=1ElTerPyfcAm31qLlbKz_SebWiubAzhg&hl=en&ehbc=2E312F" width="640" height="480"></iframe>';

  /**
   * The plugin instance.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSource\GoogleMyMaps
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
    $this->plugin = new GoogleMyMaps([], 'google_my_maps', [], $configFactory);
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsMyMapsEmbed(): void {
    $this->assertTrue(GoogleMyMaps::isValid(self::VALID_EMBED));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsSingleLocationMapsEmbed(): void {
    $this->assertFalse(GoogleMyMaps::isValid('<iframe src="https://www.google.com/maps/embed?pb=!1m18"></iframe>'));
  }

  /**
   * Single-location Google Maps still rejects My Maps and is unchanged.
   *
   * @covers \Drupal\ys_embed\Plugin\EmbedSource\GoogleMaps::isValid
   */
  public function testSingleLocationPluginStillRejectsMyMaps(): void {
    $this->assertFalse(GoogleMaps::isValid(self::VALID_EMBED));
    $this->assertTrue(GoogleMaps::isValid('<iframe src="https://www.google.com/maps/embed?pb=!1m18" width="600"></iframe>'));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsNonGoogleDomain(): void {
    $this->assertFalse(GoogleMyMaps::isValid('<iframe src="https://evil.com/maps/d/embed?mid=abc"></iframe>'));
    $this->assertFalse(GoogleMyMaps::isValid('<iframe src="https://www.google.com.evil.com/maps/d/embed?mid=abc"></iframe>'));
  }

  /**
   * @covers ::getUrl
   */
  public function testGetUrlRebuildsMyMapsUrl(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $this->assertSame('https://www.google.com/maps/d/embed?mid=1ElTerPyfcAm31qLlbKz_SebWiubAzhg&hl=en&ehbc=2E312F', $this->plugin->getUrl($params));
  }

  /**
   * @covers ::build
   */
  public function testBuildUsesProvidedTitle(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $params['title'] = 'Campus map';
    $build = $this->plugin->build($params);
    $this->assertSame('Campus map', $build['#title']);
    $this->assertTrue($build['#displayAttributes']['isIframe']);
  }

}
