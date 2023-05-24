<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * SoundCloud post embed source.
 *
 * @EmbedSource(
 *   id = "soundcloud",
 *   label = @Translation("SoundCloud"),
 *   description = @Translation("SoundCloud post embed source."),
 *   thumbnail = "soundcloud.png",
 *   active = TRUE,
 * )
 */
class SoundCloud extends EmbedSourceBase implements EmbedSourceInterface {

  /**
   * {@inheritdoc}
   */
  protected static $pattern = '/^<iframe.+src=\"https:\/\/w\.soundcloud\.com\S+(?<track_or_playlist>tracks|playlists)\/(?<track_id>\d+).+><\/iframe>/';

  /**
   * {@inheritdoc}
   */
  protected static $template = '<iframe title="{{ title }}" width="100%" height="240px" scrolling="no" frameborder="no" src="https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/{{ track_id }}&color=%02366900&auto_play=false&hide_related=false&show_comments=true&show_user=true&show_reposts=false&show_teaser=true"></iframe>';

  /**
   * {@inheritdoc}
   */
  protected static $instructions = 'Find the embed code for a SoundCloud resource by clicking the "Share" button, selecting "Embed" at the top of the window that displays, and copy the "Code" text in the text box.';

  /**
   * {@inheritdoc}
   */
  protected static $example = '<iframe width="100%" height="300" scrolling="no" frameborder="no" allow="autoplay" src="https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/320687463&color=%23ff5500&auto_play=false&hide_related=false&show_comments=true&show_user=true&show_reposts=false&show_teaser=true&visual=true"></iframe>';

  /**
   * {@inheritdoc}
   */
  protected static $display_attributes = [
    'width' => '100%',
    'height' => '240px',
    'scrolling' => 'no',
    'frameborder' => 'no',
    'isIframe' => TRUE,
  ];

  /**
   * {@inheritdoc}
   */
  public function getUrl(array $params): string {
    $track_id = $params['track_id'];
    return 'https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/' . $track_id;
  }

}
