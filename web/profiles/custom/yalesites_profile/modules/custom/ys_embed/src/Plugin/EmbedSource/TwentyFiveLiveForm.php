<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * Defines 25Live Pro event form embed source.
 *
 * @EmbedSource(
 *   id = "twenty_five_live_form",
 *   label = @Translation("25Live Event Form"),
 *   description = @Translation("25Live Pro event request form embed."),
 *   thumbnail = "generic.png",
 *   active = TRUE,
 * )
 */
class TwentyFiveLiveForm extends EmbedSourceBase implements EmbedSourceInterface {

  /**
   * {@inheritdoc}
   */
  protected static $pattern = '/^https:\/\/25live\.collegenet\.com\/pro\/yale\/embedded\/preview\?(?<params>.+)/';

  /**
   * {@inheritdoc}
   */
  protected static $template = '<iframe class="iframe" title="{{ title|default("25Live Event Form") }}" src="https://25live.collegenet.com/pro/yale/embedded/preview?{{ params }}" height="100%" width="100%" loading="lazy" scrolling="yes"></iframe>';

  /**
   * {@inheritdoc}
   */
  protected static $instructions = '<p>To embed a 25Live event form:</p>
<ol>
  <li>Log into <a href="https://25live.collegenet.com/pro/yale" target="_blank">25Live Pro (Yale)</a></li>
  <li>Navigate to your event form configuration</li>
  <li>Find the embedded preview URL for your form</li>
  <li>Copy the complete preview URL</li>
  <li>Paste the URL here</li>
</ol>
  <p><strong>Note:</strong> The URL should start with https://25live.collegenet.com/pro/yale/embedded/preview</p>
  <p><strong>Accessibility:</strong> This embed is not fully accessible at this time. Please ensure alternative contact methods are provided for users who may have difficulty with the embedded form.</p>';

  /**
   * {@inheritdoc}
   */
  protected static $example = 'https://25live.collegenet.com/pro/yale/embedded/preview?token=<YOUR-TOKEN>&target=crossCampus&instance=yale';

  /**
   * {@inheritdoc}
   */
  protected static $displayAttributes = [
    'width' => '100%',
    'height' => '800px',
    'scrolling' => 'yes',
    'frameborder' => 'no',
    'embedType' => 'form',
    'isIframe' => TRUE,
  ];

  /**
   * {@inheritdoc}
   */
  public function getUrl(array $params): string {
    return 'https://25live.collegenet.com/pro/yale/embedded/preview?' . ($params['params'] ?? '');
  }

}
