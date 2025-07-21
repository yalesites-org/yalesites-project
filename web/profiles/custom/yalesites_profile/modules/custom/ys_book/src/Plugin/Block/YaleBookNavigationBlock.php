<?php

namespace Drupal\ys_book\Plugin\Block;

use Drupal\Core\Cache\Cache;
use Drupal\custom_book_block\Plugin\Block\CustomBookNavigationBlock;

/**
 * Provides a Yale book navigation block with proper cache contexts.
 *
 * @Block(
 *   id = "yale_book_navigation",
 *   admin_label = @Translation("Yale Book Navigation"),
 *   category = @Translation("Menus")
 * )
 */
class YaleBookNavigationBlock extends CustomBookNavigationBlock {

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    $contexts = parent::getCacheContexts();

    // Add user-specific contexts since ys_book module shows all items
    // but flags restricted ones with is_cas based on actual permissions.
    $contexts = Cache::mergeContexts($contexts, [
      'user.permissions',
      'user.roles',
    ]);

    return $contexts;
  }

}
