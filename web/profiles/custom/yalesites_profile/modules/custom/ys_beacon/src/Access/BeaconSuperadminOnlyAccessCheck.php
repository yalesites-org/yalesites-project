<?php

namespace Drupal\ys_beacon\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Restricts the Beacon administration form to user 1 only.
 *
 * The administration form holds the most sensitive Beacon operator settings,
 * so access is limited to the platform superadmin (user 1). No other role,
 * however privileged, may reach it. User 1 bypasses permission (_permission)
 * checks but not custom _access checks like this one, so this check must
 * itself decide access: it returns an explicit AccessResult::allowed() for
 * user 1 and AccessResult::forbidden() for everyone else. Returning an explicit
 * forbidden (rather than a neutral result) keeps the route denied for all
 * others under any access-combination mode and stays robust if it later gains
 * additional access checks, since a forbidden result always wins.
 *
 * "Superadmin only" is in the name because this is deliberately stricter than
 * the platform's general platform-admin definition
 * (yalesites-org/YaleSites-Internal#1695): a platform admin holding
 * 'administer platform admin settings' satisfies
 * \Drupal\ys_core\PlatformAdminCheckerInterface but is refused here. Anything
 * wanting the broader audience should gate on
 * \Drupal\ys_core\Access\PlatformAdminAccessCheck instead of on this.
 */
class BeaconSuperadminOnlyAccessCheck implements AccessInterface {

  /**
   * The route requirement this check applies to.
   *
   * Repeated in ys_beacon.routing.yml and in the service tag in
   * ys_beacon.services.yml, since YAML cannot reference this constant.
   * BeaconSuperadminOnlyAccessCheckTest pins both to this value: a route
   * requirement with no matching check resolves to no access check at all,
   * which AccessManager treats as neutral, so the form would 403 for user 1
   * too.
   */
  const REQUIREMENT = '_ys_beacon_superadmin_only';

  /**
   * Checks access to the Beacon administration form.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account) {
    if ((int) $account->id() === 1) {
      return AccessResult::allowed()->cachePerUser();
    }
    return AccessResult::forbidden('The Beacon administration form is restricted to user 1.')
      ->cachePerUser();
  }

}
