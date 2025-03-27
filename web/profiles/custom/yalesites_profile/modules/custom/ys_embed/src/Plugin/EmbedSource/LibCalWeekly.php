<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * LibCal Weekly Grid embed source.
 *
 * @EmbedSource(
 *   id = "libcal_weekly",
 *   label = @Translation("LibCal Weekly"),
 *   description = @Translation("LibCal weekly grid embed source."),
 *   thumbnail = "springshare-libcal.png",
 *   active = TRUE,
 * )
 */
class LibCalWeekly extends EmbedSourceBase implements EmbedSourceInterface {

  /**
   * Static counter to track if navigation has been added.
   *
   * @var bool
   */
  protected static $navigationAdded = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $pattern = '/(?<embed_code>\<script src="https:\/\/schedule\.yale\.edu\/js\/hours_grid\.js\?002"\>\<\/script>\s*<div id="s-lc-whw\d+"\>\<\/div>\s*<script>\s*\$\(function\(\).*?var week\d+ = new \$\\.LibCalWeeklyGrid\(\s*\$\s*\(\s*"#s-lc-whw\d+"\s*\),\s*\{[^}]+\}\s*\);.*?<\/script>)/s';

  /**
   * {@inheritdoc}
   */
  protected static $template = <<<EOT
  <div class="embed-libcal-weekly"></div>
  EOT;

  /**
   * Builds the render array for the embed.
   */
  public function build(array $params): array {
    $embed_code = $params['embed_code'] ?? '';
    $elements = [];
    
    // Only add navigation for the first weekly embed.
    if (!self::$navigationAdded) {
      $elements[] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'id' => 'libcal-week-nav',
        ],
        'nav' => [
          '#type' => 'html_tag',
          '#tag' => 'nav',
          'previous' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#attributes' => [
              'class' => ['previous'],
            ],
            '#value' => 'Previous',
          ],
          'next' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#attributes' => [
              'class' => ['next'],
            ],
            '#value' => 'Next',
          ],
        ],
      ];
      self::$navigationAdded = TRUE;
    }
    
    $elements[] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => ['embed-libcal-weekly'],
        'data-embed-code' => $embed_code,
      ],
    ];
    
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['libcal-weekly-container'],
      ],
      'elements' => $elements,
      '#attached' => [
        'library' => ['ys_embed/libcal_weekly'],
        'html_head' => [
          [
            [
              '#tag' => 'link',
              '#attributes' => [
                'rel' => 'stylesheet',
                'href' => '/profiles/custom/yalesites_profile/modules/custom/ys_embed/css/LibCalWeekly.css',
              ],
            ],
            'ys_embed_libcal_weekly_css',
          ],
          [
            [
              '#tag' => 'script',
              '#attributes' => [
                'src' => '/profiles/custom/yalesites_profile/modules/custom/ys_embed/js/LibCalWeekly.js',
              ],
            ],
            'ys_embed_libcal_weekly_js',
          ],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected static $instructions = 'Place embed code for a LibCal weekly grid. DO NOT INCLUDE JQUERY (make sure that option is unchecked in LibCal). Styles will be ignored.';

  /**
   * {@inheritdoc}
   */
  protected static $example = '<script src="https://schedule.yale.edu/js/hours_grid.js?002"></script> 
<div id="s-lc-whw4213"></div> 
<script>
$(function(){ 
var week4213 = new $.LibCalWeeklyGrid( $("#s-lc-whw4213"), { iid: 457, lid: 4213, systemTime: false }); 
});
</script>';

} 