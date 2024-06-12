<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * JWPlayer post embed source.
 *
 * @EmbedSource(
 *   id = "jwplayer",
 *   label = @Translation("JW Player video"),
 *   description = @Translation("JW Player embed for videos hosted on the jwplatform"),
 *   thumbnail = "jwplayer.png",
 *   active = TRUE,
 * )
 */
class JWPlayer extends EmbedSourceBase implements EmbedSourceInterface {

  /**
   * {@inheritdoc}
   */
  protected static $pattern = '/<iframe.+src="(?<url>https:\/\/content.jwplatform.com\/players\/[^"]+)"/';
    protected static $pattern = '/<iframe.+src="(?<url>https:\/\/(content|cdn).(jwplatform|jwplayer).com\/players\/[^"]+)"/';
  /**
   * {@inheritdoc}
   */
  protected static $template = '<iframe src="{{$url}}"></iframe>\r\n';

  /**
   * {@inheritdoc}
   */
  protected static $instructions = 'Retrieve the jwplayer iframe embed code from jwplatform.';

  /**
   * {@inheritdoc}
   */
  protected static $example = <<<EXAMPLE
<div>
<div>
<iframe allowfullscreen="" frameborder="0" height="551" scrolling="auto" 
src="https://content.jwplatform.com/players/2sVMfwDJ-XjbdEvEx.html" width="980">
</iframe>
</div>
</div>
<p>&nbsp;</p>
<div>
EXAMPLE;

  /**
   * {@inheritdoc}
   */
  protected static $displayAttributes = [
    'width' => '100%',
    'height' => '100%',
    'scrolling' => 'no',
    'frameborder' => 'no',
    'isIframe' => TRUE,
  ];

}
