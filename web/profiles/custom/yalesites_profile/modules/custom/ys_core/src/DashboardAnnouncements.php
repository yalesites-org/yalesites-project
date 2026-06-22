<?php

namespace Drupal\ys_core;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Unicode;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserDataInterface;
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

  /**
   * The canonical platform announcements feed URL.
   *
   * Used when `ys_core.dashboard_settings:announcements_feed_url` is empty,
   * which is the default. The config key exists as a per-site override (set
   * via drush, e.g. for staging environments) and is intentionally not
   * exposed in the dashboard settings form.
   */
  const PLATFORM_FEED_URL = 'https://yalesites.yale.edu/api/dashboard-announcements';

  /**
   * The user.data module/key for the "newest announcement seen" timestamp.
   */
  const USER_DATA_MODULE = 'ys_core';
  const USER_DATA_LAST_SEEN = 'announcements_last_seen';

  /**
   * Cache tag invalidated whenever the cached feed contents change.
   *
   * Anything that renders feed content (the dashboard page, the menu badge)
   * should depend on this tag so it picks up fresh items as soon as the
   * keyvalue cache is cleared.
   */
  const FEED_CACHE_TAG = 'ys_core:announcements_feed';

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected KeyValueExpirableFactoryInterface $keyValueExpirable,
    protected DateFormatterInterface $dateFormatter,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected UserDataInterface $userData,
  ) {}

  /**
   * Cache tag for invalidating one user's badge render.
   */
  public static function unreadCacheTag(int|string $uid): string {
    return 'ys_core:dashboard_badge:' . $uid;
  }

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
    // Sites can opt out of consuming platform announcements via the dashboard
    // settings form. Missing key defaults to enabled.
    if ($config->get('announcements_enabled') === FALSE) {
      return [];
    }
    $feed_url = trim((string) $config->get('announcements_feed_url')) ?: self::PLATFORM_FEED_URL;

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
    $valid_format = FALSE;
    $items = [];
    if (is_array($feed)) {
      if (isset($feed['items']) && is_array($feed['items'])) {
        $valid_format = TRUE;
        $items = $feed['items'];
      }
      elseif (array_is_list($feed)) {
        $valid_format = TRUE;
        $items = $feed;
      }
    }

    if (!$valid_format) {
      $this->loggerFactory->get('ys_core')->warning('Dashboard announcements feed at @url returned an invalid format.', ['@url' => $feed_url]);
      $store->setWithExpire(self::STORE_KEY, [], $max_age);
      return [];
    }

    // A valid but empty feed is a legitimate state (no announcements right
    // now), so cache it without logging.
    if (empty($items)) {
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
   *
   * Also invalidates the feed cache tag so the dashboard render and the
   * toolbar badge re-render with the fresh items.
   */
  public function clearCache(): void {
    $this->keyValueExpirable->get(self::STORE_COLLECTION)->delete(self::STORE_KEY);
    Cache::invalidateTags([self::FEED_CACHE_TAG]);
  }

  /**
   * Counts announcements newer than the user's last-seen timestamp.
   *
   * Reads the already-cached feed, so this is a cheap operation that adds no
   * extra HTTP requests to the upstream endpoint.
   */
  public function getUnreadCount(AccountInterface $account): int {
    if ($account->isAnonymous()) {
      return 0;
    }
    $items = $this->getAnnouncements();
    if (empty($items)) {
      return 0;
    }
    $last_seen = (int) ($this->userData->get(self::USER_DATA_MODULE, (int) $account->id(), self::USER_DATA_LAST_SEEN) ?? 0);
    $count = 0;
    foreach ($items as $item) {
      if (!empty($item['timestamp']) && (int) $item['timestamp'] > $last_seen) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * Records that the user has seen every current announcement.
   *
   * Stores the newest current timestamp; future items dated later than that
   * will count as unread.
   */
  public function markAllRead(AccountInterface $account): void {
    if ($account->isAnonymous()) {
      return;
    }
    $items = $this->getAnnouncements();
    if (empty($items)) {
      return;
    }
    $newest = 0;
    foreach ($items as $item) {
      if (!empty($item['timestamp']) && (int) $item['timestamp'] > $newest) {
        $newest = (int) $item['timestamp'];
      }
    }
    if ($newest === 0) {
      return;
    }
    $uid = (int) $account->id();
    $current = (int) ($this->userData->get(self::USER_DATA_MODULE, $uid, self::USER_DATA_LAST_SEEN) ?? 0);
    if ($newest > $current) {
      $this->userData->set(self::USER_DATA_MODULE, $uid, self::USER_DATA_LAST_SEEN, $newest);
      Cache::invalidateTags([self::unreadCacheTag($uid)]);
    }
  }

}
