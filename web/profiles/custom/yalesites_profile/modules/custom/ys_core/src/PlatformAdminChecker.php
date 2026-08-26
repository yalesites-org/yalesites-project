<?php

namespace Drupal\ys_core;

use Drupal\Core\Session\AccountInterface;

/**
 * Decides whether an account is a platform admin.
 *
 * @see \Drupal\ys_core\PlatformAdminCheckerInterface
 */
class PlatformAdminChecker implements PlatformAdminCheckerInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a PlatformAdminChecker object.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user, used when no account is passed to isPlatformAdmin().
   */
  public function __construct(AccountInterface $current_user) {
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public function isPlatformAdmin(?AccountInterface $account = NULL): bool {
    $account = $account ?? $this->currentUser;

    // User 1 is stated rather than left to Drupal's permission bypass. Since
    // Drupal 10.3 that bypass lives in SuperUserAccessPolicy behind the
    // security.enable_super_user container parameter, so a permission check
    // alone would stop being true for user 1 the day that parameter is
    // hardened off. Note this covers callers of this service only - routes
    // gated with a bare _permission requirement, including this module's own
    // ys_core.platform_admin_settings, would still 403 for user 1 in that
    // scenario. See PlatformAdminCheckerInterface for that caveat.
    return (int) $account->id() === 1
      || $account->hasPermission(self::PERMISSION);
  }

}
