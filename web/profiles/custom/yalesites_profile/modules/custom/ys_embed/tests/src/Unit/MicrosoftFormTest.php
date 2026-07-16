<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\MicrosoftForm;

/**
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSource\MicrosoftForm
 *
 * @group yalesites
 * @group ys_embed
 */
class MicrosoftFormTest extends UnitTestCase {

  /**
   * A real office.com "Pages/ResponsePage.aspx" embed, from the example.
   *
   * @var string
   */
  const PAGES_EMBED = '<iframe width="640px" height="480px" src="https://forms.office.com/Pages/ResponsePage.aspx?id=u76M3Tkh-E20EU4-h6vrXJ-OMhrDFtBEifIUjjt2g_xURUVBU1IyUVlTVFFFNjJQQzJXM1pNMVozVi4u&embed=true" frameborder="0"></iframe>';

  /**
   * A cloud.microsoft "/r/" style embed.
   *
   * @var string
   */
  const R_EMBED = '<iframe width="640px" height="480px" src="https://forms.cloud.microsoft/r/abc123XYZ?embed=true" frameborder="0"></iframe>';

  /**
   * The MicrosoftForm plugin instance.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSource\MicrosoftForm
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
    $this->plugin = new MicrosoftForm([], 'msforms', [], $configFactory);
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsPagesResponsePageEmbed(): void {
    $this->assertTrue(MicrosoftForm::isValid(self::PAGES_EMBED));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsCloudMicrosoftRformatEmbed(): void {
    $this->assertTrue(MicrosoftForm::isValid(self::R_EMBED));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsNonMicrosoftDomain(): void {
    $this->assertFalse(MicrosoftForm::isValid('<iframe src="https://forms.evil.com/Pages/ResponsePage.aspx?id=abc"></iframe>'));
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsCapturesPageParams(): void {
    $params = $this->plugin->getParams(self::PAGES_EMBED);
    $this->assertSame('office.com', $params['domain']);
    $this->assertSame('?id=u76M3Tkh-E20EU4-h6vrXJ-OMhrDFtBEifIUjjt2g_xURUVBU1IyUVlTVFFFNjJQQzJXM1pNMVozVi4u&embed=true', $params['page_params']);
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsCapturesFormIdAndRformatParams(): void {
    $params = $this->plugin->getParams(self::R_EMBED);
    $this->assertSame('cloud.microsoft', $params['domain']);
    $this->assertSame('abc123XYZ', $params['form_id']);
    $this->assertSame('?embed=true', $params['r_params']);
  }

  /**
   * @covers ::getUrl
   */
  public function testGetUrlBuildsPagesResponsePageUrl(): void {
    $params = $this->plugin->getParams(self::PAGES_EMBED);
    $this->assertSame(
      'https://forms.office.com/Pages/ResponsePage.aspx?id=u76M3Tkh-E20EU4-h6vrXJ-OMhrDFtBEifIUjjt2g_xURUVBU1IyUVlTVFFFNjJQQzJXM1pNMVozVi4u&amp;embed=true',
      $this->plugin->getUrl($params)
    );
  }

  /**
   * @covers ::getUrl
   */
  public function testGetUrlBuildsRformatUrlWithParams(): void {
    $params = $this->plugin->getParams(self::R_EMBED);
    $this->assertSame('https://forms.cloud.microsoft/r/abc123XYZ?embed=true', $this->plugin->getUrl($params));
  }

  /**
   * @covers ::getUrl
   */
  public function testGetUrlOmitsQueryStringWhenRformatParamsAbsent(): void {
    $params = ['domain' => 'cloud.microsoft', 'form_id' => 'abc123'];
    $this->assertSame('https://forms.cloud.microsoft/r/abc123', $this->plugin->getUrl($params));
  }

  /**
   * @covers ::getUrl
   */
  public function testGetUrlReturnsEmptyStringWhenNoFormatMatches(): void {
    $this->assertSame('', $this->plugin->getUrl(['domain' => 'office.com']));
  }

  /**
   * Sanitization strips quotes and encodes markup in page/r query params.
   *
   * @covers ::getUrl
   */
  public function testGetUrlSanitizesPageParamsAgainstInjection(): void {
    $params = [
      'domain' => 'office.com',
      'page_params' => '?id=abc"><script>alert(1)</script>',
    ];
    $this->assertSame(
      'https://forms.office.com/Pages/ResponsePage.aspx?id=abc&gt;&lt;script&gt;alert(1)&lt;/script&gt;',
      $this->plugin->getUrl($params)
    );
  }

  /**
   * Sanitization strips quotes, angle brackets, and ampersands from form ID.
   *
   * @covers ::getUrl
   */
  public function testGetUrlSanitizesFormIdAgainstInjection(): void {
    $params = [
      'domain' => 'cloud.microsoft',
      'form_id' => 'abc"<script>alert(1)</script>&x',
    ];
    $this->assertSame('https://forms.cloud.microsoft/r/abcscriptalert(1)/scriptx', $this->plugin->getUrl($params));
  }

  /**
   * @covers ::build
   */
  public function testBuildIncludesSanitizedUrl(): void {
    $params = $this->plugin->getParams(self::R_EMBED);
    $params['title'] = 'A Yale form';
    $build = $this->plugin->build($params);
    $this->assertSame('https://forms.cloud.microsoft/r/abc123XYZ?embed=true', $build['#url']);
    $this->assertSame('A Yale form', $build['#title']);
  }

}
