<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\Datawrapper;

/**
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSource\Datawrapper
 *
 * @group yalesites
 * @group ys_embed
 */
class DatawrapperTest extends UnitTestCase {

  /**
   * A Datawrapper responsive iframe embed code, with its trailing script.
   *
   * @var string
   */
  const VALID_EMBED = '<iframe title="Impact by state" aria-label="Map" id="datawrapper-chart-aB3dE" src="https://datawrapper.dwcdn.net/aB3dE/2/" scrolling="no" frameborder="0" style="width: 0; min-width: 100% !important; border: none;" height="500" data-external="1"></iframe><script type="text/javascript">window.addEventListener("message",function(a){});</script>';

  /**
   * The plugin instance.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSource\Datawrapper
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
    $this->plugin = new Datawrapper([], 'datawrapper', [], $configFactory);
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsDatawrapperEmbed(): void {
    $this->assertTrue(Datawrapper::isValid(self::VALID_EMBED));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsOtherHosts(): void {
    $this->assertFalse(Datawrapper::isValid('<iframe src="https://evil.com/aB3dE/2/"></iframe>'));
    $this->assertFalse(Datawrapper::isValid('<iframe src="https://datawrapper.dwcdn.net.evil.com/aB3dE/2/"></iframe>'));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsMalformedPath(): void {
    $this->assertFalse(Datawrapper::isValid('<iframe src="https://datawrapper.dwcdn.net/aB3dE/"></iframe>'));
    $this->assertFalse(Datawrapper::isValid('<iframe src="https://datawrapper.dwcdn.net/a"b/2/"></iframe>'));
  }

  /**
   * @covers ::getParams
   * @covers ::getUrl
   */
  public function testGetUrlRebuildsFromChartIdAndVersion(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $this->assertSame('aB3dE', $params['chart_id']);
    $this->assertSame('2', $params['version']);
    $this->assertSame('https://datawrapper.dwcdn.net/aB3dE/2/', $this->plugin->getUrl($params));
  }

  /**
   * @covers ::build
   */
  public function testBuildUsesProvidedTitle(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $params['title'] = 'Impact by state';
    $build = $this->plugin->build($params);
    $this->assertSame('Impact by state', $build['#title']);
    $this->assertTrue($build['#displayAttributes']['isIframe']);
  }

}
