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
 */
class BeaconAdminAccessCheck implements AccessInterface {

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
