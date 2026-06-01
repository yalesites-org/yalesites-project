<?php

namespace Drupal\ys_core;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Unicode;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Fetches editorial dashboard announcements from a configured JSON feed.
 *
 * The feed follows the JSON Feed 1.1 spec. The YaleSites platform team
 * publishes announcements on yalesites.yale.edu and exposes them as a JSON
 * feed; each downstream site points
 * `ys_core.dashboard_settings:announcements_feed_url` at that endpoint. When no
 * URL is configured the dashboard simply omits the announcements section.
 *
 * @see https://www.jsonfeed.org/version/1.1/
 */
class DashboardAnnouncements {

  /**
   * Key/value collection used to cache the fetched feed.
   */
  const STORE_COLLECTION = 'ys_core_dashboard';

  /**
   * Key/value entry holding the parsed announcements.
   */
  const STORE_KEY = 'announcements';

  /**
   * Seconds to cache an empty result after a fetch failure.
   *
   * Short, so a transient outage does not hide announcements for long, but
   * long enough to avoid hammering the remote endpoint on every page load.
   */
  const FAILURE_MAX_AGE = 300;

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected KeyValueExpirableFactoryInterface $keyValueExpirable,
    protected DateFormatterInterface $dateFormatter,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Returns announcements to display on the dashboard.
   *
   * @return array
   *   A list of announcements, each an array with `title`, `url`, `summary`,
   *   `date` (formatted string) and `timestamp` (int|null) keys. Returns an
   *   empty array when no feed is configured or the feed cannot be read.
   */
  public function getAnnouncements(): array {
    $config = $this->configFactory->get('ys_core.dashboard_settings');
    $feed_url = trim((string) $config->get('announcements_feed_url'));
    if ($feed_url === '') {
      return [];
    }

    $store = $this->keyValueExpirable->get(self::STORE_COLLECTION);
    $cached = $store->get(self::STORE_KEY);
    if ($cached !== NULL) {
      return $cached;
    }

    $limit = (int) ($config->get('announcements_limit') ?: 5);
    $max_age = (int) ($config->get('announcements_max_age') ?: 3600);

    try {
      $body = (string) $this->httpClient->get($feed_url)->getBody();
      $feed = Json::decode($body);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('ys_core')->error('Unable to fetch dashboard announcements feed: @message', ['@message' => $e->getMessage()]);
      $store->setWithExpire(self::STORE_KEY, [], self::FAILURE_MAX_AGE);
      return [];
    }

    // Accept either a JSON Feed 1.1 envelope ({"items": [...]}) or a plain JSON
    // array of items, as produced by a Views REST export.
    if (is_array($feed) && isset($feed['items']) && is_array($feed['items'])) {
      $items = $feed['items'];
    }
    elseif (is_array($feed) && array_is_list($feed)) {
      $items = $feed;
    }
    else {
      $items = [];
    }

    if (empty($items)) {
      $this->loggerFactory->get('ys_core')->warning('Dashboard announcements feed at @url returned no items or an invalid format.', ['@url' => $feed_url]);
      $store->setWithExpire(self::STORE_KEY, [], $max_age);
      return [];
    }

    $announcements = [];
    foreach (array_slice(array_values($items), 0, $limit) as $item) {
      if (!is_array($item)) {
        continue;
      }
      // date_published may be an ISO 8601 string (JSON Feed) or a Unix
      // timestamp (Views REST export raw output).
      $raw_date = $item['date_published'] ?? '';
      if (is_numeric($raw_date)) {
        $timestamp = (int) $raw_date;
      }
      else {
        $timestamp = $raw_date !== '' ? strtotime((string) $raw_date) : FALSE;
      }
      $summary_source = $item['summary'] ?? $item['content_text'] ?? $item['content_html'] ?? '';
      $announcements[] = [
        'title' => isset($item['title']) ? (string) $item['title'] : '',
        'url' => isset($item['url']) ? UrlHelper::stripDangerousProtocols((string) $item['url']) : '',
        'summary' => trim(Unicode::truncate(strip_tags((string) $summary_source), 300, TRUE, TRUE)),
        'timestamp' => $timestamp ?: NULL,
        'date' => $timestamp ? $this->dateFormatter->format($timestamp, 'custom', 'F j, Y') : '',
      ];
    }

    $store->setWithExpire(self::STORE_KEY, $announcements, $max_age);
    return $announcements;
  }

  /**
   * Clears the cached announcements so the next request refetches the feed.
   */
  public function clearCache(): void {
    $this->keyValueExpirable->get(self::STORE_COLLECTION)->delete(self::STORE_KEY);
  }

}
