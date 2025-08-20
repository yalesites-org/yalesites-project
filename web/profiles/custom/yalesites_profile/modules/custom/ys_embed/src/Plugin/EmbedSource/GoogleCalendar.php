<?php

namespace Drupal\ys_embed\Plugin\EmbedSource;

use Drupal\ys_embed\Plugin\EmbedSourceBase;
use Drupal\ys_embed\Plugin\EmbedSourceInterface;

/**
 * Google Calendar embed source.
 *
 * @EmbedSource(
 *   id = "google_calendar",
 *   label = @Translation("Google Calendar"),
 *   description = @Translation("Google Calendar embed source."),
 *   thumbnail = "generic.png",
 *   active = TRUE,
 * )
 */
class GoogleCalendar extends EmbedSourceBase implements EmbedSourceInterface {

  /**
   * {@inheritdoc}
   */
  protected static $pattern = '/^<iframe[^>]*src=\"https:\/\/calendar\.google\.com\/calendar\/embed(?<calendar_params>\?.+?)\"[^>]*>.*<\/iframe>$/s';

  /**
   * {@inheritdoc}
   */
  protected static $template = '<iframe class="iframe" title="{{ title|default("Google Calendar") }}" src="https://calendar.google.com/calendar/embed{{ calendar_params }}" height="100%" width="100%" style="border:0;" loading="lazy" referrerpolicy="no-referrer-when-downgrade" aria-label="Google Calendar widget"></iframe>';

  /**
   * {@inheritdoc}
   */
  protected static $instructions = '<p>To embed a Google Calendar:</p>
<ol>
  <li>Go to <a href="https://calendar.google.com" target="_blank">Google Calendar</a></li>
  <li>Find the calendar you want to embed in the left sidebar</li>
  <li>Click the three dots next to the calendar name</li>
  <li>Select "Settings and sharing"</li>
  <li>Scroll down to "Integrate calendar" section</li>
  <li>Copy the embed code from the "Embed code" field</li>
  <li>Paste the complete iframe code here</li>
</ol>
<p><strong>Note:</strong> Make sure your calendar is set to "Public" or "See all event details" in the sharing settings.</p>';

  /**
   * {@inheritdoc}
   */
  protected static $example = '<iframe src="https://calendar.google.com/calendar/embed?src=en.usa%23holiday%40group.v.calendar.google.com&ctz=America%2FNew_York" style="border: 0" width="800" height="600" frameborder="0" scrolling="no"></iframe>';

  /**
   * {@inheritdoc}
   */
  protected static $displayAttributes = [
    'width' => '100%',
    'height' => '100%',
    'scrolling' => 'no',
    'frameborder' => 'no',
    'embedType' => 'map',
    'isIframe' => TRUE,
  ];

  /**
   * {@inheritdoc}
   */
  public function getUrl(array $params): string {
    return 'https://calendar.google.com/calendar/embed' . $params['calendar_params'];
  }

}
