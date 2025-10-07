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
  protected static $pattern = '/^<iframe.+src=\"https:\/\/(?:(?:forms\.office\.com\/Pages\/ResponsePage\.aspx(?<office_params>\?[^"]+))|(?:forms\.cloud\.microsoft\/r\/(?<form_id>[^"\?]+)(?:\?[^"]*)?))".+/';

  /**
   * {@inheritdoc}
   */
  protected static $template = '<iframe width="640px" height="480px" src="{{ url }}" height="100%" width="100%" loading="lazy"></iframe>\r\n';

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
  public function build(array $params): array {
    // Add the constructed URL to params for template use.
    $params['url'] = $this->getUrl($params);
    return parent::build($params);
  }

  /**
   * {@inheritdoc}
   */
  public function getUrl(array $params): string {
    if (isset($params['office_params']) && !empty($params['office_params'])) {
      $sanitized_params = $this->sanitizeOfficeParams($params['office_params']);
      return 'https://forms.office.com/Pages/ResponsePage.aspx' . $sanitized_params;
    }
    if (isset($params['form_id']) && !empty($params['form_id'])) {
      $sanitized_id = $this->sanitizeFormId($params['form_id']);
      return 'https://forms.cloud.microsoft/r/' . $sanitized_id;
    }
    return '';
  }

  /**
   * Sanitizes office.com form parameters.
   *
   * @param string $params
   *   The query string parameters.
   *
   * @return string
   *   The sanitized parameters.
   */
  protected function sanitizeOfficeParams(string $params): string {
    // Minimal sanitization: prevent quote injection and XSS,
    // preserve Microsoft params.
    $sanitized = str_replace('"', '', $params);
    $sanitized = htmlspecialchars($sanitized, ENT_QUOTES, 'UTF-8');
    return substr($sanitized, 0, 2000);
  }

  /**
   * Sanitizes cloud.microsoft form ID.
   *
   * @param string $form_id
   *   The form ID.
   *
   * @return string
   *   The sanitized form ID.
   */
  protected function sanitizeFormId(string $form_id): string {
    // Minimal sanitization: prevent quote injection and basic XSS,
    // preserve Microsoft IDs.
    $sanitized = str_replace(['"', '<', '>', '&'], '', $form_id);
    return substr($sanitized, 0, 100);
  }

}
