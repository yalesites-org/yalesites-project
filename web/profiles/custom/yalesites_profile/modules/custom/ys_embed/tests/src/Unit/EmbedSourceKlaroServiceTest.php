<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\Bluesky;
use Drupal\ys_embed\Plugin\EmbedSource\Broken;
use Drupal\ys_embed\Plugin\EmbedSource\GitHubApplet;
use Drupal\ys_embed\Plugin\EmbedSource\GoogleCalendar;
use Drupal\ys_embed\Plugin\EmbedSource\GoogleMaps;
use Drupal\ys_embed\Plugin\EmbedSource\Instagram;
use Drupal\ys_embed\Plugin\EmbedSource\JWPlayer;
use Drupal\ys_embed\Plugin\EmbedSource\Localist;
use Drupal\ys_embed\Plugin\EmbedSource\MicrosoftForm;
use Drupal\ys_embed\Plugin\EmbedSource\PowerBI;
use Drupal\ys_embed\Plugin\EmbedSource\Qualtrics;
use Drupal\ys_embed\Plugin\EmbedSource\SoundCloud;
use Drupal\ys_embed\Plugin\EmbedSource\TwentyFiveLiveForm;
use Drupal\ys_embed\Plugin\EmbedSource\Twitter;

/**
 * Tests the Klaro consent service declaration on embed source plugins.
 *
 * Each embed source declares the Klaro service (app) its third-party content
 * belongs to. A NULL declaration means the source is ungated, which is only
 * correct for Yale-controlled tenants and for sources that make no third-party
 * request at all.
 *
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSourceBase
 *
 * @group yalesites
 * @group ys_embed
 */
class EmbedSourceKlaroServiceTest extends UnitTestCase {

  /**
   * The stubbed config factory the plugins are constructed with.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->configFactory = $this->getConfigFactoryStub([
      'media.settings' => ['icon_base_uri' => 'public://media-icons'],
    ]);
  }

  /**
   * Every third-party embed source declares the Klaro service it belongs to.
   *
   * @param string $class
   *   The embed source plugin class.
   * @param string $plugin_id
   *   The embed source plugin ID.
   * @param string $expected
   *   The expected Klaro service (app) ID.
   *
   * @dataProvider gatedSourceProvider
   *
   * @covers ::getKlaroService
   */
  public function testGatedSourcesDeclareTheirKlaroService(string $class, string $plugin_id, string $expected): void {
    $plugin = new $class([], $plugin_id, [], $this->configFactory);
    $this->assertSame($expected, $plugin->getKlaroService());
  }

  /**
   * Data provider of consent-gated embed sources.
   *
   * @return array
   *   Sets of [plugin class, plugin ID, expected Klaro service ID].
   */
  public static function gatedSourceProvider(): array {
    return [
      'Google Maps' => [GoogleMaps::class, 'google_maps', 'google_maps'],
      'Google Calendar' => [GoogleCalendar::class, 'google_calendar', 'google_calendar'],
      'SoundCloud' => [SoundCloud::class, 'soundcloud', 'soundcloud'],
      'JW Player' => [JWPlayer::class, 'jwplayer', 'jwplayer'],
      'Power BI' => [PowerBI::class, 'powerbi', 'powerbi'],
      'Microsoft Forms' => [MicrosoftForm::class, 'msforms', 'microsoft_forms'],
      'Qualtrics' => [Qualtrics::class, 'qualtrics', 'qualtrics'],
      'Instagram' => [Instagram::class, 'instagram', 'instagram'],
      'Twitter/X' => [Twitter::class, 'twitter', 'x'],
      'Bluesky' => [Bluesky::class, 'bluesky', 'bluesky'],
    ];
  }

  /**
   * Yale-controlled and inert sources stay ungated.
   *
   * 25Live, Localist and the GitHub applet all point at Yale-controlled
   * tenants and are treated as first-party for the consent MVP. Broken makes
   * no third-party request at all.
   *
   * @param string $class
   *   The embed source plugin class.
   * @param string $plugin_id
   *   The embed source plugin ID.
   *
   * @dataProvider ungatedSourceProvider
   *
   * @covers ::getKlaroService
   */
  public function testFirstPartySourcesAreNotGated(string $class, string $plugin_id): void {
    $plugin = new $class([], $plugin_id, [], $this->configFactory);
    $this->assertNull($plugin->getKlaroService());
  }

