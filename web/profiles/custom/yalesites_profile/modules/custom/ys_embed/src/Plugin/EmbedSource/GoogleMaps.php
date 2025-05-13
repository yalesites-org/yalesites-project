<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * Google Maps embed source.
 *
 * @EmbedSource(
 *   id = "google_maps",
 *   label = @Translation("Google Maps"),
 *   description = @Translation("Google Maps embed source."),
 *   thumbnail = "googlemaps.png",
 *   active = TRUE,
 * )
 */
class GoogleMaps extends EmbedSourceBase implements EmbedSourceInterface {

  /**
   * {@inheritdoc}
   */
  protected static $pattern = '/^<iframe.+src=\"https:\/\/www\.google\.com\/maps\/embed(?<map_params>\?.+?)\".*$/';

  /**
   * {@inheritdoc}
   */
  protected static $template = '<iframe class="iframe" title="{{ title }}" src="https://www.google.com/maps/embed{{ map_params }}" height="100%" width="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>';


  /**
   * {@inheritdoc}
   */
  protected static $instructions = '<p>Open Google Maps and navigate to your desired location, click the "Share" button, select the "Embed a map" tab, and copy the code.</p>';

  /**
   * {@inheritdoc}
   */
  protected static $example = '<iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d5993.31404257508!2d-72.92491802386455!3d41.316324371308916!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x89e7d9b6cd624945%3A0xae34a2c4b4d30427!2sYale%20University!5e0!3m2!1sen!2sca!4v1746124034200!5m2!1sen!2sca" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>';

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
    return 'https://www.google.com/maps/embed' . $params['map_params'];
  }

}
