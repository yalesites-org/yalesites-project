<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\GoogleCalendar;

/**
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSource\GoogleCalendar
 *
 * @group yalesites
 * @group ys_embed
 */
class GoogleCalendarTest extends UnitTestCase {

  /**
   * A real Google Calendar embed code, taken from the plugin's own example.
   *
   * @var string
   */
  const VALID_EMBED = '<iframe src="https://calendar.google.com/calendar/embed?src=en.usa%23holiday%40group.v.calendar.google.com&ctz=America%2FNew_York" style="border: 0" width="800" height="600" frameborder="0" scrolling="no"></iframe>';

  /**
   * The GoogleCalendar plugin instance.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSource\GoogleCalendar
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
    $this->plugin = new GoogleCalendar([], 'google_calendar', [], $configFactory);
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsRealEmbedCode(): void {
    $this->assertTrue(GoogleCalendar::isValid(self::VALID_EMBED));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsNonGoogleDomain(): void {
    $this->assertFalse(GoogleCalendar::isValid('<iframe src="https://evil.com/calendar/embed?src=x"></iframe>'));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsBareUrlWithoutIframe(): void {
    $this->assertFalse(GoogleCalendar::isValid('https://calendar.google.com/calendar/embed?src=x'));
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsCapturesCalendarParams(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $this->assertSame('?src=en.usa%23holiday%40group.v.calendar.google.com&ctz=America%2FNew_York', $params['calendar_params']);
  }

  /**
   * @covers ::getUrl
   */
  public function testGetUrlBuildsFromCalendarParams(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $this->assertSame(
      'https://calendar.google.com/calendar/embed?src=en.usa%23holiday%40group.v.calendar.google.com&ctz=America%2FNew_York',
      $this->plugin->getUrl($params)
    );
  }

  /**
   * @covers ::build
   */
  public function testBuildTitleIsEmptyWhenBlank(): void {
    // GoogleCalendar does not override the base $defaultTitle, so the PHP
    // render array falls back to an empty string. The "Google Calendar"
    // fallback text lives in the Twig template's {{ title|default(...) }}
    // filter, which only applies when the inline template is rendered.
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $this->assertSame('', $build['#title']);
  }

  /**
   * @covers ::build
   */
  public function testBuildUsesProvidedTitle(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $params['title'] = 'My Calendar';
    $build = $this->plugin->build($params);
    $this->assertSame('My Calendar', $build['#title']);
  }

  /**
   * @covers ::build
   */
  public function testBuildDisplayAttributesMarkIframe(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $this->assertTrue($build['#displayAttributes']['isIframe']);
    $this->assertSame('calendar', $build['#displayAttributes']['embedType']);
  }

  /**
   * @covers ::build
   */
  public function testBuildUrlUsesCalendarParams(): void {
    $params = $this->plugin->getParams(self::VALID_EMBED);
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $this->assertSame(
      'https://calendar.google.com/calendar/embed?src=en.usa%23holiday%40group.v.calendar.google.com&ctz=America%2FNew_York',
      $build['#url']
    );
  }

}
