<?php

namespace Drupal\layout_builder_block_clone\Element;

use Drupal\Core\Render\Element;
use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Class CloneLayoutBuilderBlock
 *
 * @package Drupal\layout_builder_block_clone\Element
 */
class CloneLayoutBuilderBlock implements TrustedCallbackInterface {

  /**
   * Add 'Clone' to metadata contextual link.
   *
   * @param $element
   *
   * @return mixed
   */
  public static function preRenderLayoutBuilder($element) {
    $layout_builder = $element['layout_builder'];

    foreach (Element::children($layout_builder) as $section) {
      if (isset($layout_builder[$section]['layout-builder__section'])) {
        foreach (Element::children($layout_builder[$section]['layout-builder__section']) as $region) {
          foreach (Element::children($layout_builder[$section]['layout-builder__section'][$region]) as $content) {
            if(isset($layout_builder[$section]['layout-builder__section'][$region][$content]['#contextual_links']['layout_builder_block']['metadata'])) {
              $element['layout_builder'][$section]['layout-builder__section'][$region][$content]['#contextual_links']['layout_builder_block']['metadata'] = [
                'operations' => 'move:update:remove:clone',
              ];
            }
          }
        }
      }
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['preRenderLayoutBuilder'];
  }

}
