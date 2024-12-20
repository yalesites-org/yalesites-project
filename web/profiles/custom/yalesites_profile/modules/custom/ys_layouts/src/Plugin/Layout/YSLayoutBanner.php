<?php

namespace Drupal\ys_layouts\Plugin\Layout;

use Drupal\Core\Layout\LayoutDefault;

/**
 * Banner layout class.
 */
class YSLayoutBanner extends LayoutDefault {

  /**
   * {@inheritdoc}
   */
  public function build(array $regions) {
    /*
     * Removes empty div from the banner section based on moderation control.
     * @see ys_layouts/layouts/banner/layout--banner.html.twig
     * */
    $build = parent::build($regions);
    $build['#show_region_content'] = TRUE;
    $content = $build['content'];

    // Always show banner if there is other content in there as well.
    if (count($content) != 1) {
      return $build;
    }

    // Test to see if moderation control is here, and if we show controls.
    foreach ($content as $block) {
      if (str_contains($block['#plugin_id'], 'moderation_control')) {
        $route_match = \Drupal::routeMatch();
        $entity = $route_match->getParameter('node') ?? $route_match->getParameter('entity');
        if (
          $entity &&
          $entity->hasField('moderation_state') &&
          $entity->get('moderation_state')->value == 'published' &&
          $block['#in_preview'] != TRUE
          ) {
          $build['#show_region_content'] = FALSE;
        }
      }
    }

    return $build;
  }

}
