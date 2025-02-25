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
  protected static $pattern = '/(?<embed_code>\<script src="https:\/\/schedule\.yale\.edu\/.*?<\/script>\s*<div id="([^"]+)"\>\<\/div\>\s*<script>.*?<\/script>(?:\s*<style>.*?<\/style>)?)/s';

  /**
   * {@inheritdoc}
   */
  protected static $template = <<<EOT
  <div class="embed-libcal"></div>
  EOT;

  /**
   * Builds the render array for the embed.
   */
  public function build(array $params): array {
    $embed_code = $params['embed_code'] ?? '';
    return [
      '#markup' => self::$template,
      '#attached' => [
        'library' => ['ys_embed/libcal'],
        'drupalSettings' => [
          'ysEmbed' => [
            'libcalEmbedCode' => $embed_code,
          ],
        ],
        'html_head' => [
          [
            [
              '#tag' => 'link',
              '#attributes' => [
                'rel' => 'stylesheet',
                'href' => '/profiles/custom/yalesites_profile/modules/custom/ys_embed/css/LibCal.css',
              ],
            ],
            'ys_embed_libcal_css',
          ],
          [
            [
              '#tag' => 'script',
              '#attributes' => [
                'src' => '/profiles/custom/yalesites_profile/modules/custom/ys_embed/js/LibCal.js',
              ],
            ],
            'ys_embed_libcal_js',
          ],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected static $instructions = 'Place embed code for a LibCal listing. DO NOT INCLUDE JQUERY (make sure that option is unchecked in LibCal). Styles will be ignored.';

  /**
   * {@inheritdoc}
   */
  protected static $example = '<script src="https://schedule.yale.edu/js/hours_today.js"></script> 
<div id="s_lc_tdh_457_4216"></div> 
<script>$(function(){var s_lc_tdh_457_4216 = new $.LibCalTodayHours( $("#s_lc_tdh_457_4216"), { iid: 457, lid: 4216 }); 
});</script>';

}
