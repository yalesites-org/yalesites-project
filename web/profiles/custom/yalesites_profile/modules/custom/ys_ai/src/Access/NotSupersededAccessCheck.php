<?php

namespace Drupal\ys_ai\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ys_ai\BeaconSupersession;

/**
 * Denies the legacy YaleSites AI routes once Beacon supersedes them.
 *
 * Applied additively to every ys_ai-owned route, so a bookmarked URL cannot
 * reach a form whose menu link has been hidden. Menu links follow route
 * access in Drupal, so this is also what removes the legacy items from the
 * Integrations menu and from Configuration > AI Engine. The permission and
 * configuration checks already on those routes continue to apply.
 */
class NotSupersededAccessCheck implements AccessInterface {

  /**
   * Constructs a NotSupersededAccessCheck object.
   *
   * @param \Drupal\ys_ai\BeaconSupersession $supersession
   *   The legacy AI supersession service.
   */
  public function __construct(
    protected BeaconSupersession $supersession,
  ) {
  }

  /**
   * Checks access against the Beacon supersession state.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account) {
    // Forbidden rather than neutral, so nothing else on the route can grant
    // what this denies; allowed rather than neutral, because Drupal requires
    // every requirement on a route to return allowed.
    $result = $this->supersession->isSuperseded()
      ? AccessResult::forbidden('Beacon supersedes the legacy YaleSites AI configuration.')
      : AccessResult::allowed();

    return $result->addCacheTags($this->supersession->getCacheTags());
  }

}
