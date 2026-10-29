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

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsPluginExample(): void {
    $this->assertTrue(Localist::isValid(Localist::getExample()));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsNonEnterpriseLocalistHost(): void {
    $this->assertTrue(Localist::isValid(self::embedWithSource('https://yale.localist.com/widget/view?container=localist-widget-1')));
  }

  /**
   * Yale's own Localist host, the ys_localist default endpoint, validates.
   *
   * @covers ::isValid
   */
  public function testIsValidAcceptsYaleEventsHost(): void {
    $this->assertTrue(Localist::isValid(self::embedWithSource('https://events.yale.edu/widget/view?container=localist-widget-1')));
  }

  /**
   * A stored source on a Localist host is still output.
   *
   * @covers ::build
   */
  public function testBuildKeepsAllowedStoredSource(): void {
    $build = $this->plugin->build([
      'widget_id' => '1',
      'localist_source' => 'https://events.yale.edu/widget/view',
      'title' => '',
    ]);
    $this->assertSame('https://events.yale.edu/widget/view', $build['#embedSource']['#context']['localist_source']);
  }

  /**
   * A stored source saved before host validation is dropped at output.
   *
   * @covers ::build
   */
  public function testBuildDropsDisallowedStoredSource(): void {
    $build = $this->plugin->build([
      'widget_id' => '1',
      'localist_source' => 'https://evil.example.com/widget.js',
      'title' => '',
    ]);
    $this->assertSame('', $build['#embedSource']['#context']['localist_source']);
    $this->assertSame('1', $build['#embedSource']['#context']['widget_id']);
    // The template only prints the script tag when a source is present.
    $this->assertStringContainsString('{% if localist_source %}', $build['#embedSource']['#template']);
  }

  /**
   * Data provider for testIsValidRejectsNonLocalistSource().
   *
   * @return array
   *   Script sources that must not validate.
   */
  public static function nonLocalistSourceProvider(): array {
    return [
      'other host' => ['https://evil.example.com/widget.js'],
      'localist in path' => ['https://evil.example.com/yale.localist.com/widget/view'],
      'localist as subdomain prefix' => ['https://yale.localist.com.evil.example.com/widget/view'],
      'look-alike dot' => ['https://yale.enterprisexlocalist.com/widget/view'],
      'plain http' => ['http://yale.localist.com/widget/view'],
      'protocol relative' => ['//yale.localist.com/widget/view'],
      'credentials trick' => ['https://yale.localist.com@evil.example.com/widget/view'],
      'yale host as subdomain prefix' => ['https://events.yale.edu.evil.example.com/widget/view'],
      'yale host look-alike dot' => ['https://eventsxyale.edu/widget/view'],
    ];
  }

  /**
   * @covers ::isValid
   * @dataProvider nonLocalistSourceProvider
   */
  public function testIsValidRejectsNonLocalistSource(string $src): void {
    $this->assertFalse(Localist::isValid(self::embedWithSource($src)));
  }

  /**
   * Builds a Localist embed code with the given script source.
   *
   * @param string $src
   *   The script src attribute value.
   *
   * @return string
   *   The embed code.
   */
  protected static function embedWithSource(string $src): string {
    return '<div id="localist-widget-1" class="localist-widget"></div><script defer type="text/javascript" src="' . $src . '"></script>';
  }

}
