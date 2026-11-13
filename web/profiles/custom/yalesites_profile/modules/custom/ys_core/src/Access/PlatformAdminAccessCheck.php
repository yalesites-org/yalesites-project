<?php

namespace Drupal\ys_core\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ys_core\PlatformAdminCheckerInterface;

/**
 * Gates a route on the platform's definition of "platform admin".
 *
 * This exists so a route and PHP callers resolve that question the same way.
 * YAML cannot call a service, so a route requiring the permission directly
 * never reaches PlatformAdminChecker - and the two would disagree the day
 * security.enable_super_user is hardened off, with the service still counting
 * user 1 and the route refusing them
 * (yalesites-org/YaleSites-Internal#1695).
 *
 * It holds no policy of its own: the definition stays in the service, and this
 * only makes it reachable from routing YAML.
 *
 * @see \Drupal\ys_core\PlatformAdminCheckerInterface
 */
class PlatformAdminAccessCheck implements AccessInterface {

  /**
   * The route requirement this check applies to.
   *
   * Neither ys_core.routing.yml nor the service tag in ys_core.services.yml
   * can reference this constant, so both repeat the string and must move
   * together. PlatformAdminAccessCheckTest reads the two files and pins them
   * to this value, because renaming one alone leaves the route with no
   * resolved access check at all - which AccessManager treats as neutral, so
   * the page 403s for everyone rather than opening up.
   */
  const REQUIREMENT = '_ys_core_platform_admin';

  /**
   * Constructs a PlatformAdminAccessCheck object.
   *
   * @param \Drupal\ys_core\PlatformAdminCheckerInterface $platformAdminChecker
   *   The service holding the platform admin definition.
   */
  public function __construct(
    protected PlatformAdminCheckerInterface $platformAdminChecker,
  ) {
  }

  /**
   * Checks access to a platform-admin-only route.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account making the request.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account) {
    // allowedIf() is deliberate: it is NEUTRAL when the answer is no, which is
    // what core's PermissionAccessCheck returns for a missing permission. That
    // equivalence is what makes replacing the route's _permission requirement
    // with this check a no-op today. An explicit forbidden() would behave
    // identically on this route, since nothing else gates it, but forbidden
    // always wins an andIf(), so it would quietly override any access check
    // added to the route later. Where that stricter behaviour IS wanted it
    // should be stated in the class name - see
    // \Drupal\ys_beacon\Access\BeaconSuperadminOnlyAccessCheck.
    //
    // Both contexts, not one or the other. cachePerUser() is needed because
    // the definition includes a uid check, so two accounts with identical
    // permissions get different answers when one of them is user 1.
    // cachePerPermissions() is needed because the definition also consults
    // hasPermission(), and it is what carries the config:user.role.* tags:
    // UserCacheContext::getCacheableMetadata() returns empty metadata, while
    // the permissions context bubbles the access-policy metadata that
    // invalidates when a role's permission set is edited. Declaring both costs
    // nothing - CacheContextsManager::convertTokensToKeys() optimizes
    // 'user.permissions' away as implied by the finer 'user', but explicitly
    // merges the metadata of every context it drops, so the key stays per-user
    // and the tags still bubble. This is what core's
    // allowedIfHasPermission() contributed before this check replaced it.
    return AccessResult::allowedIf($this->platformAdminChecker->isPlatformAdmin($account))
      ->cachePerPermissions()
      ->cachePerUser();
  }

}
