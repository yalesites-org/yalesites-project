<?php

namespace Drupal\ys_core\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\taxonomy\TermInterface;
use Drupal\ys_core\Plugin\PlatformAdminSetting\AnnouncementsSourcePlatformAdminSetting;
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

    // Normalized the same way the settings form stores and displays it, and
    // through the same constant, so the query cannot look for a name the form
    // would never have written. A whitespace-only value set out of band - by
    // drush, or a settings.php override the form cannot see - would otherwise
    // match no term and answer 200 with an empty feed.
    $term_name = trim((string) $config->get('announcements_source_term'))
      ?: AnnouncementsSourcePlatformAdminSetting::DEFAULT_TERM;

    $category_whitelist = AnnouncementsSourcePlatformAdminSetting::resolveCategoryWhitelist($config->get('announcements_source_categories'));

    $cacheability = (new CacheableMetadata())
      ->addCacheTags(['node_list:post', 'taxonomy_term_list:tags', 'taxonomy_term_list:post_category'])
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
        $category_names = [];
        if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
          foreach ($node->get('field_category')->referencedEntities() as $term) {
            if ($term instanceof TermInterface) {
              $category_names[] = $term->getName();
            }
          }
        }
        $items[] = [
          'id' => (string) $node->id(),
          'title' => $node->getTitle(),
          'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
          'date_published' => date('c', $node->getChangedTime()),
          'summary' => $summary,
          // JSON Feed 1.1's own `tags` field is documented as covering exactly
          // this ("some blogging systems and other feed formats call these
          // categories") - no custom extension key needed. Always an array,
          // empty when the post has no category or none survive the site's
          // whitelist, so a consumer never has to special-case a missing key.
          'tags' => self::filterCategories($category_names, $category_whitelist),
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

  /**
   * Filters a post's category names down to the site's published whitelist.
   *
   * Matching is case-insensitive - the category field allows auto-created
   * terms, so editor-typed casing drift ("news" vs "News") is a real case -
   * but each surviving name is emitted in its own original casing rather than
   * the whitelist's. Extracted as a pure static method so the filtering logic
   * is Unit-testable without loading real node/taxonomy entities.
   *
   * @param string[] $names
   *   The post's category term names, in any order.
   * @param string[] $whitelist
   *   The site's configured category whitelist.
   *
   * @return string[]
   *   The whitelisted names, trimmed, deduped, and in their original casing.
   */
  public static function filterCategories(array $names, array $whitelist): array {
    $whitelist_lower = array_map(fn($name) => mb_strtolower(trim((string) $name)), $whitelist);

    $result = [];
    foreach ($names as $name) {
      $name = trim((string) $name);
      if ($name === '' || in_array($name, $result, TRUE)) {
        continue;
      }
      if (in_array(mb_strtolower($name), $whitelist_lower, TRUE)) {
        $result[] = $name;
      }
    }

    return $result;
  }

}
