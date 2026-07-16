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
   * CHARACTERIZATION: getUrl() always reconstructs a tracks API URL.
   *
   * It rebuilds a "/tracks/{id}" URL even when the embed code matched as a
   * playlist -- it captures "track_or_playlist" for validation but never
   * reads it back out when building the URL. A playlist embed is
   * re-pointed at the tracks API endpoint instead of the playlists one.
   *
   * Paired with testGetUrlShouldUsePlaylistsEndpointForPlaylistEmbeds() --
   * delete once the GAP is fixed.
   *
   * @covers ::getUrl
   */
  public function testGetUrlAlwaysBuildsTracksApiUrlRegardlessOfType(): void {
    $params = $this->plugin->getParams(self::PLAYLIST_EMBED);
    $this->assertSame(
      'https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/123456',
      $this->plugin->getUrl($params)
    );
  }

  /**
   * Tests get url should use playlists endpoint for playlist embeds.
   *
   * GAP: getUrl() should reconstruct "/playlists/{id}" when the embed
   * matched as a playlist, using the captured "track_or_playlist" group
   * instead of hardcoding "tracks" -- see
   * ~/Documents/Claude/not_dave/module-tests-20260710/ys_embed.md.
   */
  public function testGetUrlShouldUsePlaylistsEndpointForPlaylistEmbeds(): void {
    $this->markTestSkipped('GAP: SoundCloud::getUrl() hardcodes "/tracks/" and ignores the captured "track_or_playlist" group, so a playlist embed is reconstructed with the wrong API endpoint -- see ~/Documents/Claude/not_dave/module-tests-20260710/ys_embed.md');
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
