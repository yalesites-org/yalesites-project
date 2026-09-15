<?php

namespace Drupal\ys_core\Toolbar;

use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ys_core\DashboardAnnouncements;

/**
 * Lazy builder that renders the unread-announcements badge on the admin menu.
 *
 * The placeholder is attached to the Dashboard menu link in
 * `ys_core_preprocess_menu()`. Keeping the per-user count behind a lazy
 * builder lets the surrounding admin menu render stay shared across users.
 *
 * The viewer is resolved here rather than passed in as an argument.
 *
 * @see _ys_core_attach_dashboard_badge_walk()
 */
class DashboardToolbarLazyBuilders implements TrustedCallbackInterface {

  use StringTranslationTrait;

  public function __construct(
    protected DashboardAnnouncements $announcements,
    protected AccountInterface $currentUser,
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
   * Returns an empty render array when the user has nothing unread.
   *
   * There is no `#cache['keys']`, so the badge is rebuilt for whoever the
   * placeholder is replaced for rather than stored. That, not tag
   * invalidation, is what keeps it per-user correct: on the page pipeline
   * placeholders are rendered by HtmlResponseAttachmentsProcessor at
   * `kernel.response` priority 0, after Dynamic Page Cache has already stored
   * the response at priority 7, so these tags never reach that entry. They are
   * carried for render paths that do replace placeholders within renderRoot().
   */
  public function renderBadge(): array {
    $build = [
      '#cache' => [
        'contexts' => ['user'],
        'tags' => [
          DashboardAnnouncements::unreadCacheTag((int) $this->currentUser->id()),
          DashboardAnnouncements::FEED_CACHE_TAG,
          'config:ys_core.dashboard_settings',
        ],
        'max-age' => 3600,
      ],
    ];
    // An anonymous account resolves no unread state, so this is 0 for them.
    $count = $this->announcements->getUnreadCount($this->currentUser);
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
