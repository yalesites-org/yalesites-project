<?php

namespace Drupal\ys_core\Toolbar;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ys_core\DashboardAnnouncements;

/**
 * Lazy builder that renders the unread-announcements badge on the admin menu.
 *
 * The placeholder is attached to the Dashboard menu link in
 * `ys_core_preprocess_menu()`. Keeping the per-user count behind a lazy
 * builder lets the surrounding admin menu render stay shared across users.
 */
class DashboardToolbarLazyBuilders implements TrustedCallbackInterface {

  use StringTranslationTrait;

  public function __construct(
    protected DashboardAnnouncements $announcements,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['renderBadge'];
  }

  /**
   * Builds the unread-announcements pill for the Dashboard menu link.
   *
   * Returns an empty render array when the user has no unread items, but the
   * cacheability is preserved so the result re-renders on cache invalidation
   * (e.g. when the user visits the dashboard and `markAllRead()` runs).
   */
  public function renderBadge(string $uid): array {
    $uid = (int) $uid;
    $build = [
      '#cache' => [
        'contexts' => ['user'],
        'tags' => [
          DashboardAnnouncements::unreadCacheTag($uid),
          DashboardAnnouncements::FEED_CACHE_TAG,
          'config:ys_core.dashboard_settings',
        ],
        'max-age' => 3600,
      ],
    ];
    if ($uid <= 0) {
      return $build;
    }
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$account) {
      return $build;
    }
    $count = $this->announcements->getUnreadCount($account);
    if ($count <= 0) {
      return $build;
    }
    $build['badge'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $count,
      '#attributes' => [
        'class' => ['ys-dashboard-badge', 'ys-dashboard-badge--unread'],
        'aria-label' => $this->formatPlural($count, '1 unread announcement', '@count unread announcements'),
      ],
    ];
    return $build;
  }

}
