<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * Microsoft forms embed source.
 *
 * @EmbedSource(
 *   id = "msforms",
 *   label = @Translation("Microsoft form"),
 *   description = @Translation("Microsoft form embed."),
 *   thumbnail = "msforms.png",
 *   active = TRUE,
 * )
 */
class MicrosoftForm extends EmbedSourceBase implements EmbedSourceInterface {
  /**
   * {@inheritdoc}
   */
  protected static $pattern = '/^<iframe.+src=\"https:\/\/forms\.office\.com\/Pages\/ResponsePage.aspx(?<form_params>[^"]+)".+/';

  /**
   * {@inheritdoc}
   */
  protected static $template = '<iframe width="640px" height="480px" src="https://forms.office.com/Pages/ResponsePage.aspx{{ form_params }}" height="100%" width="100%" loading="lazy"></iframe>\r\n';

  /**
   * {@inheritdoc}
   */
  protected static $instructions = 'On the microsoft forms website, click "Collect responses", and when promped how to collect responses, click "Embed" and copy the code.';

  /**
   * {@inheritdoc}
   */
  protected static $example = '<iframe width="640px" height="480px" src="https://forms.office.com/Pages/ResponsePage.aspx?id=u76M3Tkh-E20EU4-h6vrXJ-OMhrDFtBEifIUjjt2g_xURUVBU1IyUVlTVFFFNjJQQzJXM1pNMVozVi4u&embed=true" frameborder="0" marginwidth="0" marginheight="0" style="border: none; max-width:100%; max-height:100vh" allowfullscreen webkitallowfullscreen mozallowfullscreen msallowfullscreen> </iframe>';

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
    $form_params = $params['form_params'];
    return 'https://forms.office.com/Pages/ResponsePage.aspx' . $form_params;
  }

}
