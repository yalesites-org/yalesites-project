<?php

namespace Drupal\ys_book\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Determines access to the content collection management screens.
 *
 * Allows access for accounts that have either the broad
 * 'administer book outlines' permission or the narrower 'reorder book pages'
 * permission. This is what lets the platform's editing roles manage
 * collections without holding contrib book's administer-everything
 * permission.
 *
 * The broad permission is still accepted so that a site which granted it to a
 * role of its own does not lose access.
 *
 * @see yalesites-org/YaleSites-Internal#1573
 */
class BookOutlineAccessCheck implements AccessInterface {

  /**
   * Checks access to a content collection management screen.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed if the account has 'administer book outlines' or
   *   'reorder book pages' permission; neutral otherwise.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermissions(
      $account,
      ['administer book outlines', 'reorder book pages'],
      'OR'
    );
  }

}
