<?php

namespace Drupal\ys_core;

use Drupal\Core\Render\Element\RenderCallbackInterface;
use Drupal\Core\Render\Markup;

/**
 * Provides a trusted callback to alter the nodeaccess help block.
 *
 * @see ys_core_block_view_alter()
 */
class NodeAccessHelpBlockOverride implements RenderCallbackInterface {

  /**
   * Sets new help text for nodeaccess help message.
   */
  public static function preRender($build) {
    unset($build['content'][0]['#markup']);
    $build['content']['#markup'] = Markup::create('Set the public visibility of the page here.');
    return $build;
  }

}
