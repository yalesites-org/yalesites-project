<?php

namespace Drupal\ys_core;

use Drupal\Core\Session\AccountInterface;

/**
 * Answers whether an account is a platform admin.
 *
 * This holds the platform's definition of that question
 * (yalesites-org/YaleSites-Internal#1560). Everything that needs to know -
 * settings forms with a platform-admin-only field, menu and local action
 * alters, plugins - asks this service rather than open-coding a role or
 * permission check, so the definition lives in one place and cannot drift.
 *
 * Routes reach it through
 * \Drupal\ys_core\Access\PlatformAdminAccessCheck, because YAML cannot call a
 * service. Gating a route on the bare `_permission` requirement instead would
 * not consult this service at all, and would refuse user 1 if
 * security.enable_super_user were ever turned off while this service still
 * counted them (yalesites-org/YaleSites-Internal#1695). Anything
 * platform-admin-only should therefore use that access check rather than the
 * permission.
 *
 * It is not the platform's only access rule, and is not meant to be.
 * \Drupal\ys_beacon\Access\BeaconSuperadminOnlyAccessCheck is deliberately
 * stricter - user 1 alone, with an explicit forbidden() - because the Beacon
 * operator settings it guards are a narrower audience than "platform admin".
 * A caller needing that stricter scope should say so in its own name, the way
 * that class does, rather than widening this definition.
 */
interface PlatformAdminCheckerInterface {

  /**
   * The permission that marks a user as a platform admin.
   *
   * It is granted to the platform_admin role alone. The declaration in
   * ys_core.permissions.yml necessarily repeats the string, because YAML
   * cannot reference a PHP constant - so a rename here has to move that with
   * it, and PlatformAdminCheckerTest pins the value for that reason.
   *
   * Note that routes do not name this permission: the Platform Admin Settings
   * route goes through PlatformAdminAccessCheck, whose own test pins that
   * wiring.
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
