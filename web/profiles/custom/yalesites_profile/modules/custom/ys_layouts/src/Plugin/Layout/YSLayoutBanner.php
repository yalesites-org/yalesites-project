<?php

namespace Drupal\ys_layouts\Plugin\Layout;

use Drupal\Core\Form\FormStateInterface;

/**
 * Banner layout class.
 *
 * Extends YSLayoutOptions so Banner sections get the same "Component theme"
 * picker as the other themed Section layouts. Banner has a single region, so
 * the inherited divider checkbox is removed.
 */
class YSLayoutBanner extends YSLayoutOptions {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    unset($form['divider']);

    return $form;
  }

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
    $content = $build['content'] ?? NULL;

    if (!$content) {
      return $build;
    }

    // Always show banner if there is other content in there as well.
    if (count($content) != 1) {
      return $build;
    }

    // Test to see if moderation control is here, and if we show controls.
    foreach ($content as $block) {
      if (str_contains($block['#plugin_id'] ?? '', 'moderation_control')) {
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
