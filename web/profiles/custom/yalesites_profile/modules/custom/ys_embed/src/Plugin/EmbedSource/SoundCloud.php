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
  protected static $pattern = '/<iframe(?<embed_code>.*src="https:\/\/w\.soundcloud\.com\/player\/.*)<\/iframe>/';

  /**
   * {@inheritdoc}
   */
  protected static $template = '<iframe {{ embed_code|raw }}</iframe>';

  /**
   * {@inheritdoc}
   */
  protected static $instructions = 'Find the embed code for a SoundCloud resource by clicking the "Share" button, selecting "Embed" at the top of the window that displays, and copy the "Code" text in the text box.';

  /**
   * {@inheritdoc}
   */
  protected static $example = '<iframe width="100%" height="300" scrolling="no" frameborder="no" allow="autoplay" src="https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/320687463&color=%23ff5500&auto_play=false&hide_related=false&show_comments=true&show_user=true&show_reposts=false&show_teaser=true&visual=true"></iframe>';

}
