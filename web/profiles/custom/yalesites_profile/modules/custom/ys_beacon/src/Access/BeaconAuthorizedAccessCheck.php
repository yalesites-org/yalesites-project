<?php

namespace Drupal\ys_beacon\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ys_beacon\BeaconAuthorization;

/**
 * Checks that Beacon is platform-authorized for the site.
 *
 * Applied additively to the Beacon settings and system instructions routes, so
 * their menu links and pages are denied until a platform admin authorizes
 * Beacon. It gates on the per-site authorization flag only; the existing
 * permission and integration checks on those routes continue to apply.
 */
class BeaconAuthorizedAccessCheck implements AccessInterface {

  /**
   * Constructs a BeaconAuthorizedAccessCheck object.
   *
   * @param \Drupal\ys_beacon\BeaconAuthorization $beaconAuthorization
   *   The Beacon authorization service.
   */
  public function __construct(
    protected BeaconAuthorization $beaconAuthorization,
  ) {
  }

  /**
   * Checks access based on the per-site Beacon authorization flag.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account) {
    $cache_tags = ['config:' . BeaconAuthorization::CONFIG_NAME];
    if (!$this->beaconAuthorization->isAuthorized()) {
      return AccessResult::forbidden('Beacon is not authorized for this site.')
        ->addCacheTags($cache_tags);
    }
    return AccessResult::allowed()->addCacheTags($cache_tags);
  }

}
