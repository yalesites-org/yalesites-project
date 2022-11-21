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
 *   description = @Translation("Qualtrics survey embed source."),
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
  protected static $instructions = 'Visit your Qualtrics form and copy the survey\'s URL. This web address will be used to create an iframe within Drupal.';

  /**
   * {@inheritdoc}
   */
  protected static $example = 'https://yalesurvey.ca1.qualtrics.com/jfe/form/SV_cDezt2JVsNok77o';

}
