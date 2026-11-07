<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * Datawrapper embed source.
 *
 * @EmbedSource(
 *   id = "datawrapper",
 *   label = @Translation("Datawrapper"),
 *   description = @Translation("Datawrapper chart and map embed source."),
 *   thumbnail = "generic.png",
 *   active = TRUE,
 * )
 */
class Datawrapper extends EmbedSourceBase implements EmbedSourceInterface {

  /**
   * {@inheritdoc}
   */
  protected static $pattern = '/^<iframe[^>]*src=\"https:\/\/datawrapper\.dwcdn\.net\/(?<chart_id>[A-Za-z0-9]+)\/(?<version>\d+)\/?\".*$/s';

  /**
   * {@inheritdoc}
   */
  protected static $template = '<iframe class="iframe" title="{{ title }}" src="https://datawrapper.dwcdn.net/{{ chart_id }}/{{ version }}/" height="100%" width="100%" style="border:0;" loading="lazy"></iframe>';

  /**
   * {@inheritdoc}
   */
  protected static $instructions = '<p>In Datawrapper, open your published chart or map, go to the Publish &amp; Embed step, and copy the "Embed code (responsive iframe)" code. Before you publish, set a description and custom alt text for the chart in Datawrapper so screen reader users can understand it. Datawrapper controls the accessibility of the chart itself.</p>';

  /**
   * {@inheritdoc}
   */
  protected static $example = '<iframe title="Example chart" aria-label="Map" id="datawrapper-chart-abcde" src="https://datawrapper.dwcdn.net/abcde/1/" scrolling="no" frameborder="0" style="width: 0; min-width: 100% !important; border: none;" height="400"></iframe>';

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
    return 'https://datawrapper.dwcdn.net/' . $params['chart_id'] . '/' . $params['version'] . '/';
  }

}
