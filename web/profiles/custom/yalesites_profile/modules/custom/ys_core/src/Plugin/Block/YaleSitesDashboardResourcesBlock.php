<?php

namespace Drupal\ys_core\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Contains helpful information and links for site editors and admins.
 *
 * @Block(
 *   id = "dashboard_resources_block",
 *   admin_label = @Translation("Dashboard Resources Block"),
 *   category = @Translation("YaleSites Core"),
 * )
 */
class YaleSitesDashboardResourcesBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#theme' => 'ys_dashboard_resources',
    ];
  }

}
