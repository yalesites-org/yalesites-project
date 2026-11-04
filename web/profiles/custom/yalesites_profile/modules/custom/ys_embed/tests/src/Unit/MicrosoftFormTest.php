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
    $url = $this->plugin->getUrl($params);
    $this->assertSame(
      'https://forms.office.com/Pages/ResponsePage.aspx?id=u76M3Tkh-E20EU4-h6vrXJ-OMhrDFtBEifIUjjt2g_xURUVBU1IyUVlTVFFFNjJQQzJXM1pNMVozVi4u&embed=true',
      $url
    );
    $this->assertStringContainsString('&embed=true', $url);
    $this->assertStringNotContainsString('&amp;', $url);
  }

  /**
   * The plugin's own example yields a plain URL; Twig escapes it at output.
   *
   * @covers ::getUrl
   */
  public function testGetUrlForExampleIsNotHtmlEncoded(): void {
    $params = $this->plugin->getParams(MicrosoftForm::getExample());
    $url = $this->plugin->getUrl($params);
    $this->assertStringContainsString('&embed=true', $url);
    $this->assertStringNotContainsString('&amp;', $url);
  }

  /**
   * An /r/ form with two query params keeps a literal ampersand.
   *
   * @covers ::getUrl
   */
  public function testGetUrlKeepsLiteralAmpersandInRformatParams(): void {
    $embed = '<iframe width="640px" height="480px" src="https://forms.cloud.microsoft/r/abc123XYZ?embed=true&lang=en" frameborder="0"></iframe>';
    $params = $this->plugin->getParams($embed);
    $this->assertSame('?embed=true&lang=en', $params['r_params']);
    $url = $this->plugin->getUrl($params);
    $this->assertSame('https://forms.cloud.microsoft/r/abc123XYZ?embed=true&lang=en', $url);
    $this->assertStringNotContainsString('&amp;', $url);
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
   * Sanitization strips quotes from page query params.
   *
   * The URL is not HTML-encoded here; Twig autoescapes it at output.
   *
   * @covers ::getUrl
   */
  public function testGetUrlSanitizesPageParamsAgainstInjection(): void {
    $params = [
      'domain' => 'office.com',
      'page_params' => '?id=abc"><script>alert(1)</script>',
    ];
    $this->assertSame(
      'https://forms.office.com/Pages/ResponsePage.aspx?id=abc><script>alert(1)</script>',
      $this->plugin->getUrl($params)
    );
  }

  /**
   * Sanitization strips quotes from /r/ query params.
   *
   * @covers ::getUrl
   */
  public function testGetUrlStripsQuotesFromRformatParams(): void {
    $params = [
      'domain' => 'cloud.microsoft',
      'form_id' => 'abc123',
      'r_params' => '?embed=true"&lang=en',
    ];
    $this->assertSame('https://forms.cloud.microsoft/r/abc123?embed=true&lang=en', $this->plugin->getUrl($params));
  }

  /**
   * Query params are capped at 2000 characters.
   *
   * @covers ::getUrl
   */
  public function testGetUrlCapsPageParamsLength(): void {
    $params = [
      'domain' => 'office.com',
      'page_params' => '?id=' . str_repeat('a', 3000),
    ];
    $prefix = 'https://forms.office.com/Pages/ResponsePage.aspx';
    $this->assertSame(strlen($prefix) + 2000, strlen($this->plugin->getUrl($params)));
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
