<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\Localist;

/**
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSource\Localist
 *
 * @group yalesites
 * @group ys_embed
 */
class LocalistTest extends UnitTestCase {

  /**
   * A real Localist widget embed code, taken from the plugin's own example.
   *
   * @var string
   */
  const VALID_EMBED = '<div id="localist-widget-86302562" class="localist-widget"></div><script defer type="text/javascript" src="https://yale.enterprise.localist.com/widget/view?schools=yale&groups=asian-network-at-yale&days=31&num=50&experience=inperson&container=localist-widget-86302562&template=modern"></script><div id="lclst_widget_footer"></div>';

  /**
   * The Localist plugin instance.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSource\Localist
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
    $this->plugin = new Localist([], 'localist', [], $configFactory);
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsRealEmbedCode(): void {
    $this->assertTrue(Localist::isValid(self::VALID_EMBED));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsMissingScriptTag(): void {
    $this->assertFalse(Localist::isValid('<div id="localist-widget-123" class="localist-widget"></div>'));
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsCapturesWidgetIdAndSource(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $this->assertSame('86302562', $params['widget_id']);
    $this->assertStringStartsWith('https://yale.enterprise.localist.com/widget/view', $params['localist_source']);
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
  public function testBuildContextIncludesWidgetIdAndSource(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $this->assertSame('86302562', $build['#embedSource']['#context']['widget_id']);
    $this->assertStringStartsWith('https://yale.enterprise.localist.com', $build['#embedSource']['#context']['localist_source']);
  }

  /**
   * @covers ::build
   */
  public function testBuildDisplayAttributesAreNotIframe(): void {
    // Localist does not override $displayAttributes or $template with an
    // "iframe" tag, so the base class isIframe() computation applies and
    // resolves to FALSE, unlike most of the other embed sources.
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $this->assertFalse($build['#displayAttributes']['isIframe']);
    $this->assertSame('form', $build['#displayAttributes']['embed_type']);
  }

}
