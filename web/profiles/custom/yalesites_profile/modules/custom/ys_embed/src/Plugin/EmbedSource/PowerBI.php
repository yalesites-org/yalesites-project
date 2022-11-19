<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * Class PowerBI.
 *
 * @EmbedSource(
 *   id = "powerbi",
 *   label = @Translation("Microsoft PowerBI"),
 *   description = @Translation("Microsoft PowerBI embed provider plugin."),
 *   active = TRUE,
 *   require_title = TRUE,
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
  protected static $instructions = 'Open a report in the Power BI service. On the File menu, select Embed report > Website or portal. In the Secure embed code dialog, select the value under "Here\'s a link you can use to embed this content."';

  /**
   * {@inheritdoc}
   */
  protected static $example = 'https://app.powerbi.com/view?r=eyJrIjoiYzQ1ODA0ZjEtZjc5YS00OTgyLWIzOTItNmJmNDY2YmRiODQ2IiwidCI6ImRkOGNiZWJiLTIxMzktNGRmOC1iNDExLTRlM2U4N2FiZWI1YyIsImMiOjF9&pageName=ReportSection2ac2649f17189885d376';

}
