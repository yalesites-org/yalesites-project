<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * Qualtrics survey embed source.
 *
 * @EmbedSource(
 *   id = "qualtrics",
 *   label = @Translation("Qualtrics"),
 *   description = @Translation("Qualtrics survey embed source (deprecated)."),
 *   thumbnail = "qualtrics.png",
 *   active = TRUE,
 * )
 */
class Qualtrics extends EmbedSourceBase implements EmbedSourceInterface {

  /**
   * {@inheritdoc}
   */
  protected static $pattern = '/^https:\/\/yalesurvey.ca1.qualtrics.com\/jfe\/form\/(?<form_id>.+)/';

  /**
   * {@inheritdoc}
   */
  protected static $template = '<p>Qualtrics has been removed.  Please recreate your form in Microsoft Forms</p>';

  /**
   * {@inheritdoc}
   */
  protected static $instructions = 'Visit your Qualtrics form and copy the survey\'s URL. This web address will be used to create an iframe within Drupal.';

  /**
   * {@inheritdoc}
   */
  protected static $example = 'https://yalesurvey.ca1.qualtrics.com/jfe/form/SV_cDezt2JVsNok77o';

  /**
   * {@inheritdoc}
   */
  protected static $displayAttributes = [
    'width' => '100%',
    'height' => '100%',
    'scrolling' => 'yes',
    'frameborder' => 'no',
    'embedType' => 'form',
    'isIframe' => TRUE,
  ];

  /**
   * {@inheritdoc}
   */
  public function getUrl(array $params): string {
    $form_id = $params['form_id'];
    return 'https://yalesurvey.ca1.qualtrics.com/jfe/form/' . $form_id;
  }

}
