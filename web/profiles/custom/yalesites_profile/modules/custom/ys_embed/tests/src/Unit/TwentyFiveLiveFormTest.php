<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\TwentyFiveLiveForm;

/**
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSource\TwentyFiveLiveForm
 *
 * @group yalesites
 * @group ys_embed
 */
class TwentyFiveLiveFormTest extends UnitTestCase {

  /**
   * A real 25Live embedded preview URL, from the plugin's own example.
   *
   * @var string
   */
  const VALID_URL = 'https://25live.collegenet.com/pro/yale/embedded/preview?token=abc123&target=crossCampus&instance=yale';

  /**
   * The TwentyFiveLiveForm plugin instance.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSource\TwentyFiveLiveForm
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
    $this->plugin = new TwentyFiveLiveForm([], 'twenty_five_live_form', [], $configFactory);
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsRealPreviewUrl(): void {
    $this->assertTrue(TwentyFiveLiveForm::isValid(self::VALID_URL));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsNonCollegenetDomain(): void {
    $this->assertFalse(TwentyFiveLiveForm::isValid('https://evil.com/pro/yale/embedded/preview?token=x'));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsNonYaleInstance(): void {
    $this->assertFalse(TwentyFiveLiveForm::isValid('https://25live.collegenet.com/pro/harvard/embedded/preview?token=x'));
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsCapturesQueryParams(): void {
    $params = $this->plugin->getParams(self::VALID_URL);
    $this->assertSame('token=abc123&target=crossCampus&instance=yale', $params['params']);
  }

  /**
   * @covers ::getUrl
   */
  public function testGetUrlBuildsFromParams(): void {
    $this->assertSame(self::VALID_URL, $this->plugin->getUrl(['params' => 'token=abc123&target=crossCampus&instance=yale']));
  }

  /**
   * @covers ::getUrl
   */
  public function testGetUrlDefaultsToEmptyParamsWhenMissing(): void {
    $this->assertSame('https://25live.collegenet.com/pro/yale/embedded/preview?', $this->plugin->getUrl([]));
  }

  /**
   * @covers ::build
   */
  public function testBuildTitleIsEmptyWhenBlank(): void {
    // No PHP-level $defaultTitle override; the "25Live Event Form" fallback
    // is a Twig {{ title|default(...) }} filter applied at render time only.
    $params = $this->plugin->getParams(self::VALID_URL);
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $this->assertSame('', $build['#title']);
  }

  /**
   * @covers ::build
   */
  public function testBuildDisplayAttributesMarkIframeForm(): void {
    $params = $this->plugin->getParams(self::VALID_URL);
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $this->assertTrue($build['#displayAttributes']['isIframe']);
    $this->assertSame('form', $build['#displayAttributes']['embedType']);
  }

}
