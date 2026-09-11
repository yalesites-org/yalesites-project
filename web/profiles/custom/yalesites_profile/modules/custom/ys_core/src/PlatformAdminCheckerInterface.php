<?php

namespace Drupal\ys_core;

use Drupal\Core\Session\AccountInterface;

/**
 * Answers whether an account is a platform admin.
 *
 * This is the platform's single mechanism for that question
 * (yalesites-org/YaleSites-Internal#1560). Everything that needs to know -
 * settings forms with a platform-admin-only field, menu and local action
 * alters, plugins - asks this service rather than open-coding a role or
 * permission check, so the definition lives in one place and cannot drift.
 *
 * The scope is PHP callers of this service. Routes gated declaratively with a
 * bare `_permission` requirement - including this module's own
 * ys_core.platform_admin_settings - do not go through here, because YAML
 * cannot call a service. That only matters if security.enable_super_user is
 * ever turned off: user 1 would still satisfy this service but would be
 * refused those routes. Closing that gap would mean giving the routes a
 * _custom_access check that delegates here.
 */
interface PlatformAdminCheckerInterface {

  /**
   * The permission that marks a user as a platform admin.
   *
   * It gates the Platform Admin Settings route, and it is granted to the
   * platform_admin role alone. The declaration in ys_core.permissions.yml and
   * the route requirement in ys_core.routing.yml necessarily repeat the string,
   * because YAML cannot reference a PHP constant - so a rename here has to move
   * both of those with it. PlatformAdminCheckerTest pins the value for that
   * reason.
   */
  const PERMISSION = 'administer platform admin settings';

  /**
   * Checks whether an account is a platform admin.
   *
   * @param \Drupal\Core\Session\AccountInterface|null $account
   *   The account to check, or NULL to check the current user.
   *
   * @return bool
   *   TRUE if the account is user 1 or holds self::PERMISSION.
   */
  public function isPlatformAdmin(?AccountInterface $account = NULL): bool;

}
