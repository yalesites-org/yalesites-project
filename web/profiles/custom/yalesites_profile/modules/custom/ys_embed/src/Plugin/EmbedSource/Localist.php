<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * Localist widget embed source.
 *
 * @EmbedSource(
 *   id = "localist",
 *   label = @Translation("Localist"),
 *   description = @Translation("Localist widget embed source."),
 *   thumbnail = "localist.png",
 *   active = TRUE,
 * )
 */
class Localist extends EmbedSourceBase implements EmbedSourceInterface {

  /**
   * {@inheritdoc}
   */
  protected static $pattern = '/<div\s+id="localist-widget-(?<widget_id>\d+)"\s+class="localist-widget"><\/div><script\s+defer\s+type="text\/javascript"\s+src="(?<localist_source>[^"]+)"><\/script>/';

  /**
   * {@inheritdoc}
   */
  protected static $template = '<div id="localist-widget-{{ widget_id }}" class="localist-widget"></div><script defer type="text/javascript" src="{{ localist_source }}"></script>';

  /**
   * {@inheritdoc}
   */
  protected static $instructions = 'On the Localist "Build an Events Widget" page, fill out the fields to filter which events should be visible in the widget. At the bottom of the builder, click "Generate Embed Code", and copy the "Code" text in the text box.';

  /**
   * {@inheritdoc}
   */
  protected static $example = '<div id="localist-widget-86302562" class="localist-widget"></div><script defer type="text/javascript" src="https://calendar.yale.edu/widget/view?schools=yale&groups=asian-network-at-yale&days=31&num=50&experience=inperson&container=localist-widget-86302562&template=modern"></script><div id="lclst_widget_footer"><a style="margin-left:auto;margin-right:auto;display:block;width:81px;margin-top:10px;" title="Widget powered by Concept3D Event Calendar Software" href="https://www.localist.com?utm_source=widget&utm_campaign=widget_footer&utm_medium=branded%20link"><img src="//d3e1o4bcbhmj8g.cloudfront.net/assets/platforms/default/about/widget_footer.png" alt="Localist Online Calendar Software" style="vertical-align: middle;" width="81" height="23"></a></div>';

}
