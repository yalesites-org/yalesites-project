<?php

namespace Drupal\ys_book\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

class BookOutlineAccessCheck implements AccessInterface {

  public function access(AccountInterface $account): AccessResultInterface {
    $allowed = $account->hasPermission('administer book outlines')
      || $account->hasPermission('reorder book pages');

    return AccessResult::allowedIf($allowed);
  }

}
