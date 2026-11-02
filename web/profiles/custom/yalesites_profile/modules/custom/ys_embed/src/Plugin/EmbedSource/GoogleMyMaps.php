<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * Google My Maps embed source.
 *
 * @EmbedSource(
 *   id = "google_my_maps",
 *   label = @Translation("Google My Maps"),
 *   description = @Translation("Google My Maps embed source."),
 *   thumbnail = "googlemaps.png",
 *   active = TRUE,
 * )
 */
class GoogleMyMaps extends EmbedSourceBase implements EmbedSourceInterface {

  /**
   * {@inheritdoc}
   */
  protected static $pattern = '/^<iframe[^>]*src=\"https:\/\/www\.google\.com\/maps\/d\/embed(?<map_params>\?.+?)\".*$/s';

  /**
   * {@inheritdoc}
   */
  protected static $template = '<iframe class="iframe" title="{{ title }}" src="https://www.google.com/maps/d/embed{{ map_params }}" height="100%" width="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>';

  /**
   * {@inheritdoc}
   */
  protected static $instructions = '<p>Open your map in Google My Maps, click the three-dot menu next to the map title, select "Embed on my site", and copy the code. This is different from the "Embed a map" option in regular Google Maps, which is for a single location. The map must be shared publicly or with "Anyone with the link" for visitors to see it.</p>';

  /**
   * {@inheritdoc}
   */
  protected static $example = '<iframe src="https://www.google.com/maps/d/embed?mid=1ElTerPyfcAm31qLlbKz_SebWiubAzhg&hl=en&ehbc=2E312F" width="640" height="480"></iframe>';

  /**
   * {@inheritdoc}
   */
  protected static $displayAttributes = [
    'width' => '100%',
    'height' => '100%',
    'scrolling' => 'yes',
    'frameborder' => 'no',
    'embedType' => 'map',
    'isIframe' => TRUE,
  ];

  /**
   * {@inheritdoc}
   */
  public function getUrl(array $params): string {
    return 'https://www.google.com/maps/d/embed' . $params['map_params'];
  }

}
