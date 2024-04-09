<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * Twitter post embed source.
 *
 * @EmbedSource(
 *   id = "twitter",
 *   label = @Translation("X Tweet"),
 *   description = @Translation("X post embed source."),
 *   thumbnail = "x-twitter.png",
 *   active = TRUE,
 * )
 */
class Twitter extends EmbedSourceBase implements EmbedSourceInterface {

  /**
   * {@inheritdoc}
   */
  protected static $pattern = '/^(?<blockquote><blockquote.*<\/blockquote>).*src=\"https:\/\/platform\.twitter\.com\/widgets\.js\"/s';

  /**
   * {@inheritdoc}
   */
  protected static $template = "{{ blockquote|raw }}\r\n<script async src=\"https://platform.twitter.com/widgets.js\" charset=\"utf-8\"></script>\r\n";

  /**
   * {@inheritdoc}
   */
  protected static $instructions = 'On the Twitter website, click the triangular (...) icon on the upper-right corner of a tweet and select the \'Embed Tweet\' item from the contextual menu. The embed code will appear in an input-box on this interface.';

  /**
   * {@inheritdoc}
   */
  protected static $example = '<blockquote class="twitter-tweet"><p lang="en" dir="ltr">Yale scientists find a common weed harbors important clues about how to create drought resistant crops in a world beset by climate change.<a href="https://twitter.com/yale_eeb?ref_src=twsrc%5Etfw">@yale_eeb</a> <a href="https://twitter.com/hashtag/Yale?src=hash&amp;ref_src=twsrc%5Etfw">#Yale</a><a href="https://t.co/IsOLJ9hAbh">https://t.co/IsOLJ9hAbh</a></p>&mdash; Yale University (@Yale) <a href="https://twitter.com/Yale/status/1586724355089776640?ref_src=twsrc%5Etfw">October 30, 2022</a></blockquote> <script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>';

}
