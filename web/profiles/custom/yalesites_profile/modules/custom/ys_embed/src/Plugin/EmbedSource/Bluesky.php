<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * Bluesky post embed source.
 *
 * @EmbedSource(
 *   id = "bluesky",
 *   label = @Translation("Bluesky"),
 *   description = @Translation("Bluesky post embed source."),
 *   thumbnail = "bluesky.png",
 *   active = TRUE,
 * )
 */
class Bluesky extends EmbedSourceBase implements EmbedSourceInterface {

  /**
   * {@inheritdoc}
   */
  protected static $pattern = '/^(?<blockquote><blockquote class="bluesky-embed".*?<\/blockquote>).*?src="https:\/\/embed\.bsky\.app\/static\/embed\.js"/s';

  /**
   * {@inheritdoc}
   */
  protected static $template = "{{ blockquote|raw }}\r\n<script async src=\"https://embed.bsky.app/static/embed.js\" charset=\"utf-8\"></script>\r\n";

  /**
   * {@inheritdoc}
   */
  protected static $instructions = 'To embed a Bluesky post, go to the post on bsky.app, click the share icon, and select "Embed post". Click on "Copy code" to copy the entire embed code including both the blockquote and script tags.';

  /**
   * {@inheritdoc}
   */
  protected static $example = '<blockquote class="bluesky-embed" data-bluesky-uri="at://did:plc:hptrw4dge4l5q4h5pl7wpw74/app.bsky.feed.post/3lsm7x74hls2n" data-bluesky-cid="bafyreib7xh4ogycvsofbgowkqtmt3dj5yokxcew5utknrfnvfinuihwgwm" data-bluesky-embed-color-mode="system"><p lang="">We&#x27;re at the Native American and Indigenous Studies Association Conference! Enjoy 30% off select titles using code NAISA25 through June 26!
https://yalebooks.yale.edu/NAISA25/<br><br><a href="https://bsky.app/profile/did:plc:hptrw4dge4l5q4h5pl7wpw74/post/3lsm7x74hls2n?ref_src=embed">[image or embed]</a></p>&mdash; Yale University Press (<a href="https://bsky.app/profile/did:plc:hptrw4dge4l5q4h5pl7wpw74?ref_src=embed">@yalepress.bsky.social</a>) <a href="https://bsky.app/profile/did:plc:hptrw4dge4l5q4h5pl7wpw74/post/3lsm7x74hls2n?ref_src=embed">June 27, 2025 at 2:02 PM</a></blockquote><script async src="https://embed.bsky.app/static/embed.js" charset="utf-8"></script>';

}
