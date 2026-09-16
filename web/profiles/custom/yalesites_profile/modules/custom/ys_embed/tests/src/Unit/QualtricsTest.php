<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\Qualtrics;

/**
 * Tests for qualtrics test.
 *
 * Qualtrics is annotated "active = FALSE" (legacy support only), but its
 * regex, getUrl(), and build() logic still run whenever an existing legacy
 * embed is loaded, so that behavior is characterized here regardless of the
 * active flag (which is a manager-level concern, not a plugin-level one).
 *
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSource\Qualtrics
 *
 * @group yalesites
 * @group ys_embed
 */
class QualtricsTest extends UnitTestCase {

  /**
   * A real Qualtrics survey URL, taken from the plugin's own example.
   *
   * @var string
   */
  const VALID_URL = 'https://yalesurvey.ca1.qualtrics.com/jfe/form/SV_cDezt2JVsNok77o';

  /**
   * The Qualtrics plugin instance.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSource\Qualtrics
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
    $this->plugin = new Qualtrics([], 'qualtrics', [], $configFactory);
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsRealSurveyUrl(): void {
    $this->assertTrue(Qualtrics::isValid(self::VALID_URL));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsNonQualtricsDomain(): void {
    $this->assertFalse(Qualtrics::isValid('https://evil.com/jfe/form/SV_abc'));
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsCapturesFormId(): void {
    $params = $this->plugin->getParams(self::VALID_URL);
    $this->assertSame('SV_cDezt2JVsNok77o', $params['form_id']);
  }

  /**
   * @covers ::getUrl
   */
  public function testGetUrlBuildsFromFormId(): void {
    $this->assertSame(self::VALID_URL, $this->plugin->getUrl(['form_id' => 'SV_cDezt2JVsNok77o']));
  }

  /**
   * @covers ::build
   */
  public function testBuildTitleIsEmptyWhenBlank(): void {
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
