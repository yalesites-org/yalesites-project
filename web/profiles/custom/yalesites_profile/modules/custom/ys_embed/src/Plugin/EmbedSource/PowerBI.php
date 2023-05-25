<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * Microsoft PowerBI embed source.
 *
 * @EmbedSource(
 *   id = "powerbi",
 *   label = @Translation("Microsoft PowerBI"),
 *   description = @Translation("Microsoft PowerBI embed source."),
 *   thumbnail = "powerbi.png",
 *   active = TRUE,
 * )
 */
class PowerBI extends EmbedSourceBase implements EmbedSourceInterface {

  /**
   * {@inheritdoc}
   */
  protected static $pattern = '/^https:\/\/app.powerbi.com\/view(?<form_params>.+)/';

  /**
   * {@inheritdoc}
   */
  protected static $template = '<iframe class="iframe" title="{{ title }}" src="https://app.powerbi.com/view{{ form_params }}" height="100%" width="100%" loading="lazy"></iframe>';

  /**
   * {@inheritdoc}
   */
  protected static $instructions = 'Open a report in the Power BI service. On the File menu, select Embed report > Website or portal. In the Secure embed code dialog, select the value under "Here\'s a link you can use to embed this content."';

  /**
   * {@inheritdoc}
   */
  protected static $example = 'https://app.powerbi.com/view?r=eyJrIjoiYzQ1ODA0ZjEtZjc5YS00OTgyLWIzOTItNmJmNDY2YmRiODQ2IiwidCI6ImRkOGNiZWJiLTIxMzktNGRmOC1iNDExLTRlM2U4N2FiZWI1YyIsImMiOjF9&pageName=ReportSection2ac2649f17189885d376';

  /**
   * {@inheritdoc}
   */
  protected static $display_attributes = [
    'width' => '100%',
    'height' => '100%',
    'scrolling' => 'yes',
    'frameborder' => 'no',
    'embed_type' => 'form',
    'isIframe' => TRUE,
  ];

  /**
   * {@inheritdoc}
   */
  public function getUrl(array $params): string {
    $form_params = $params['form_params'];
    return 'https://app.powerbi.com/view' . $form_params;
  }

}
