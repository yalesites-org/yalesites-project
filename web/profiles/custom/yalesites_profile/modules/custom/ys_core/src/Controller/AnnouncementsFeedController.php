<?php

namespace Drupal\ys_core\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Publishes a JSON feed of announcement posts for editorial dashboards.
 *
 * This endpoint ships on every YaleSite but stays dormant: it returns 404
 * unless the site opts in via
 * `ys_core.dashboard_settings:announcements_source_enabled`. The platform site
 * (yalesites.yale.edu) turns it on; downstream dashboards consume it through
 * \Drupal\ys_core\DashboardAnnouncements.
 *
 * @see \Drupal\ys_core\DashboardAnnouncements
 */
class AnnouncementsFeedController extends ControllerBase {

  /**
   * Maximum number of posts to expose in the feed.
   */
  const FEED_LIMIT = 25;

  /**
   * Returns published announcement posts as a JSON Feed 1.1 document.
   */
  public function feed(): CacheableJsonResponse {
    $config = $this->config('ys_core.dashboard_settings');
    if (!$config->get('announcements_source_enabled')) {
      throw new NotFoundHttpException();
    }

    $term_name = $config->get('announcements_source_term') ?: 'Dashboard Announcement';

    $cacheability = (new CacheableMetadata())
      ->addCacheTags(['node_list:post', 'taxonomy_term_list:tags'])
      ->addCacheableDependency($config);

    $items = [];
    $tids = $this->entityTypeManager()->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(TRUE)
      ->condition('vid', 'tags')
      ->condition('name', $term_name)
      ->execute();

    if ($tids) {
      $node_storage = $this->entityTypeManager()->getStorage('node');
      // Sort by `changed` rather than `created` so that a post drafted weeks
      // ago but published today still appears at the top of the feed and gets
      // a current `date_published`. Consumers compare this against the
      // per-user last-seen timestamp to decide what counts as unread, so
      // using the publish-time-or-later value keeps the unread badge honest.
      $nids = $node_storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'post')
        ->condition('status', 1)
        ->condition('field_tags', $tids, 'IN')
        ->sort('changed', 'DESC')
        ->range(0, self::FEED_LIMIT)
        ->execute();

      foreach ($node_storage->loadMultiple($nids) as $node) {
        $summary = '';
        if ($node->hasField('field_teaser_text') && !$node->get('field_teaser_text')->isEmpty()) {
          $summary = (string) $node->get('field_teaser_text')->value;
        }
        $items[] = [
          'id' => (string) $node->id(),
          'title' => $node->getTitle(),
          'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
          'date_published' => date('c', $node->getChangedTime()),
          'summary' => $summary,
        ];
        $cacheability->addCacheableDependency($node);
      }
    }

    $response = new CacheableJsonResponse([
      'version' => 'https://jsonfeed.org/version/1.1',
      'title' => 'YaleSites dashboard announcements',
      'items' => $items,
    ]);
    $response->addCacheableDependency($cacheability);

    return $response;
  }

}
