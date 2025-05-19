<?php

namespace Drupal\ys_foo_bar\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'Foo Bar' block.
 *
 * @Block(
 *   id = "foo_bar_block",
 *   admin_label = @Translation("Foo Bar"),
 *   category = @Translation("Featured Content")
 * )
 */
class FooBarBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#markup' => 'foo bar',
    ];
  }

} 