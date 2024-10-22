<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * Instagram post embed source.
 *
 * @EmbedSource(
 *   id = "libcal",
 *   label = @Translation("LibCal"),
 *   description = @Translation("LibCal embed source."),
 *   thumbnail = "",
 *   active = TRUE,
 * )
 */
class LibCal extends EmbedSourceBase implements EmbedSourceInterface {

  /**
   * {@inheritdoc}
   */
  protected static $pattern = '/(?<embed_code>\<div id="([^"]+)"\>\<\/div\>\<script src="https:\/\/schedule\.yale\.edu\/.*<\/script>)/';

  /**
   * {@inheritdoc}
   */
  protected static $template = '{{ embed_code|raw }}';

  /**
   * {@inheritdoc}
   */
  protected static $instructions = 'Find the embed code for an LibCal listing';

  /**
   * {@inheritdoc}
   */
  protected static $example = '<div id="api_hours_today_iid457_lid4211"></div><script src="https://schedule.yale.edu/api_hours_today.php?iid=457&lid=4211&format=js&systemTime=0&context=object"> </script>';

}
