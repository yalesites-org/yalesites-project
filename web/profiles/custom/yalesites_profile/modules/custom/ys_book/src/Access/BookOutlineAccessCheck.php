<?php

namespace Drupal\ys_book\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Determines access to the book outline admin edit form.
 *
 * Allows access for accounts that have either the broad
 * 'administer book outlines' permission or the narrower 'reorder book pages'
 * permission. This avoids requiring the full administer permission just to
 * reorder content collection pages.
 */
class BookOutlineAccessCheck implements AccessInterface {

  /**
   * Checks access to the book outline admin edit form.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed if the account has 'administer book outlines' or
   *   'reorder book pages' permission; neutral otherwise.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    $allowed = $account->hasPermission('administer book outlines')
      || $account->hasPermission('reorder book pages');

    return AccessResult::allowedIf($allowed);
  }

}
