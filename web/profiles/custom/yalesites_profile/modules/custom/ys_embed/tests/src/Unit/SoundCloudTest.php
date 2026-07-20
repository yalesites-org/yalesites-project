<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\SoundCloud;

/**
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSource\SoundCloud
 *
 * @group yalesites
 * @group ys_embed
 */
class SoundCloudTest extends UnitTestCase {

  /**
   * A real SoundCloud track embed, taken from the plugin's own example.
   *
   * @var string
   */
  const TRACK_EMBED = '<iframe width="100%" height="130" scrolling="no" frameborder="no" allow="autoplay" src="https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/320687463&color=%23ff5500&auto_play=false&hide_related=false&show_comments=true&show_user=true&show_reposts=false&show_teaser=true&visual=true"></iframe>';

  /**
   * A SoundCloud playlist embed using the encoded "soundcloud%253A" form.
   *
   * @var string
   */
  const PLAYLIST_EMBED = '<iframe width="100%" height="130" src="https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/playlists/soundcloud%253Aplaylists%253A123456&color=%23ff5500"></iframe>';

  /**
   * The SoundCloud plugin instance.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSource\SoundCloud
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
    $this->plugin = new SoundCloud([], 'soundcloud', [], $configFactory);
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsTrackEmbed(): void {
    $this->assertTrue(SoundCloud::isValid(self::TRACK_EMBED));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAcceptsPlaylistEmbed(): void {
    $this->assertTrue(SoundCloud::isValid(self::PLAYLIST_EMBED));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsNonSoundcloudDomain(): void {
    $this->assertFalse(SoundCloud::isValid('<iframe src="https://evil.com/player/?url=tracks/123"></iframe>'));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsNonNumericTrackId(): void {
    $this->assertFalse(SoundCloud::isValid('<iframe src="https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/abc"></iframe>'));
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsCapturesTrackIdAndType(): void {
    $params = $this->plugin->getParams(self::TRACK_EMBED);
    $this->assertSame('tracks', $params['track_or_playlist']);
    $this->assertSame('320687463', $params['track_id']);
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsCapturesPlaylistId(): void {
    $params = $this->plugin->getParams(self::PLAYLIST_EMBED);
    $this->assertSame('playlists', $params['track_or_playlist']);
    $this->assertSame('123456', $params['track_id']);
  }

  /**
   * GetUrl() reconstructs the playlists endpoint for a playlist embed.
   *
   * GetUrl() reads the captured "track_or_playlist" group, so a playlist embed
   * yields a "/playlists/{id}" URL rather than the tracks endpoint.
   *
   * @covers ::getUrl
   */
  public function testGetUrlUsesPlaylistsEndpointForPlaylistEmbeds(): void {
    $params = $this->plugin->getParams(self::PLAYLIST_EMBED);
    $this->assertSame(
      'https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/playlists/123456',
      $this->plugin->getUrl($params)
    );
  }

  /**
   * @covers ::build
   */
  public function testBuildTitleIsEmptyWhenBlank(): void {
    $params = $this->plugin->getParams(self::TRACK_EMBED);
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $this->assertSame('', $build['#title']);
  }

  /**
   * @covers ::build
   */
  public function testBuildDisplayAttributesMarkAudio(): void {
    $params = $this->plugin->getParams(self::TRACK_EMBED);
    $params['title'] = '';
    $build = $this->plugin->build($params);
    $this->assertTrue($build['#displayAttributes']['isIframe']);
    $this->assertSame('audio', $build['#displayAttributes']['embedType']);
  }

}
