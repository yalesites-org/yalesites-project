<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\PowerBI;

/**
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSource\PowerBI
 *
 * @group yalesites
 * @group ys_embed
 */
class PowerBITest extends UnitTestCase {

  /**
   * A real public PowerBI "view" URL, taken from the plugin's own example.
   *
   * @var string
   */
  const VIEW_URL = 'https://app.powerbi.com/view?r=eyJrIjoiYzQ1ODA0ZjEtZjc5YS00OTgyLWIzOTItNmJmNDY2YmRiODQ2IiwidCI6ImRkOGNiZWJiLTIxMzktNGRmOC1iNDExLTRlM2U4N2FiZWI1YyIsImMiOjF9&pageName=ReportSection2ac2649f17189885d376';

  /**
   * A real private PowerBI "reportEmbed" URL, from the plugin's own example.
   *
   * @var string
   */
  const REPORT_EMBED_URL = 'https://app.powerbi.com/reportEmbed?reportId=66e25bd6-5e0a-4db8-ad0c-28bb31b0fd5e&autoAuth=true&ctid=dd8cbebb-2139-4df8-b411-4e3e87abeb5c';

  /**
   * The PowerBI plugin instance.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSource\PowerBI
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
    $this->plugin = new PowerBI([], 'powerbi', [], $configFactory);
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsViewUrl(): void {
    $this->assertTrue(PowerBI::isValid(self::VIEW_URL));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsReportEmbedUrl(): void {
    $this->assertTrue(PowerBI::isValid(self::REPORT_EMBED_URL));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsUnsupportedType(): void {
    $this->assertFalse(PowerBI::isValid('https://app.powerbi.com/edit?r=abc'));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsNonPowerbiDomain(): void {
    $this->assertFalse(PowerBI::isValid('https://evil.com/view?r=abc'));
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsCapturesTypeAndFormParams(): void {
    $params = $this->plugin->getParams(self::VIEW_URL);
    $this->assertSame('view', $params['type']);
    $this->assertStringStartsWith('?r=eyJrIjoiYzQ1ODA0', $params['form_params']);
  }

  /**
   * @covers ::getUrl
   */
  public function testGetUrlBuildsFromTypeAndFormParams(): void {
    $params = $this->plugin->getParams(self::REPORT_EMBED_URL);
    $this->assertSame(self::REPORT_EMBED_URL, $this->plugin->getUrl($params));
  }

  /**
   * @covers ::build
   */
  public function testBuildTitleIsEmptyWhenBlank(): void {
    $params = $this->plugin->getParams(self::VIEW_URL);
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $this->assertSame('', $build['#title']);
  }

  /**
   * @covers ::build
   */
  public function testBuildUsesProvidedTitle(): void {
    $params = $this->plugin->getParams(self::VIEW_URL);
    $params['title'] = 'Quarterly Report';
    $build = $this->plugin->build($params);
    $this->assertSame('Quarterly Report', $build['#title']);
  }

  /**
   * @covers ::build
   */
  public function testBuildDisplayAttributesMarkIframeForm(): void {
    $params = $this->plugin->getParams(self::VIEW_URL);
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $this->assertTrue($build['#displayAttributes']['isIframe']);
    $this->assertSame('form', $build['#displayAttributes']['embedType']);
  }

}