  /**
   * Data provider of embed sources that are deliberately ungated.
   *
   * @return array
   *   Sets of [plugin class, plugin ID].
   */
  public static function ungatedSourceProvider(): array {
    return [
      '25Live' => [TwentyFiveLiveForm::class, 'twenty_five_live_form'],
      'Localist' => [Localist::class, 'localist'],
      'GitHub applet' => [GitHubApplet::class, 'github_applet'],
      'Broken' => [Broken::class, 'broken'],
    ];
  }

  /**
   * The build array carries the Klaro service through to the theme layer.
   *
   * The iframe render path builds its own element from '#url', so the theme
   * layer is the only place that can swap 'src' for 'data-src'. It needs the
   * service ID to do that.
   *
   * @covers ::build
   */
  public function testBuildExposesKlaroServiceToTheThemeLayer(): void {
    $plugin = new GoogleMaps([], 'google_maps', [], $this->configFactory);
    $build = $plugin->build(['title' => '', 'map_params' => '?pb=x']);
    $this->assertSame('google_maps', $build['#klaroService']);
  }

  /**
   * An ungated source passes NULL, so the theme layer renders a live src.
   *
   * @covers ::build
   */
  public function testBuildExposesNullForUngatedSources(): void {
    $plugin = new TwentyFiveLiveForm([], 'twenty_five_live_form', [], $this->configFactory);
    $build = $plugin->build(['title' => '', 'url' => 'https://25live.collegenet.com/pro/yale']);
    $this->assertNull($build['#klaroService']);
  }

  /**
   * Script-based sources can defer their third-party script.
   *
   * These three render their own inline template rather than going through the
   * shared iframe path, so the blocking markup has to live in each template
   * string. The template carries both branches — Klaro's deferred form and the
   * plain form — and picks between them on the klaro_service context value,
   * which ys_embed_preprocess_embed_wrapper() clears when the site has consent
   * management switched off.
   *
   * @param string $class
   *   The embed source plugin class.
   * @param string $plugin_id
   *   The embed source plugin ID.
   * @param string $script_url
   *   The third-party script URL that must be deferred.
   * @param string $service
   *   The Klaro service ID that must gate it.
   *
   * @dataProvider scriptSourceProvider
   *
   * @covers ::build
   */
  public function testScriptSourcesDeferTheirThirdPartyScript(string $class, string $plugin_id, string $script_url, string $service): void {
    $plugin = new $class([], $plugin_id, [], $this->configFactory);
    $build = $plugin->build(['title' => '', 'embed_code' => '', 'blockquote' => '']);
    $template = $build['#embedSource']['#template'];
    $context = $build['#embedSource']['#context'];

    // The URL is written once, in the context, not inlined in either branch.
    $this->assertSame($script_url, $context['script']);
    $this->assertSame($service, $context['klaro_service']);
    $this->assertStringNotContainsString($script_url, $template);

    $this->assertStringContainsString('{% if klaro_service %}', $template);
    $this->assertStringContainsString('type="text/plain"', $template);
    $this->assertStringContainsString('data-type="application/javascript"', $template);
    $this->assertStringContainsString('data-src="{{ script }}"', $template);
    $this->assertStringContainsString('data-name="{{ klaro_service }}"', $template);
  }

  /**
   * Data provider of script-based embed sources and their blocked scripts.
   *
   * @return array
   *   Sets of [class, plugin ID, script URL, Klaro service ID].
   */
  public static function scriptSourceProvider(): array {
    return [
      'Instagram' => [Instagram::class, 'instagram', '//www.instagram.com/embed.js', 'instagram'],
      'Twitter/X' => [Twitter::class, 'twitter', 'https://platform.twitter.com/widgets.js', 'x'],
      'Bluesky' => [Bluesky::class, 'bluesky', 'https://embed.bsky.app/static/embed.js', 'bluesky'],
    ];
  }

}
