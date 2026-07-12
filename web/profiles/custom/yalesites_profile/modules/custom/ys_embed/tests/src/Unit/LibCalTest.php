<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\LibCal;

/**
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSource\LibCal
 *
 * @group yalesites
 * @group ys_embed
 */
class LibCalTest extends UnitTestCase {

  /**
   * A real LibCal embed code, taken from the plugin's own example.
   *
   * @var string
   */
  const VALID_EMBED = <<<EMBED
  <script src="https://schedule.yale.edu/js/hours_today.js"></script>
  <div id="s_lc_tdh_457_4216"></div>
  <script>\$(function(){var s_lc_tdh_457_4216 = new \$.LibCalTodayHours( \$("#s_lc_tdh_457_4216"), { iid: 457, lid: 4216 });
  });</script>
  EMBED;

  /**
   * The LibCal plugin instance.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSource\LibCal
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
    $this->plugin = new LibCal([], 'libcal', [], $configFactory);
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsRealEmbedCode(): void {
    $this->assertTrue(LibCal::isValid(self::VALID_EMBED));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsNonYaleDomain(): void {
    $this->assertFalse(LibCal::isValid('<script src="https://evil.com/js/hours_today.js"></script><div id="x"></div><script>foo();</script>'));
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsCapturesEmbedCode(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $this->assertStringContainsString('schedule.yale.edu', $params['embed_code']);
    $this->assertStringContainsString('s_lc_tdh_457_4216', $params['embed_code']);
  }

  /**
   * @covers ::build
   */
  public function testBuildReturnsStaticMarkupContainer(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $build = $this->plugin->build($params);
    $this->assertSame('<div class="embed-libcal"></div>', $build['#markup']);
  }

  /**
   * @covers ::build
   */
  public function testBuildAttachesEmbedCodeToDrupalSettings(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $build = $this->plugin->build($params);
    $this->assertSame(
      $params['embed_code'],
      $build['#attached']['drupalSettings']['ysEmbed']['libcalEmbedCode']
    );
  }

  /**
   * @covers ::build
   */
  public function testBuildAttachesLibcalLibrary(): void {
    $build = $this->plugin->build(['embed_code' => 'anything']);
    $this->assertContains('ys_embed/libcal', $build['#attached']['library']);
  }

  /**
   * @covers ::build
   */
  public function testBuildDefaultsToEmptyStringWhenEmbedCodeMissing(): void {
    $build = $this->plugin->build([]);
    $this->assertSame('', $build['#attached']['drupalSettings']['ysEmbed']['libcalEmbedCode']);
  }

}
