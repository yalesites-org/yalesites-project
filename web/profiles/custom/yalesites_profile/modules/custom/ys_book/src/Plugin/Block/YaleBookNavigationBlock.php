<?php

namespace Drupal\ys_book\Plugin\Block;

use Drupal\custom_book_block\Plugin\Block\CustomBookNavigationBlock;

/**
 * Provides a Yale book navigation block.
 *
 * This extends CustomBookNavigationBlock which already includes the necessary
 * user.permissions and user.roles cache contexts. Additional service-level
 * caching in YSExpandBookManager ensures proper per-user cache handling.
 *
 * @Block(
 *   id = "yale_book_navigation",
 *   admin_label = @Translation("Yale Book Navigation"),
 *   category = @Translation("Menus")
 * )
 */
class YaleBookNavigationBlock extends CustomBookNavigationBlock {

  // No additional overrides needed - parent class handles cache contexts
  // and YSExpandBookManager service handles user-specific book tree caching.
}
