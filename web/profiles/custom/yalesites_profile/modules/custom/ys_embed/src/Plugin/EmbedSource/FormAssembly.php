<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * FormAssembly widget embed source.
 *
 * @EmbedSource(
 *  id = "formassembly",
 *  label = @Translation("FormAssembly"),
 *  description = @Translation("FormAssembly widget embed source."),
 *  thumbnail = "formassembly.png",
 *  active = TRUE,
 *  )
 */
class FormAssembly extends EmbedSourceBase implements EmbedSourceInterface {
  /**
   * {@inheritdoc}
   */
  protected static $pattern = '/<iframe.+src="(?<url>https:\/\/.+tfaforms.(com|net)\/[^"]+)"[^>]+>/';
  /**
   * {@inheritdoc}
   */
  protected static $template = '<iframe src="{{ url }}"></iframe><script src="//tfaforms.com/js/iframe_resize_helper.js"></script>';
  /**
   * {@inheritdoc}
   */
  protected static $instructions = 'Click "Configure" for the form you want to publish, and select Publish from the left-side menu.  On the publish page, copy the public address.';
  /**
   * {@inheritdoc}
   */
  protected static $example = '<iframe src="https://tfaforms.com/YOUR_FORM_ID" height="800" width="600" frameborder="0" ></iframe> <script src="//tfaforms.com/js/iframe_resize_helper.js"></script>';

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

}
